<?php

namespace ErnestDefoe\TimeCapsule\Api;

use Carbon\Carbon;
use ErnestDefoe\TimeCapsule\Capsule;
use ErnestDefoe\TimeCapsule\Community;
use ErnestDefoe\TimeCapsule\Opener;
use ErnestDefoe\TimeCapsule\Present;
use ErnestDefoe\TimeCapsule\Settings;
use Flarum\Extension\ExtensionManager;
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
 * capsules, and forum-wide counts. A letter due but not yet opened (no
 * scheduler run since) is opened here, through the same idempotent Opener.
 */
class PageController implements RequestHandlerInterface
{
    private const MINE = 200;
    private const RECENT = 12;

    public function __construct(
        private readonly Present $present,
        private readonly Opener $opener,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ExtensionManager $extensions,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);

        $community = Community::query()->whereNull('opened_at')->orderBy('opens_at')->first();
        if ($community && $this->opener->openCommunity($community)) {
            $community = null;
        }

        $mine = $actor->isGuest() ? new Collection() : Capsule::query()->where('user_id', $actor->id)
            ->with(['photos', 'discussion'])->orderByDesc('sealed_at')->limit(self::MINE)->get();
        $mine->each(function (Capsule $capsule) use ($actor) {
            $capsule->setRelation('user', $actor);
            $this->opener->open($capsule);
        });

        $recent = Capsule::query()->whereNotNull('opened_at')->whereNull('community_id')->where('visibility', Capsule::PUBLIC)
            ->with(['user', 'photos', 'discussion'])->orderByDesc('opened_at')->limit(self::RECENT)->get();

        $opened = Community::query()->whereNotNull('opened_at')->orderByDesc('opened_at')->limit(3)->get();
        $counts = $this->counts($opened->modelKeys(), $community, $actor);

        $stats = Capsule::query()->toBase()->selectRaw(
            'sum(case when opened_at is null then 1 else 0 end) as sealed, sum(case when opened_at is null then 0 else 1 end) as opened, min(case when opened_at is null then opens_at end) as next_open'
        )->first();

        return new JsonResponse([
            'mine' => $mine->map(fn (Capsule $c) => $this->present->capsule($c, $actor))->values(),
            'recent' => $recent->map(fn (Capsule $c) => $this->present->capsule($c, $actor))->values(),
            'community' => $community ? $this->present->community($community, $actor, $counts[$community->id] ?? []) : null,
            'opened' => $opened->map(fn (Community $c) => $this->present->community($c, $actor, $counts[$c->id] ?? []))->values(),
            'stats' => [
                'sealed' => (int) ($stats->sealed ?? 0),
                'opened' => (int) ($stats->opened ?? 0),
                'nextOpen' => $stats?->next_open ? Carbon::parse($stats->next_open, 'UTC')->toIso8601String() : null,
            ],
            'can' => [
                'create' => $actor->can(Settings::CREATE),
                'community' => $actor->can(Settings::COMMUNITY),
                'post' => $this->canPost($actor),
            ],
            'limits' => [
                'maxYears' => Input::maxYears($this->settings),
                'maxPhotos' => Settings::MAX_PHOTOS,
                'maxLength' => Settings::MAX_LENGTH,
                'minHours' => Settings::MIN_HOURS,
                'founded' => (string) $this->settings->get(Settings::FOUNDED),
            ],
        ]);
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

        return ! $this->extensions->isEnabled('flarum-tags') || (int) $this->settings->get(Settings::TAG) > 0 || $actor->hasPermission('bypassTagCounts');
    }
}
