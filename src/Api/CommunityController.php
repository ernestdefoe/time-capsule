<?php

namespace ErnestDefoe\TimeCapsule\Api;

use Carbon\Carbon;
use ErnestDefoe\TimeCapsule\Capsule;
use ErnestDefoe\TimeCapsule\Community;
use ErnestDefoe\TimeCapsule\Opener;
use ErnestDefoe\TimeCapsule\Photos;
use ErnestDefoe\TimeCapsule\Present;
use ErnestDefoe\TimeCapsule\Settings;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET    /api/time-capsule/community/{id}   the capsule; once open, its letters (?offset=)
 * POST   /api/time-capsule/community        start one {title, description, opensAt, post, notifyEveryone}
 * PATCH  /api/time-capsule/community/{id}   change it while it's sealed
 * DELETE /api/time-capsule/community/{id}   delete it and every letter in it
 *
 * One community capsule is sealed at a time, so the page always has one countdown.
 */
class CommunityController implements RequestHandlerInterface
{
    private const PAGE = 30;

    public function __construct(
        private readonly Present $present,
        private readonly Opener $opener,
        private readonly Photos $photos,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $id = (int) Arr::get($request->getQueryParams(), 'id');

        return match ($request->getMethod()) {
            'GET' => $this->show($request, $actor, $id),
            'DELETE' => $this->delete($actor, $id),
            default => $this->save($request, $actor, $id),
        };
    }

    private function show(ServerRequestInterface $request, User $actor, int $id): ResponseInterface
    {
        $actor->assertCan(Settings::VIEW);
        $community = Community::query()->find($id);
        if (! $community) {
            return Errors::make(404, 'not_found');
        }
        $this->opener->openCommunity($community);

        $base = Capsule::query()->where('community_id', $community->id);
        $counts = [
            'letters' => $base->clone()->count(),
            'members' => $base->clone()->distinct()->count('user_id'),
            'mine' => $actor->isGuest() ? 0 : $base->clone()->where('user_id', $actor->id)->count(),
        ];

        $offset = max(0, (int) Arr::get($request->getQueryParams(), 'offset'));
        // Sealed letters are cards for their own writers only; nobody reads the rest until the day.
        $letters = $community->isOpen()
            ? $base->clone()->with(['user', 'photos'])->orderBy('sealed_at')->orderBy('id')->offset($offset)->limit(self::PAGE)->get()
            : ($actor->isGuest() ? collect() : $base->clone()->where('user_id', $actor->id)->orderBy('sealed_at')->get());

        return new JsonResponse([
            'community' => $this->present->community($community, $actor, $counts),
            'letters' => $letters->map(fn (Capsule $c) => $this->present->capsule($c, $actor, true))->values(),
            'more' => $community->isOpen() && $counts['letters'] > $offset + self::PAGE,
        ]);
    }

    private function save(ServerRequestInterface $request, User $actor, int $id): ResponseInterface
    {
        $actor->assertCan(Settings::COMMUNITY);

        $community = $id ? Community::query()->whereNull('opened_at')->find($id) : new Community();
        if (! $community) {
            return Errors::make(404, 'not_found');
        }
        if (! $id && Community::query()->whereNull('opened_at')->exists()) {
            return Errors::make(422, 'time_capsule_community_exists');
        }

        $body = (array) $request->getParsedBody();
        $title = Input::text($body, 'title');
        $description = Input::text($body, 'description');
        $opensAt = Input::opensAt($body, $this->settings, 1);
        if (mb_strlen($title) < 3 || mb_strlen($title) > 100 || mb_strlen($description) > 2000 || ! $opensAt) {
            return Errors::make(422, 'time_capsule_bad_community');
        }
        // Members wrote their letters for that day: once there are any, the day can only move later.
        if ($id && $opensAt < $community->opens_at && Capsule::query()->where('community_id', $community->id)->exists()) {
            return Errors::make(422, 'time_capsule_community_earlier');
        }

        if (! $id) {
            $community->user_id = $actor->id;
            $community->created_at = Carbon::now();
        }
        $community->title = $title;
        $community->description = $description !== '' ? $description : null;
        $community->opens_at = $opensAt;
        $community->post_to_discussion = Input::flag($body, 'post');
        $community->notify_everyone = Input::flag($body, 'notifyEveryone');
        $community->save();

        Capsule::query()->where('community_id', $community->id)->whereNull('opened_at')->update(['opens_at' => $opensAt]);

        return new JsonResponse(['community' => $this->present->community($community, $actor)], $id ? 200 : 201);
    }

    private function delete(User $actor, int $id): ResponseInterface
    {
        $actor->assertCan(Settings::COMMUNITY);
        $community = Community::query()->find($id);
        if (! $community) {
            return Errors::make(404, 'not_found');
        }

        $this->photos->deleteCapsules(Capsule::query()->where('community_id', $community->id)->pluck('id'));
        $community->delete();

        return new EmptyResponse(204);
    }
}
