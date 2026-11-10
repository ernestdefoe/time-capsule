<?php

namespace ErnestDefoe\TimeCapsule;

use Flarum\User\User;

/**
 * Who may read a letter. The ONLY gate in front of Present::letter().
 *
 * Sealed: nobody, admins included; the author only if they chose to keep
 * a key ("let me reread it"). Opened: the author always; anyone who may view
 * time capsules for a public letter or a community letter; a private letter
 * stays the author's alone.
 */
final class Access
{
    public static function canRead(Capsule $capsule, User $actor): bool
    {
        $mine = ! $actor->isGuest() && $actor->id === $capsule->user_id;

        if (! $capsule->isOpen()) {
            return $mine && $capsule->author_can_read;
        }

        if ($mine) {
            return true;
        }

        return ($capsule->community_id !== null || $capsule->visibility === Capsule::PUBLIC) && $actor->can(Settings::VIEW);
    }

    public static function canDelete(Capsule $capsule, User $actor): bool
    {
        return (! $actor->isGuest() && $actor->id === $capsule->user_id) || $actor->can(Settings::MODERATE);
    }
}
