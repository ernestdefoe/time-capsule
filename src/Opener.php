<?php

namespace ErnestDefoe\TimeCapsule;

use Carbon\Carbon;
use ErnestDefoe\TimeCapsule\Notification\CapsuleOpenedBlueprint;
use ErnestDefoe\TimeCapsule\Notification\CommunityOpenedBlueprint;
use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\Notification;
use Flarum\Notification\NotificationSyncer;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Opens letters on their day. Every step is idempotent, so the scheduler can
 * run it every minute, overlap a page request that opens the same letter, or
 * crash halfway and simply pick up again:
 *
 *  - opening is a conditional UPDATE (`opened_at IS NULL`): exactly one
 *    caller wins it, and only the winner goes on;
 *  - the writer's notification is a sync of one blueprint per letter, which
 *    never notifies twice, and `notified_at` marks it done;
 *  - a discussion is claimed the same way (`posted_at IS NULL`) and released
 *    for a retry (three tries) if posting throws;
 *  - a community's notifications go out in chunks by user id behind a
 *    cursor, skipping anyone who already has it.
 */
class Opener
{
    private const BATCH = 200;
    private const CHUNK = 500;
    private const CHUNKS_PER_RUN = 20;
    private const MAX_POST_ATTEMPTS = 3;

    public function __construct(
        private readonly Vault $vault,
        private readonly NotificationSyncer $notifications,
        private readonly JsonApi $api,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ExtensionManager $extensions,
        private readonly TranslatorInterface $translator,
        private readonly UrlGenerator $url,
        private readonly LoggerInterface $log,
    ) {
    }

    /** The scheduler's run. Returns how many letters and community capsules it opened. */
    public function run(): int
    {
        $opened = 0;
        $now = Carbon::now();

        Capsule::query()->whereNull('opened_at')->whereNull('community_id')->where('opens_at', '<=', $now)
            ->orderBy('opens_at')->limit(self::BATCH)->get()
            ->each(function (Capsule $capsule) use (&$opened) {
                $opened += (int) $this->open($capsule);
            });

        Community::query()->whereNull('opened_at')->where('opens_at', '<=', $now)->get()
            ->each(function (Community $community) use (&$opened) {
                $opened += (int) $this->openCommunity($community);
            });

        $this->followUp();

        return $opened;
    }

    /** Opens one letter if its day has come. True only for the caller that opened it. */
    public function open(Capsule $capsule): bool
    {
        if ($capsule->isOpen() || $capsule->community_id !== null || $capsule->opens_at->isFuture()) {
            return false;
        }

        $now = Carbon::now();
        $won = Capsule::query()->whereKey($capsule->id)->whereNull('opened_at')->update(['opened_at' => $now]) === 1;
        $capsule->opened_at = $capsule->opened_at ?? Capsule::query()->whereKey($capsule->id)->value('opened_at');
        if ($won) {
            $capsule->opened_at = $now;
            $this->notifyWriter($capsule);
        }

        return $won;
    }

    /** Opens a community capsule and every letter in it at once. */
    public function openCommunity(Community $community): bool
    {
        if ($community->isOpen() || $community->opens_at->isFuture()) {
            return false;
        }

        $now = Carbon::now();
        $won = Community::query()->whereKey($community->id)->whereNull('opened_at')->update(['opened_at' => $now]) === 1;
        if ($won) {
            $community->opened_at = $now;
            Capsule::query()->where('community_id', $community->id)->whereNull('opened_at')->update(['opened_at' => $now]);
        }

        return $won;
    }

    /** Notifications and discussions still owed by letters that opened. */
    public function followUp(): void
    {
        Capsule::query()->whereNotNull('opened_at')->whereNull('community_id')->whereNull('notified_at')
            ->with('user')->limit(self::BATCH)->get()
            ->each(fn (Capsule $capsule) => $this->notifyWriter($capsule));

        Capsule::query()->whereNotNull('opened_at')->whereNull('community_id')
            ->where('post_to_discussion', true)->where('visibility', Capsule::PUBLIC)
            ->whereNull('posted_at')->where('post_attempts', '<', self::MAX_POST_ATTEMPTS)
            ->with('user')->limit(20)->get()
            ->each(fn (Capsule $capsule) => $this->postLetter($capsule));

        Community::query()->whereNotNull('opened_at')->whereNull('notified_at')->get()
            ->each(fn (Community $community) => $this->notifyCommunity($community));

        Community::query()->whereNotNull('opened_at')->where('post_to_discussion', true)
            ->whereNull('posted_at')->where('post_attempts', '<', self::MAX_POST_ATTEMPTS)
            ->with('user')->get()
            ->each(fn (Community $community) => $this->postCommunity($community));
    }

    private function notifyWriter(Capsule $capsule): void
    {
        if ($capsule->user) {
            // One blueprint per letter: a second sync of it notifies nobody new.
            $this->notifications->sync(new CapsuleOpenedBlueprint($capsule), [$capsule->user]);
        }
        Capsule::query()->whereKey($capsule->id)->update(['notified_at' => Carbon::now()]);
    }

