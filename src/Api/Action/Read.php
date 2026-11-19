<?php

namespace ErnestDefoe\TimeCapsule\Api\Action;

use ErnestDefoe\TimeCapsule\Api\Errors;
use ErnestDefoe\TimeCapsule\Api\Input;

use Carbon\Carbon;
use ErnestDefoe\TimeCapsule\Capsule;
use Laminas\Diactoros\Response\EmptyResponse;
use Flarum\Api\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/time-capsule-letters/{id}/read: the writer has read their opened letter (the envelope opened
 * on their screen), so it isn't new any more. Their own opened letters only; once.
 */
class Read
{
    public function __invoke(Context $context): ResponseInterface
    {
        $actor = $context->getActor();

        // The letter is found through the resource's scope, so it's one this viewer may see at all.
        Capsule::query()->whereKey($context->model->id)->where('user_id', $actor->id)
            ->whereNotNull('opened_at')->whereNull('read_at')->update(['read_at' => Carbon::now()]);

        return new EmptyResponse(204);
    }
}
