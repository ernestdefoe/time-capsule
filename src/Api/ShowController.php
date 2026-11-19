<?php

namespace ErnestDefoe\TimeCapsule\Api;

use ErnestDefoe\TimeCapsule\Access;
use ErnestDefoe\TimeCapsule\Capsule;
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
 * that doesn't exist. Only reads (the writer's first read is marked by
 * ReadController, when the envelope has opened on their screen).
 */
class ShowController implements RequestHandlerInterface
{
    public function __construct(private readonly Present $present)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);

        $capsule = Capsule::query()->with(Present::with($actor))->find((int) Arr::get($request->getQueryParams(), 'id'));

        $mine = $capsule && ! $actor->isGuest() && $capsule->user_id === $actor->id;
        if (! $capsule || (! $mine && ! Access::canRead($capsule, $actor))) {
            return Errors::make(404, 'not_found');
        }

        return new JsonResponse(['capsule' => $this->present->capsule($capsule, $actor, true)]);
    }
}
