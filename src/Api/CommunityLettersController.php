<?php

namespace ErnestDefoe\TimeCapsule\Api;

use ErnestDefoe\TimeCapsule\Capsule;
use ErnestDefoe\TimeCapsule\Community;
use ErnestDefoe\TimeCapsule\Present;
use ErnestDefoe\TimeCapsule\Settings;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/time-capsule/community/{id}: a community capsule, its counts and its letters: once it
 * has opened, everyone's (30 at a time, ?offset=); while it's sealed, only the viewer's own, as cards.
 *
 * A plain endpoint, not part of CommunityResource, because it serves letters, and letters are never
 * a JSON:API resource: no include may ever reach one (see extend.php). Starting, changing and
 * deleting the capsule itself go through CommunityResource.
 */
class CommunityLettersController implements RequestHandlerInterface
{
    private const PAGE = 30;

    public function __construct(private readonly Present $present, private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);
        $community = Community::query()->with(['discussion' => Present::visibleDiscussion($actor)])->find((int) Arr::get($request->getQueryParams(), 'id'));
        if (! $community) {
            return Errors::make(404, 'not_found');
        }

        $base = Capsule::query()->where('community_id', $community->id);
        $counts = [
            'letters' => $base->clone()->count(),
            'members' => $base->clone()->distinct()->count('user_id'),
            'mine' => $actor->isGuest() ? 0 : $base->clone()->where('user_id', $actor->id)->count(),
        ];

        $offset = max(0, (int) Arr::get($request->getQueryParams(), 'offset'));
        // Sealed letters are cards for their own writers only; nobody reads the rest until the day.
        $letters = $community->isOpen()
            ? $base->clone()->with(Present::with($actor))->orderBy('sealed_at')->orderBy('id')->offset($offset)->limit(self::PAGE)->get()
            : ($actor->isGuest() ? collect() : $base->clone()->where('user_id', $actor->id)->orderBy('sealed_at')->get());

        return new JsonResponse([
            'community' => $this->present->community($community, $actor, $counts),
            'letters' => $letters->map(fn (Capsule $c) => $this->present->capsule($c, $actor, true))->values(),
            'more' => $community->isOpen() && $counts['letters'] > $offset + self::PAGE,
            'limits' => Input::limits($this->settings),
        ]);
    }
}
