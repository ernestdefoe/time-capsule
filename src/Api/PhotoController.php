<?php

namespace ErnestDefoe\TimeCapsule\Api;

use ErnestDefoe\TimeCapsule\Access;
use ErnestDefoe\TimeCapsule\Capsule;
use ErnestDefoe\TimeCapsule\Photo;
use ErnestDefoe\TimeCapsule\Photos;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/time-capsule/{id}/photos/{photo}: one photo, decrypted, only for a
 * viewer who may read its letter (Access). A sealed letter's photos answer
 * 404 like a photo that doesn't exist.
 */
class PhotoController implements RequestHandlerInterface
{
    public function __construct(private readonly Photos $photos)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getQueryParams();
        $capsule = Capsule::query()->find((int) Arr::get($params, 'id'));
        $photo = $capsule ? Photo::query()->where('capsule_id', $capsule->id)->find((int) Arr::get($params, 'photo')) : null;

        if (! $capsule || ! $photo || ! Access::canRead($capsule, $actor)) {
            return Errors::make(404, 'not_found');
        }

        $bytes = $this->photos->read($photo, $capsule->user_id);
        if ($bytes === null) {
            return Errors::make(404, 'not_found');
        }

        $stream = new Stream('php://temp', 'w+');
        $stream->write($bytes);
        $stream->rewind();

        return new Response($stream, 200, [
            'Content-Type' => 'image/webp',
            'Content-Length' => (string) strlen($bytes),
            // Private: a shared cache must never keep a letter's photo.
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