    private function notifyCommunity(Community $community): void
    {
        $subject = $community->user ?? User::query()->whereHas('groups', fn ($q) => $q->whereKey(Group::ADMINISTRATOR_ID))->orderBy('id')->first();
        if (! $subject) {
            Community::query()->whereKey($community->id)->update(['notified_at' => Carbon::now()]);

            return;
        }
        $blueprint = new CommunityOpenedBlueprint($community, $subject);

        for ($i = 0; $i < self::CHUNKS_PER_RUN; $i++) {
            $query = $community->notify_everyone
                ? User::query()->where('is_email_confirmed', true)
                : User::query()->whereIn('id', Capsule::query()->select('user_id')->where('community_id', $community->id));
            $users = $query->where('id', '>', $community->notify_cursor)->orderBy('id')->limit(self::CHUNK)->get();

            if ($users->isEmpty()) {
                $community->notified_at = Carbon::now();
                $community->save();

                return;
            }

            $have = Notification::query()->matchingBlueprint($blueprint)->whereIn('user_id', $users->modelKeys())->pluck('user_id')->all();
            $new = $users->reject(fn (User $user) => in_array($user->id, $have))->values()->all();
            if ($new) {
                foreach (NotificationSyncer::getNotificationDrivers() as $driver) {
                    $driver->send($blueprint, $new);
                }
            }

            $community->notify_cursor = (int) $users->last()->id;
            $community->save();
        }
    }

    private function postLetter(Capsule $capsule): void
    {
        if (! $this->claimPost(Capsule::class, $capsule->id)) {
            return;
        }

        $author = $capsule->user;
        $tags = $author ? $this->tagsFor($author) : false;
        if (! $author || $tags === false || ! $author->can('startDiscussion')) {
            // Can't post as them any more (suspended, the tag closed): the letter stays on the capsule page.
            return;
        }

        $body = $this->vault->open($capsule->sealed_body, $capsule->context());
        if ($body === null) {
            return;
        }

        $locale = $this->translator;
        $title = $capsule->envelope ?: (string) $locale->trans('ernestdefoe-time-capsule.lib.discussion.title', ['date' => $this->date($capsule->sealed_at)]);
        $content = implode("\n\n", array_filter([
            '*'.$locale->trans('ernestdefoe-time-capsule.lib.discussion.written', ['date' => $this->date($capsule->sealed_at), 'span' => $this->span($capsule)]).'*',
            trim($body),
            '['.$locale->trans($capsule->photo_count ? 'ernestdefoe-time-capsule.lib.discussion.link_photos' : 'ernestdefoe-time-capsule.lib.discussion.link').']('.$this->url->to('forum')->route('time-capsule.letter', ['id' => $capsule->id]).')',
        ]));

        $this->post($capsule, $author, $title, $content, $tags);
    }

    private function postCommunity(Community $community): void
    {
        if (! $this->claimPost(Community::class, $community->id)) {
            return;
        }

        $author = $community->user;
        $tags = $author ? $this->tagsFor($author) : false;
        if (! $author || $tags === false || ! $author->can('startDiscussion')) {
            return;
        }

        $letters = Capsule::query()->where('community_id', $community->id)->count();
        $members = Capsule::query()->where('community_id', $community->id)->distinct()->count('user_id');
        $content = implode("\n\n", array_filter([
            '*'.$this->translator->trans('ernestdefoe-time-capsule.lib.discussion.community_sealed', ['date' => $this->date($community->created_at)]).'*',
            trim((string) $community->description),
            (string) $this->translator->trans('ernestdefoe-time-capsule.lib.discussion.community_count', ['letters' => $letters, 'members' => $members]),
            '['.$this->translator->trans('ernestdefoe-time-capsule.lib.discussion.community_link').']('.$this->url->to('forum')->route('time-capsule.community', ['id' => $community->id]).')',
        ]));

        $this->post($community, $author, $community->title, $content, $tags);
    }

    /** @param list<int>|null $tags */
    private function post(Capsule|Community $model, User $author, string $title, string $content, ?array $tags): void
    {
        $data = ['type' => 'discussions', 'attributes' => ['title' => mb_substr($title, 0, 80), 'content' => $content]];
        if ($tags) {
            $data['relationships']['tags']['data'] = array_map(fn (int $id) => ['type' => 'tags', 'id' => (string) $id], $tags);
        }

        try {
            /** @var Discussion $discussion */
            $discussion = $this->api->forResource(DiscussionResource::class)
                ->forEndpoint('create')
                ->process(['data' => $data], [], ['actor' => $author]);
            $model::query()->whereKey($model->id)->update(['discussion_id' => $discussion->id]);
        } catch (Throwable $e) {
            $this->log->warning('[time-capsule] '.class_basename($model).' '.$model->id.' could not be posted: '.$e->getMessage());
            // Released for the next run, up to MAX_POST_ATTEMPTS.
            $model::query()->whereKey($model->id)->update(['posted_at' => null, 'post_attempts' => $model->post_attempts + 1]);
        }
    }

    /** @param class-string<Capsule|Community> $class */
    private function claimPost(string $class, int $id): bool
    {
        return $class::query()->whereKey($id)->whereNull('posted_at')->update(['posted_at' => Carbon::now()]) === 1;
    }

    /**
     * The admin's tag for opened letters, if the author may start a discussion there.
     *
     * @return list<int>|null|false null: no tags needed; false: can't post.
     */
    private function tagsFor(User $author): array|null|false
    {
        if (! $this->extensions->isEnabled('flarum-tags') || ! class_exists(\Flarum\Tags\Tag::class)) {
            return null;
        }

        $id = (int) $this->settings->get(Settings::TAG);
        $tag = $id ? \Flarum\Tags\Tag::whereVisibleTo($author)->find($id) : null;
        if ($tag) {
            // The API's process() skips the tag's own permission check: ask it here.
            return $author->can('startDiscussion', $tag) ? [$id] : false;
        }

        return $author->hasPermission('bypassTagCounts') ? null : false;
    }

    private function date(Carbon $date): string
    {
        return $date->locale((string) ($this->settings->get('default_locale') ?: 'en'))->isoFormat('LL');
    }

    private function span(Capsule $capsule): string
    {
        return $capsule->sealed_at->locale((string) ($this->settings->get('default_locale') ?: 'en'))
            ->diffForHumans($capsule->opened_at ?? Carbon::now(), ['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 2]);
    }
}
