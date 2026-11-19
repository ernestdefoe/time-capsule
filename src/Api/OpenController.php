<?php

namespace ErnestDefoe\TimeCapsule\Api;

use ErnestDefoe\TimeCapsule\Opener;
use ErnestDefoe\TimeCapsule\Settings;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/time-capsule/open: sent by the page when it shows a letter or community capsule whose
 * day has come but that hasn't opened yet. Opens the viewer's own due letters and any due community
 * capsule (Opener::openFor(), idempotent), so nobody waits for the next scheduler run, and forums
 * without a running scheduler still open letters. Never anyone else's letters.
 */
class OpenController implements RequestHandlerInterface
{
    public function __construct(private readonly Opener $opener)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);

        return new JsonResponse(['opened' => $this->opener->openFor($actor)]);
    }
}
