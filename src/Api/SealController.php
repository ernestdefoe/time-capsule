<?php

namespace ErnestDefoe\TimeCapsule\Api;

use Carbon\Carbon;
use ErnestDefoe\TimeCapsule\Capsule;
use ErnestDefoe\TimeCapsule\Community;
use ErnestDefoe\TimeCapsule\Photo;
use ErnestDefoe\TimeCapsule\Photos;
use ErnestDefoe\TimeCapsule\Present;
use ErnestDefoe\TimeCapsule\Settings;
use ErnestDefoe\TimeCapsule\Vault;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * POST /api/time-capsule   (multipart when there are photos)
 *   body, envelope?, opensAt (ISO 8601 with offset), visibility (private|public),
 *   post?, peek?, community? (a community capsule's id), photos[]?
 *
 * Seals a letter: encrypted before it reaches the database, photos
 * re-encoded and encrypted into storage/. The answer is the sealed card, not
 * the letter.
 */
class SealController implements RequestHandlerInterface
{
    public function __construct(
        private readonly Vault $vault,
        private readonly Photos $photos,
        private readonly Present $present,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ConnectionInterface $db,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan(Settings::CREATE);

        $body = (array) $request->getParsedBody();
        $text = Input::text($body, 'body');
        $envelope = Input::text($body, 'envelope');
        if ($text === '' || mb_strlen($text) > Settings::MAX_LENGTH || mb_strlen($envelope) > Settings::MAX_ENVELOPE) {
            return Errors::make(422, 'time_capsule_bad_letter');
        }

        $community = null;
        if (! empty($body['community'])) {
            $community = Community::query()->whereNull('opened_at')->where('opens_at', '>', Carbon::now())->find((int) $body['community']);
            if (! $community) {
                return Errors::make(422, 'time_capsule_community_closed');
            }
            $opensAt = $community->opens_at;
        } else {
            $opensAt = Input::opensAt($body, $this->settings);
            if (! $opensAt) {
                return Errors::make(422, 'time_capsule_bad_date');
            }
        }

        $uploads = array_values(array_filter(
            (array) ($request->getUploadedFiles()['photos'] ?? []),
            fn ($file) => $file instanceof UploadedFileInterface && $file->getError() !== UPLOAD_ERR_NO_FILE
        ));
        if (count($uploads) > Settings::MAX_PHOTOS) {
            return Errors::make(422, 'time_capsule_too_many_photos');
        }

        $mine = Capsule::query()->where('user_id', $actor->id);
        if ($mine->clone()->where('sealed_at', '>', Carbon::now()->subDay())->count() >= Settings::MAX_PER_DAY) {
            return Errors::make(429, 'time_capsule_slow_down');
        }
        if ($mine->clone()->whereNull('opened_at')->count() >= Settings::MAX_SEALED) {
            return Errors::make(422, 'time_capsule_too_many');
        }

        $stored = [];
        foreach ($uploads as $upload) {
            $photo = $this->photos->store($upload, $actor->id);
            if (! $photo) {
                $this->photos->forget(array_column($stored, 'file'));

                return Errors::make(422, 'time_capsule_bad_photo');
            }
            $stored[] = $photo;
        }

        $visibility = $community || ($body['visibility'] ?? null) === Capsule::PUBLIC ? Capsule::PUBLIC : Capsule::PRIVATE;

        $capsule = new Capsule();
        $capsule->user_id = $actor->id;
        $capsule->community_id = $community?->id;
        $capsule->envelope = $envelope !== '' ? $envelope : null;
        $capsule->sealed_body = $this->vault->seal($text, 'capsule:'.$actor->id);
        $capsule->words = Input::words($text);
        $capsule->photo_count = count($stored);
        $capsule->opens_at = $opensAt;
        $capsule->sealed_at = Carbon::now();
        $capsule->visibility = $visibility;
        $capsule->author_can_read = Input::flag($body, 'peek');
        $capsule->post_to_discussion = ! $community && $visibility === Capsule::PUBLIC && Input::flag($body, 'post');

        try {
            $this->db->transaction(function () use ($capsule, $stored) {
                $capsule->save();
                foreach ($stored as $i => $file) {
                    $photo = new Photo();
                    $photo->capsule_id = $capsule->id;
                    $photo->file = $file['file'];
                    $photo->width = $file['width'];
                    $photo->height = $file['height'];
                    $photo->position = $i;
                    $photo->save();
                }
            });
        } catch (Throwable $e) {
            $this->photos->forget(array_column($stored, 'file'));

            throw $e;
        }

        $capsule->setRelation('user', $actor);

        return new JsonResponse(['capsule' => $this->present->capsule($capsule, $actor)], 201);
    }
}
