<?php

namespace ErnestDefoe\TimeCapsule\Api;

use Carbon\Carbon;
use ErnestDefoe\TimeCapsule\Capsule;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/time-capsule/{id}/read: the writer has read their opened letter (the envelope opened
 * on their screen), so it isn't new any more. Their own opened letters only; once.
 */
class ReadController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        Capsule::query()->whereKey((int) Arr::get($request->getQueryParams(), 'id'))->where('user_id', $actor->id)
            ->whereNotNull('opened_at')->whereNull('read_at')->update(['read_at' => Carbon::now()]);

        return new EmptyResponse(204);
    }
}
