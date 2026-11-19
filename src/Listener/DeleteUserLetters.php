<?php

namespace ErnestDefoe\TimeCapsule\Listener;

use ErnestDefoe\TimeCapsule\Capsule;
use ErnestDefoe\TimeCapsule\Photos;
use Flarum\User\Event\Deleting;

/** Before the user row goes: its foreign keys would take the letters, but not the photo files. */
class DeleteUserLetters
{
    public function __construct(private readonly Photos $photos)
    {
    }

    public function handle(Deleting $event): void
    {
        $this->photos->deleteCapsules(Capsule::query()->where('user_id', $event->user->id)->pluck('id'));
    }
}
