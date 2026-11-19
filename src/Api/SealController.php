<?php

namespace ErnestDefoe\TimeCapsule\Api;

use Carbon\Carbon;
use ErnestDefoe\TimeCapsule\Capsule;
use ErnestDefoe\TimeCapsule\Community;
use ErnestDefoe\TimeCapsule\KeyBusy;
use ErnestDefoe\TimeCapsule\KeyMissing;
use ErnestDefoe\TimeCapsule\Locks;
use ErnestDefoe\TimeCapsule\Photo;
use ErnestDefoe\TimeCapsule\Photos;
use ErnestDefoe\TimeCapsule\Present;
use ErnestDefoe\TimeCapsule\Settings;
use ErnestDefoe\TimeCapsule\Vault;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
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
 * re-encoded and encrypted onto the extension's private disk. The answer is the sealed card, not
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
        private readonly Locks $locks,
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

        $when = $this->when($body);
        $uploads = $this->uploads($request);
        if ($when instanceof ResponseInterface) {
            return $when;
        }
        if ($uploads instanceof ResponseInterface) {
            return $uploads;
        }
        [$community, $opensAt] = $when;

        // One letter at a time per member, so two sent at once can't both slip under the limits.
        $lock = $this->locks->lock('seal:'.$actor->id, 30);
        if (! $lock->get()) {
            return Errors::make(429, 'time_capsule_slow_down');
        }

        try {
            $capsule = $this->letter($actor->id, $body, $community, $opensAt);
            $capsule->envelope = $envelope !== '' ? $envelope : null;
            $capsule->words = Input::words($text);

            return $this->limits($actor->id) ?? $this->seal($capsule, $text, $uploads, $actor);
        } finally {
            $lock->release();
        }
    }

    /**
     * Encrypts the letter and its photos and saves them.
     *
     * @param list<UploadedFileInterface> $uploads
     */
    private function seal(Capsule $capsule, string $text, array $uploads, User $actor): ResponseInterface
    {
        try {
            $capsule->sealed_body = $this->vault->seal($text, $capsule->context());
        } catch (KeyMissing) {
            return Errors::make(503, 'time_capsule_key_missing');
        } catch (KeyBusy) {
            return Errors::make(503, 'time_capsule_busy');
        }

        $stored = $this->photos->storeAll($uploads, $actor->id);
        if ($stored === null) {
            return Errors::make(422, 'time_capsule_bad_photo');
        }
        $capsule->photo_count = count($stored);

        $this->save($capsule, $stored);
        $capsule->setRelation('user', $actor);

        return new JsonResponse(['capsule' => $this->present->capsule($capsule, $actor)], 201);
    }

    /**
     * A new letter's when and who-reads-it, from the form.
     *
     * @param array<string, mixed> $body
     */
    private function letter(int $userId, array $body, ?Community $community, Carbon $opensAt): Capsule
    {
        $visibility = $community || ($body['visibility'] ?? null) === Capsule::PUBLIC ? Capsule::PUBLIC : Capsule::PRIVATE;

        $capsule = new Capsule();
        $capsule->user_id = $userId;
        $capsule->community_id = $community?->id;
        $capsule->opens_at = $opensAt;
        $capsule->sealed_at = Carbon::now();
        $capsule->visibility = $visibility;
        $capsule->author_can_read = Input::flag($body, 'peek');
        $capsule->post_to_discussion = ! $community && $visibility === Capsule::PUBLIC && Input::flag($body, 'post');

        return $capsule;
    }

    /**
     * The community capsule the letter goes in (its day), or the writer's own day.
     *
     * @param array<string, mixed> $body
     * @return array{Community|null, Carbon}|ResponseInterface
     */
    private function when(array $body): array|ResponseInterface
    {
        if (! empty($body['community'])) {
            $community = Community::query()->whereNull('opened_at')->where('opens_at', '>', Carbon::now())->find((int) $body['community']);

            return $community ? [$community, $community->opens_at] : Errors::make(422, 'time_capsule_community_closed');
        }

        $opensAt = Input::opensAt($body, $this->settings);

        return $opensAt ? [null, $opensAt] : Errors::make(422, 'time_capsule_bad_date');
    }

    /**
     * The photos sent (Photos::storeAll() checks each before storing any).
     *
     * @return list<UploadedFileInterface>|ResponseInterface
     */
    private function uploads(ServerRequestInterface $request): array|ResponseInterface
    {
        $uploads = array_values(array_filter(
            (array) ($request->getUploadedFiles()['photos'] ?? []),
            fn ($file) => $file instanceof UploadedFileInterface && $file->getError() !== UPLOAD_ERR_NO_FILE
        ));
        if (count($uploads) > Settings::MAX_PHOTOS) {
            return Errors::make(422, 'time_capsule_too_many_photos');
        }

        return $uploads;
    }

    /** The rate limits: letters sealed today, and sealed at once. */
    private function limits(int $userId): ?ResponseInterface
    {
        $mine = Capsule::query()->where('user_id', $userId);
        if ($mine->clone()->where('sealed_at', '>', Carbon::now()->subDay())->count() >= Settings::MAX_PER_DAY) {
            return Errors::make(429, 'time_capsule_slow_down');
        }
        if ($mine->clone()->whereNull('opened_at')->count() >= Settings::MAX_SEALED) {
            return Errors::make(422, 'time_capsule_too_many');
        }

        return null;
    }

    /**
     * The letter and its photo rows in one transaction; the files go again if it fails.
     *
     * @param list<array{file: string, width: int, height: int}> $stored
     */
    private function save(Capsule $capsule, array $stored): void
    {
        try {
            $this->db->transaction(function () use ($capsule, $stored) {
                $capsule->save();
                $rows = [];
                foreach ($stored as $i => $file) {
                    $rows[] = ['capsule_id' => $capsule->id, 'position' => $i] + $file;
                }
                if ($rows !== []) {
                    Photo::query()->insert($rows);
                }
            });
        } catch (Throwable $e) {
            $this->photos->forget(array_column($stored, 'file'));

            throw $e;
        }
    }
}
