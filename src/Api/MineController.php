<?php

namespace ErnestDefoe\TimeCapsule\Api;

use ErnestDefoe\TimeCapsule\Capsule;
use ErnestDefoe\TimeCapsule\OpenedLetters;
use ErnestDefoe\TimeCapsule\Present;
use ErnestDefoe\TimeCapsule\Settings;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/time-capsule/mine?offset=: more of your own opened letters, after the first page that
 * GET /api/time-capsule sends. A plain endpoint like every letter endpoint (see extend.php).
 */
class MineController implements RequestHandlerInterface
{
    public function __construct(private readonly OpenedLetters $letters, private readonly Present $present)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan(Settings::VIEW);

        [$letters, $more] = $this->letters->page($actor, (int) Arr::get($request->getQueryParams(), 'offset'));

        return new JsonResponse([
            'letters' => $letters->map(fn (Capsule $c) => $this->present->capsule($c, $actor))->values(),
            'more' => $more,
        ]);
    }
}
