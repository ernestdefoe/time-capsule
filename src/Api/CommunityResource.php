<?php

namespace ErnestDefoe\TimeCapsule\Api;

use Carbon\Carbon;
use ErnestDefoe\TimeCapsule\Capsule;
use ErnestDefoe\TimeCapsule\Community;
use ErnestDefoe\TimeCapsule\Locks;
use ErnestDefoe\TimeCapsule\Photo;
use ErnestDefoe\TimeCapsule\Photos;
use ErnestDefoe\TimeCapsule\Settings;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * The `time-capsule-communities` type: starting, changing and deleting the community capsule.
 *
 * POST   /api/time-capsule-communities        {title, description, opensAt, post, notifyEveryone}
 * PATCH  /api/time-capsule-communities/{id}   while it's sealed
 * DELETE /api/time-capsule-communities/{id}   it and every letter in it, unread
 *
 * A capsule carries no letter text, so it can be an ordinary resource. Its letters are served
 * by CommunityLettersController, never here (see extend.php).
 *
 * @extends AbstractDatabaseResource<Community>
 */
class CommunityResource extends AbstractDatabaseResource
{
    public function __construct(
        private readonly Photos $photos,
        private readonly SettingsRepositoryInterface $settings,
        private readonly TranslatorInterface $translator,
        private readonly ConnectionInterface $db,
        private readonly Locks $locks,
    ) {
    }

    public function type(): string
    {
        return 'time-capsule-communities';
    }

    public function model(): string
    {
        return Community::class;
    }

    /** @param Builder<Community> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        if (! $context->getActor()->hasPermission(Settings::VIEW)) {
            $query->whereKey([]);
        }
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Create::make()->authenticated()->can(Settings::COMMUNITY),
            Endpoint\Update::make()->authenticated()->can(Settings::COMMUNITY),
            Endpoint\Delete::make()->authenticated()->can(Settings::COMMUNITY),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('title')->requiredOnCreate()->writable()->minLength(3)->maxLength(100),
            Schema\Str::make('description')->nullable()->writable()->maxLength(2000),
            Schema\DateTime::make('opensAt')->requiredOnCreate()->writable(),
            Schema\Boolean::make('post')->property('post_to_discussion')->writable(),
            Schema\Boolean::make('notifyEveryone')->writable(),
            Schema\DateTime::make('openedAt'),
            Schema\DateTime::make('createdAt'),
        ];
    }

    /** @param Context $context */
    public function creating(object $model, BaseContext $context): ?object
    {
        $model->user_id = $context->getActor()->id;
        $model->created_at = Carbon::now();

        return $model;
    }

    /**
     * A sealed one only; a day between an hour from now and the forum's limit; and once members
     * have written for a day, it can only move later. (One sealed at a time is checked under the
     * lock in saveModel().)
     *
     * @param Community $model
     * @param Context $context
     */
    public function saving(object $model, BaseContext $context): ?object
    {
        if ($model->exists && $model->getOriginal('opened_at') !== null) {
            throw new ValidationException(['opensAt' => $this->trans('community_open')]);
        }

        $model->description = $model->description !== null && trim($model->description) !== '' ? trim($model->description) : null;
        $model->title = trim($model->title);

        if ($model->isDirty('opens_at')) {
            $this->checkDay($model);
        }

        return $model;
    }

    /**
     * A new capsule is saved under a lock (one sealed at a time: two admins starting one at once
     * can't both get one); a changed day moves every letter in it too, in the same transaction.
     *
     * @param Community $model
     */
    protected function saveModel(Model $model, BaseContext $context): void
    {
        if ($model->exists) {
            $this->db->transaction(function () use ($model) {
                $model->save();
                Capsule::query()->where('community_id', $model->id)->whereNull('opened_at')->update(['opens_at' => $model->opens_at]);
            });

            return;
        }

        $saved = $this->locks->lock('community', 10)->get(function () use ($model) {
            if (Community::query()->whereNull('opened_at')->exists()) {
                return false;
            }
            $model->save();

            return true;
        });
        if ($saved !== true) {
            throw new ValidationException(['title' => $this->trans('community_exists')]);
        }
    }

    /**
     * The capsule and every letter in it go in one transaction; the photo files after it commits.
     *
     * @param Community $model
     */
    public function delete(object $model, BaseContext $context): void
    {
        $letters = Capsule::query()->select('id')->where('community_id', $model->id);
        $files = Photo::query()->whereIn('capsule_id', $letters)->pluck('file');

        $this->db->transaction(function () use ($model, $letters) {
            Photo::query()->whereIn('capsule_id', $letters->clone())->delete();
            Capsule::query()->where('community_id', $model->id)->delete();
            $model->delete();
        });

        $this->photos->forget($files);
    }

    private function checkDay(Community $model): void
    {
        $at = Carbon::instance($model->opens_at)->utc();
        if ($at < Carbon::now()->addHour() || $at > Carbon::now()->addYears(Input::maxYears($this->settings))->addDays(2)) {
            throw new ValidationException(['opensAt' => $this->trans('community_day')]);
        }

        $before = $model->getOriginal('opens_at');
        if ($model->exists && $before && $at < $before && Capsule::query()->where('community_id', $model->id)->exists()) {
            throw new ValidationException(['opensAt' => $this->trans('community_earlier')]);
        }
        $model->opens_at = $at;
    }

    private function trans(string $key): string
    {
        return $this->translator->trans('ernestdefoe-time-capsule.api.'.$key);
    }
}
