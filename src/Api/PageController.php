<?php

namespace ErnestDefoe\TimeCapsule\Api;

use Carbon\Carbon;
use ErnestDefoe\TimeCapsule\Capsule;
use ErnestDefoe\TimeCapsule\Community;
use ErnestDefoe\TimeCapsule\DiscussionTags;
use ErnestDefoe\TimeCapsule\OpenedLetters;
use ErnestDefoe\TimeCapsule\Present;
use ErnestDefoe\TimeCapsule\Settings;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Support\Collection;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/time-capsule: everything the /time-capsule page shows in one
 * answer: your letters (sealed ones as cards only), recently opened public
 * letters, the community capsule's countdown, recently opened community
 * capsules, and forum-wide counts. Only reads: a letter whose day has come
 * but isn't open yet shows as due, and the page asks POST /api/time-capsule/open.
 */
class PageController implements RequestHandlerInterface
{
    private const RECENT = 12;

    public function __construct(
        private readonly Present $present,
        private readonly OpenedLetters $mine,
        private readonly SettingsRepositoryInterface $settings,
        private readonly DiscussionTags $tags,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);

        $community = Community::query()->whereNull('opened_at')->orderBy('opens_at')->first();

        // Sealed letters are capped (Settings::MAX_SEALED), so they all come; opened ones come a page
        // at a time (Api\MineController serves the rest).
        $sealed = $actor->isGuest() ? new Collection() : Capsule::query()->where('user_id', $actor->id)->whereNull('opened_at')
            ->with(Present::with($actor, false))->orderBy('opens_at')->limit(Settings::MAX_SEALED)->get();
        $sealed->each(fn (Capsule $capsule) => $capsule->setRelation('user', $actor));
        [$opened, $moreMine] = $actor->isGuest() ? [new Collection(), false] : $this->mine->page($actor, 0);
        $mine = $sealed->concat($opened);

        $recent = Capsule::query()->whereNotNull('opened_at')->whereNull('community_id')->where('visibility', Capsule::PUBLIC)
            ->with(Present::with($actor))->orderByDesc('opened_at')->limit(self::RECENT)->get();

        $communities = Community::query()->whereNotNull('opened_at')->with(['discussion' => Present::visibleDiscussion($actor)])
            ->orderByDesc('opened_at')->limit(3)->get();
        $counts = $this->counts($communities->modelKeys(), $community, $actor);

        return new JsonResponse([
            'mine' => $mine->map(fn (Capsule $c) => $this->present->capsule($c, $actor))->values(),
            'recent' => $recent->map(fn (Capsule $c) => $this->present->capsule($c, $actor))->values(),
            'community' => $community ? $this->present->community($community, $actor, $counts[$community->id] ?? []) : null,
            'moreMine' => $moreMine,
            'opened' => $communities->map(fn (Community $c) => $this->present->community($c, $actor, $counts[$c->id] ?? []))->values(),
            'stats' => $this->stats(),
            'can' => [
                'create' => $actor->can(Settings::CREATE),
                'community' => $actor->can(Settings::COMMUNITY),
                'post' => $this->canPost($actor),
            ],
            'limits' => Input::limits($this->settings),
        ]);
    }

    /**
     * Forum-wide counts and the next letter to open, in one query.
     *
     * @return array{sealed: int, opened: int, nextOpen: string|null}
     */
    private function stats(): array
    {
        // Raw aggregates so the three numbers cost one query instead of three; portable SQL only.
        $stats = Capsule::query()->toBase()->selectRaw(
            'sum(case when opened_at is null then 1 else 0 end) as sealed, sum(case when opened_at is null then 0 else 1 end) as opened, min(case when opened_at is null then opens_at end) as next_open'
        )->first();

        return [
            'sealed' => (int) ($stats->sealed ?? 0),
            'opened' => (int) ($stats->opened ?? 0),
            'nextOpen' => $stats?->next_open ? Carbon::parse($stats->next_open, 'UTC')->toIso8601String() : null,
        ];
    }

    /**
     * Letters, writers and your own letters per community capsule, in one query.
     *
     * @param list<int> $ids
     * @return array<int, array{letters: int, members: int, mine: int}>
     */
    private function counts(array $ids, ?Community $active, User $actor): array
    {
        if ($active) {
            $ids[] = $active->id;
        }
        if (! $ids) {
            return [];
        }

        // One grouped query for every capsule shown (a conditional sum for "yours"), not one per capsule.
        return Capsule::query()->toBase()->whereIn('community_id', $ids)->groupBy('community_id')
            ->selectRaw('community_id, count(*) as letters, count(distinct user_id) as members, sum(case when user_id = ? then 1 else 0 end) as mine', [(int) $actor->id])
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->community_id => ['letters' => (int) $row->letters, 'members' => (int) $row->members, 'mine' => (int) $row->mine]])
            ->all();
    }

    /** Whether "also post it in a discussion" can work for this member (the opener asks again on the day). */
    private function canPost(User $actor): bool
    {
        if (! $actor->can('startDiscussion')) {
            return false;
        }

        return $this->tags->allowsPosting($actor);
    }
}
