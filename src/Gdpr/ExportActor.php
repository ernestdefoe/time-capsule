<?php

namespace ErnestDefoe\TimeCapsule\Gdpr;

use Flarum\Gdpr\Events\Exported;
use Flarum\Gdpr\Events\Exporting;

/**
 * Who is exporting whose data. flarum/gdpr lets a moderator with
 * `moderateExport` export another member's data, and the download goes to
 * that moderator; the export types are never told the actor. Exporting is
 * dispatched right before the types run (in the same job), so we note the
 * pair here and forget it on Exported. No pair noted = not the member = no
 * letter text.
 */
final class ExportActor
{
    private static ?int $user = null;
    private static ?int $actor = null;

    public static function exporting(Exporting $event): void
    {
        self::$user = $event->user->id;
        self::$actor = $event->actor->id;
    }

    public static function exported(Exported $event): void
    {
        self::$user = self::$actor = null;
    }

    /** True only while the member themself is exporting their own data. */
    public static function isSelf(int $userId): bool
    {
        return self::$user === $userId && self::$actor === $userId;
    }
}
