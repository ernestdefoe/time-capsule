<?php

namespace ErnestDefoe\TimeCapsule\Api;

use ErnestDefoe\TimeCapsule\Access;
use ErnestDefoe\TimeCapsule\Capsule;
use ErnestDefoe\TimeCapsule\Photos;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** DELETE /api/time-capsule/{id}: the writer, or a moderator. Gone for good, photos too; never read first. */
class DeleteController implements RequestHandlerInterface
{
    public function __construct(private readonly Photos $photos)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $capsule = Capsule::query()->find((int) Arr::get($request->getQueryParams(), 'id'));

        if (! $capsule || ! Access::canDelete($capsule, $actor)) {
            return Errors::make(404, 'not_found');
        }

        $this->photos->deleteCapsules([$capsule->id]);

        return new EmptyResponse(204);
    }
}
