<?php

namespace ErnestDefoe\TimeCapsule\Api\Action;

use ErnestDefoe\TimeCapsule\Api\Errors;
use ErnestDefoe\TimeCapsule\Api\Input;

use ErnestDefoe\TimeCapsule\Opener;
use ErnestDefoe\TimeCapsule\Settings;
use Laminas\Diactoros\Response\JsonResponse;
use Flarum\Api\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/time-capsule-letters/open: sent by the page when it shows a letter or community capsule whose
 * day has come but that hasn't opened yet. Opens the viewer's own due letters and any due community
 * capsule (Opener::openFor(), idempotent), so nobody waits for the next scheduler run, and forums
 * without a running scheduler still open letters. Never anyone else's letters.
 */
class Open
{
    public function __construct(private readonly Opener $opener)
    {
    }

    public function __invoke(Context $context): ResponseInterface
    {
        $request = $context->request;
        $actor = $context->getActor();

        return new JsonResponse(['opened' => $this->opener->openFor($actor)]);
    }
}
