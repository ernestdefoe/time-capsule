<?php

use Illuminate\Database\Schema\Builder;

/*
 * The forum's birthday, for the "forum's anniversary" open date: the first
 * member's join date. Kept as a setting so the composer needs no query, and
 * so an admin can correct it (a forum moved from other software).
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        $settings = $db->table('settings');
        if ($settings->clone()->where('key', 'time-capsule.founded')->exists()) {
            return;
        }
        $first = $db->table('users')->whereNotNull('joined_at')->min('joined_at');
        $settings->clone()->insert(['key' => 'time-capsule.founded', 'value' => $first ? substr((string) $first, 0, 10) : date('Y-m-d')]);
    },
    'down' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->where('key', 'time-capsule.founded')->delete();
    },
];
