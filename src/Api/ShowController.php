<?php

namespace ErnestDefoe\TimeCapsule\Api;

use Carbon\Carbon;
use ErnestDefoe\TimeCapsule\Access;
use ErnestDefoe\TimeCapsule\Capsule;
use ErnestDefoe\TimeCapsule\Opener;
use ErnestDefoe\TimeCapsule\Present;
use ErnestDefoe\TimeCapsule\Settings;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/time-capsule/{id}: one letter, in full, for a viewer who may read
 * it. The writer of a sealed letter gets its card; everyone else gets 404
 * for a letter they may not read, sealed or private, the same as for one
 * that doesn't exist.
 */
class ShowController implements RequestHandlerInterface
{
    public function __construct(private readonly Present $present, private readonly Opener $opener)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);

        $capsule = Capsule::query()->with(['user', 'photos', 'discussion'])->find((int) Arr::get($request->getQueryParams(), 'id'));
        if ($capsule) {
            $this->opener->open($capsule);
        }

        $mine = $capsule && ! $actor->isGuest() && $capsule->user_id === $actor->id;
        if (! $capsule || (! $mine && ! Access::canRead($capsule, $actor))) {
            return Errors::make(404, 'not_found');
        }

        $row = $this->present->capsule($capsule, $actor, true);

        if (! empty($row['firstRead'])) {
            Capsule::query()->whereKey($capsule->id)->whereNull('read_at')->update(['read_at' => Carbon::now()]);
        }

        return new JsonResponse(['capsule' => $row]);
    }
}
