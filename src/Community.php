<?php

namespace ErnestDefoe\TimeCapsule;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A forum-wide capsule: members add letters, and they all open on one day.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $title
 * @property string|null $description
 * @property Carbon $opens_at
 * @property Carbon|null $opened_at
 * @property bool $post_to_discussion
 * @property bool $notify_everyone
 * @property int|null $discussion_id
 * @property Carbon|null $posted_at
 * @property int $post_attempts
 * @property int $notify_cursor
 * @property Carbon|null $notified_at
 * @property Carbon $created_at
 * @property User|null $user
 * @property Discussion|null $discussion
 */
class Community extends AbstractModel
{
    protected $table = 'time_capsule_communities';

    public $timestamps = false;

    protected $casts = [
        'opens_at' => 'datetime',
        'opened_at' => 'datetime',
        'posted_at' => 'datetime',
        'notified_at' => 'datetime',
        'created_at' => 'datetime',
        'post_to_discussion' => 'boolean',
        'notify_everyone' => 'boolean',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Discussion, $this> */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    public function isOpen(): bool
    {
        return $this->opened_at !== null;
    }
}
