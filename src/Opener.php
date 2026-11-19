<?php

namespace ErnestDefoe\TimeCapsule;

use Carbon\Carbon;
use ErnestDefoe\TimeCapsule\Notification\CapsuleOpenedBlueprint;
use ErnestDefoe\TimeCapsule\Notification\CommunityOpenedBlueprint;
use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
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
 *    cursor, each chunk a sync of its own blueprint, so a rerun repeats
 *    nobody;
 *  - letters of a community capsule that has opened are opened on every
 *    run, whatever stopped them opening with it.
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
        private readonly DiscussionTags $tags,
        private readonly TranslatorInterface $translator,
        private readonly UrlGenerator $url,
        private readonly LoggerInterface $log,
        private readonly ConnectionInterface $db,
    ) {
    }

    /** The scheduler's run. Returns how many letters and community capsules it opened. */
    public function run(): int
    {
        $opened = 0;
        $now = Carbon::now();

        Capsule::query()->whereNull('opened_at')->whereNull('community_id')->where('opens_at', '<=', $now)
            ->with('user')->orderBy('opens_at')->limit(self::BATCH)->get()
            ->each(function (Capsule $capsule) use (&$opened) {
                $this->guard('letter '.$capsule->id, function () use ($capsule, &$opened) {
                    $opened += (int) $this->open($capsule);
                });
            });

        Community::query()->whereNull('opened_at')->where('opens_at', '<=', $now)->get()
            ->each(function (Community $community) use (&$opened) {
                $this->guard('community capsule '.$community->id, function () use ($community, &$opened) {
                    $opened += (int) $this->openCommunity($community);
                });
            });

        // A letter sealed into a community capsule in the moment it opened, or left by a run that
        // stopped between the capsule and its letters, opens with the capsule all the same.
        Capsule::query()->whereNull('opened_at')
            ->whereIn('community_id', Community::query()->select('id')->whereNotNull('opened_at'))
            ->update(['opened_at' => $now]);

        $this->followUp();

        return $opened;
    }

    /**
     * What a viewer's page found due (POST /api/time-capsule/open, sent by the page when it shows
     * a letter or capsule whose day has come): their own due letters and any due community
     * capsule. The scheduler opens the same within a minute; this is for the moment between, and
     * for forums whose scheduler isn't running. Page views themselves never write.
     */
    public function openFor(User $actor): int
    {
        $opened = 0;
        Community::query()->whereNull('opened_at')->where('opens_at', '<=', Carbon::now())->get()
            ->each(function (Community $community) use (&$opened) {
                $opened += (int) $this->openCommunity($community);
            });

        if (! $actor->isGuest()) {
            Capsule::query()->where('user_id', $actor->id)->whereNull('opened_at')->whereNull('community_id')
                ->where('opens_at', '<=', Carbon::now())->limit(Settings::MAX_SEALED)->get()
                ->each(function (Capsule $capsule) use ($actor, &$opened) {
                    $capsule->setRelation('user', $actor);
                    $opened += (int) $this->open($capsule);
                });
        }

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
        if (! $won) {
            // Someone else opened it a moment ago: show their time.
            $capsule->opened_at = Capsule::query()->whereKey($capsule->id)->value('opened_at');
        } else {
            $capsule->opened_at = $now;
            // A page's open request opens letters too: a failed notification must not fail it.
            // notified_at stays empty and the scheduler's follow-up tries again.
            $this->guard('letter '.$capsule->id, fn () => $this->notifyWriter($capsule));
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
        $won = $this->db->transaction(function () use ($community, $now) {
            $won = Community::query()->whereKey($community->id)->whereNull('opened_at')->update(['opened_at' => $now]) === 1;
            if ($won) {
                Capsule::query()->where('community_id', $community->id)->whereNull('opened_at')->update(['opened_at' => $now]);
            }

            return $won;
        });
        if ($won) {
            $community->opened_at = $now;
        }

        return $won;
    }

    /** Notifications and discussions still owed by letters that opened. */
    public function followUp(): void
    {
        Capsule::query()->whereNotNull('opened_at')->whereNull('community_id')->whereNull('notified_at')
            ->with('user')->limit(self::BATCH)->get()
            ->each(fn (Capsule $capsule) => $this->guard('letter '.$capsule->id, fn () => $this->notifyWriter($capsule)));

        Capsule::query()->whereNotNull('opened_at')->whereNull('community_id')
            ->where('post_to_discussion', true)->where('visibility', Capsule::PUBLIC)
            ->whereNull('posted_at')->where('post_attempts', '<', self::MAX_POST_ATTEMPTS)
            ->with('user')->limit(20)->get()
            ->each(fn (Capsule $capsule) => $this->guard('letter '.$capsule->id, fn () => $this->postLetter($capsule)));

        Community::query()->whereNotNull('opened_at')->whereNull('notified_at')->get()
            ->each(fn (Community $community) => $this->guard('community capsule '.$community->id, fn () => $this->notifyCommunity($community)));

        Community::query()->whereNotNull('opened_at')->where('post_to_discussion', true)
            ->whereNull('posted_at')->where('post_attempts', '<', self::MAX_POST_ATTEMPTS)
            ->with('user')->get()
            ->each(fn (Community $community) => $this->guard('community capsule '.$community->id, fn () => $this->postCommunity($community)));
    }

    /** One letter that throws must not stop the others, or a community's notifications, in the same run. */
    private function guard(string $what, callable $step): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            $this->log->error('[time-capsule] '.$what.': '.$e->getMessage());
        }
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

        $recipients = $this->recipients($community);
        for ($i = 0; $i < self::CHUNKS_PER_RUN; $i++) {
            $after = $community->notify_cursor;
            $users = $recipients->clone()->where('users.id', '>', $after)->orderBy('users.id')->limit(self::CHUNK)->get();

            if ($users->isEmpty()) {
                $community->notified_at = Carbon::now();
                $community->save();

                return;
            }

            // Its own blueprint per chunk: sync() leaves other chunks alone and never repeats this one.
            $this->notifications->sync(new CommunityOpenedBlueprint($community, $subject, $after), $users->all());

            $community->notify_cursor = (int) $users->last()->id;
            $community->save();
        }
    }

    /**
     * Who hears that a community capsule opened: every confirmed member (or only its writers) who
     * may view time capsules. Groups with the permission are asked once per run, not per member.
     *
     * @return Builder<User>
     */
    private function recipients(Community $community): Builder
    {
        $query = $community->notify_everyone
            ? User::query()->where('is_email_confirmed', true)
            : User::query()->whereIn('id', Capsule::query()->select('user_id')->where('community_id', $community->id));

        $groups = Permission::query()->where('permission', Settings::VIEW)->pluck('group_id')->map(fn ($id) => (int) $id)->all();
        if (array_intersect($groups, [Group::GUEST_ID, Group::MEMBER_ID])) {
            return $query;
        }
        $groups[] = Group::ADMINISTRATOR_ID;

        return $query->whereHas('groups', fn (Builder $q) => $q->whereIn('groups.id', $groups));
    }

    private function postLetter(Capsule $capsule): void
    {
        // Everything that can fail or say no runs BEFORE the claim, so a throw leaves the letter
        // to be tried again, and a no is recorded as done.
        $author = $capsule->user;
        $tags = $author ? $this->tags->forAuthor($author) : false;
        $body = $this->vault->open($capsule->sealed_body, $capsule->context());

        if (! $author || $tags === false || ! $author->can('startDiscussion') || $body === null) {
            // Can't post as them any more (suspended, the tag closed, unreadable): the letter
            // stays on the capsule page, and nobody tries again.
            Capsule::query()->whereKey($capsule->id)->whereNull('posted_at')->update(['posted_at' => Carbon::now()]);

            return;
        }

        $locale = $this->translator;
        $title = $capsule->envelope ?: (string) $locale->trans('ernestdefoe-time-capsule.lib.discussion.title', ['date' => $this->date($capsule->sealed_at)]);
        $content = implode("\n\n", array_filter([
            '*'.$locale->trans('ernestdefoe-time-capsule.lib.discussion.written', ['date' => $this->date($capsule->sealed_at), 'span' => $this->span($capsule)]).'*',
            trim($body),
            '['.$locale->trans($capsule->photo_count ? 'ernestdefoe-time-capsule.lib.discussion.link_photos' : 'ernestdefoe-time-capsule.lib.discussion.link').']('.$this->url->to('forum')->route('time-capsule.letter', ['id' => $capsule->id]).')',
        ]));

        if ($this->claimPost(Capsule::class, $capsule->id)) {
            $this->post($capsule, $author, $title, $content, $tags);
        }
    }

    private function postCommunity(Community $community): void
    {
        $author = $community->user;
        $tags = $author ? $this->tags->forAuthor($author) : false;
        if (! $author || $tags === false || ! $author->can('startDiscussion')) {
            Community::query()->whereKey($community->id)->whereNull('posted_at')->update(['posted_at' => Carbon::now()]);

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

        if ($this->claimPost(Community::class, $community->id)) {
            $this->post($community, $author, $community->title, $content, $tags);
        }
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

    private function date(Carbon $date): string
    {
        return $date->locale($this->translator->getLocale())->isoFormat('LL');
    }

    private function span(Capsule $capsule): string
    {
        return $capsule->sealed_at->locale($this->translator->getLocale())
            ->diffForHumans($capsule->opened_at ?? Carbon::now(), ['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 2]);
    }
}
