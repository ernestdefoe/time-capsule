<?php

/*
 * Time Capsule: letters to the future.
 *
 * A letter is encrypted before it reaches the database (Vault: libsodium,
 * key outside the database), its photos re-encoded and encrypted into
 * storage/. Until its day nobody reads it, admins included; the writer only
 * if they kept a key. Access is the one gate and Present the one place a
 * letter is decrypted for output, and only these plain endpoints serve
 * letters: no API resource, so no include can ever pull one in.
 *
 * The scheduler opens letters every minute (Opener: idempotent, safe to
 * overlap or rerun); the page opens any it shows that are due, for forums
 * whose scheduler isn't running.
 */

use ErnestDefoe\TimeCapsule\Api;
use ErnestDefoe\TimeCapsule\Capsule;
use ErnestDefoe\TimeCapsule\Console\OpenCommand;
use ErnestDefoe\TimeCapsule\Gdpr\TimeCapsuleData;
use ErnestDefoe\TimeCapsule\Http\PageContent;
use ErnestDefoe\TimeCapsule\Notification\CapsuleOpenedBlueprint;
use ErnestDefoe\TimeCapsule\Notification\CommunityOpenedBlueprint;
use ErnestDefoe\TimeCapsule\Photos;
use ErnestDefoe\TimeCapsule\Settings;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Gdpr\Extend\UserData;
use Flarum\User\Event\Deleting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // 🚨 Not optional: the page and the forms are chunks in here, and undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/time-capsule', 'time-capsule', PageContent::class)
        ->route('/time-capsule/{id:\d+}', 'time-capsule.letter', PageContent::class)
        ->route('/time-capsule/community/{id:\d+}', 'time-capsule.community', PageContent::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())
        ->namespace('ernestdefoe-time-capsule', __DIR__.'/views'),

    (new Extend\Routes('api'))
        ->get('/time-capsule', 'time-capsule.index', Api\PageController::class)
        ->post('/time-capsule', 'time-capsule.seal', Api\SealController::class)
        ->get('/time-capsule/{id:\d+}', 'time-capsule.show', Api\ShowController::class)
        ->delete('/time-capsule/{id:\d+}', 'time-capsule.delete', Api\DeleteController::class)
        ->get('/time-capsule/{id:\d+}/photos/{photo:\d+}', 'time-capsule.photo', Api\PhotoController::class)
        ->post('/time-capsule/community', 'time-capsule.community.create', Api\CommunityController::class)
        ->get('/time-capsule/community/{id:\d+}', 'time-capsule.community.show', Api\CommunityController::class)
        ->patch('/time-capsule/community/{id:\d+}', 'time-capsule.community.update', Api\CommunityController::class)
        ->delete('/time-capsule/community/{id:\d+}', 'time-capsule.community.delete', Api\CommunityController::class),

    (new Extend\Console())
        ->command(OpenCommand::class)
        ->schedule(OpenCommand::class, function (ScheduledEvent $event): void {
            $event->everyMinute()->withoutOverlapping();
        }),

    (new Extend\Notification())
        ->type(CapsuleOpenedBlueprint::class, ['alert', 'email'])
        ->type(CommunityOpenedBlueprint::class, ['alert']),

    // The viewer's own permissions: already loaded, so no query.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('timeCapsuleCanView')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::VIEW)),
        ]),

    (new Extend\Settings())
        ->default(Settings::NAV, true)
        ->default(Settings::MAX_YEARS, Settings::DEFAULT_MAX_YEARS)
        ->serializeToForum('timeCapsuleNav', Settings::NAV, 'boolval'),

    // Before the user row goes: its foreign keys would take the letters, but not the photo files.
    (new Extend\Event())
        ->listen(Deleting::class, function (Deleting $event) {
            resolve(Photos::class)->deleteCapsules(Capsule::query()->where('user_id', $event->user->id)->pluck('id'));
        }),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(TimeCapsuleData::class),
        ]),
];
