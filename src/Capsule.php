<?php

namespace ErnestDefoe\TimeCapsule;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One letter. `sealed_body` is ciphertext (Vault); read it only through
 * Present, after Access says the viewer may.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $community_id
 * @property string|null $envelope
 * @property string $sealed_body
 * @property int $words
 * @property int $photo_count
 * @property Carbon $opens_at
 * @property Carbon $sealed_at
 * @property Carbon|null $opened_at
 * @property string $visibility
 * @property bool $author_can_read
 * @property bool $post_to_discussion
 * @property int|null $discussion_id
 * @property Carbon|null $posted_at
 * @property int $post_attempts
 * @property Carbon|null $notified_at
 * @property Carbon|null $read_at
 * @property User|null $user
 * @property Community|null $community
 * @property Discussion|null $discussion
 * @property \Illuminate\Database\Eloquent\Collection<int, Photo> $photos
 */
class Capsule extends AbstractModel
{
    public const PRIVATE = 'private';
    public const PUBLIC = 'public';

    protected $table = 'time_capsules';

    public $timestamps = false;

    protected $casts = [
        'opens_at' => 'datetime',
        'sealed_at' => 'datetime',
        'opened_at' => 'datetime',
        'posted_at' => 'datetime',
        'notified_at' => 'datetime',
        'read_at' => 'datetime',
        'author_can_read' => 'boolean',
        'post_to_discussion' => 'boolean',
    ];

    /** Never serialized by accident: the ciphertext stays out of toArray()/toJson(). */
    protected $hidden = ['sealed_body'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Community, $this> */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /** @return BelongsTo<Discussion, $this> */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    /** @return HasMany<Photo, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class, 'capsule_id')->orderBy('position');
    }

    public function isOpen(): bool
    {
        return $this->opened_at !== null;
    }

    /** What the ciphertext is bound to: a letter moved to another member no longer decrypts. */
    public function context(): string
    {
        return 'capsule:'.$this->user_id;
    }
}
