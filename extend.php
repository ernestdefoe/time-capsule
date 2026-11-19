<?php

/*
 * Time Capsule: letters to the future.
 *
 * A letter is encrypted before it reaches the database (Vault: libsodium,
 * key outside the database), its photos re-encoded and encrypted into
 * the extension's private disk. Until its day nobody reads it, admins included; the writer only
 * if they kept a key. Access is the one gate and Present the one place a
 * letter is decrypted for output. Letters are one API resource
 * (time-capsule-letters) whose text is visible only when it may be read, and
 * no other resource has a relationship to it, so no include can reach one.
 *
 * The scheduler opens letters every minute (Opener: idempotent, safe to
 * overlap or rerun). GET requests only read: a page that shows a due letter
 * asks POST /api/time-capsule-letters/open, which opens the viewer's own due letters
 * and any due community capsule (forums without a running scheduler still
 * open letters).
 */

use ErnestDefoe\TimeCapsule\Api;
use ErnestDefoe\TimeCapsule\Console\OpenCommand;
use ErnestDefoe\TimeCapsule\Gdpr\ExportActor;
use ErnestDefoe\TimeCapsule\Gdpr\TimeCapsuleData;
use ErnestDefoe\TimeCapsule\Http\PageContent;
use ErnestDefoe\TimeCapsule\Listener\DeleteUserLetters;
use ErnestDefoe\TimeCapsule\Notification\CapsuleOpenedBlueprint;
use ErnestDefoe\TimeCapsule\Notification\CommunityOpenedBlueprint;
use ErnestDefoe\TimeCapsule\Tags;
use Flarum\Api\Resource\ForumResource;
use Flarum\Extend;
use Flarum\Foundation\Paths;
use Flarum\Gdpr\Events\Exported;
use Flarum\Gdpr\Events\Exporting;
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

    // Every letter endpoint: one resource with no relationship pointing at it (see LetterResource).
    new Extend\ApiResource(Api\LetterResource::class),

    // The community capsule itself carries no letter text: an ordinary resource.
    new Extend\ApiResource(Api\CommunityResource::class),

    (new Extend\Console())
        ->command(OpenCommand::class)
        ->schedule(OpenCommand::class, function (ScheduledEvent $event): void {
            $event->everyMinute()->withoutOverlapping();
        }),

    (new Extend\Notification())
        ->type(CapsuleOpenedBlueprint::class, ['alert', 'email'])
        ->type(CommunityOpenedBlueprint::class, ['alert']),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(Api\ForumFields::class),

    // The key file and the encrypted photos (Vault::DISK): private, outside public/.
    (new Extend\Filesystem())
        ->disk('ernestdefoe-time-capsule', fn (Paths $paths): array => [
            'root' => $paths->storage.'/time-capsule',
            'visibility' => 'private',
        ]),

    // String keys, not Settings:: constants: extend.php runs on every boot, also mid-deploy.
    (new Extend\Settings())
        ->default('time-capsule.nav', true)
        ->default('time-capsule.tag', '')
        ->default('time-capsule.max_years', 25)
        ->serializeToForum('timeCapsuleNav', 'time-capsule.nav', 'boolval'),

    (new Extend\Event())
        ->listen(Deleting::class, DeleteUserLetters::class),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-tags', fn () => [
            (new Extend\ServiceProvider())->register(Tags\TagsServiceProvider::class),
        ])
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(TimeCapsuleData::class),
            (new Extend\Event())
                ->listen(Exporting::class, [ExportActor::class, 'exporting'])
                ->listen(Exported::class, [ExportActor::class, 'exported']),
        ]),
];
