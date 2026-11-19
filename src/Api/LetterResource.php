<?php

namespace ErnestDefoe\TimeCapsule\Api;

use ErnestDefoe\TimeCapsule\Access;
use ErnestDefoe\TimeCapsule\Capsule;
use ErnestDefoe\TimeCapsule\OpenedLetters;
use ErnestDefoe\TimeCapsule\Photos;
use ErnestDefoe\TimeCapsule\Present;
use ErnestDefoe\TimeCapsule\Settings;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Pagination\Pagination;
use WeakMap;

/**
 * The `time-capsule-letters` type: every letter endpoint.
 *
 * GET    /api/time-capsule-letters                       your own opened letters, newest first (page[offset])
 * GET    /api/time-capsule-letters/{id}                  one letter
 * DELETE /api/time-capsule-letters/{id}                  the writer, or a moderator; never read first
 * GET    /api/time-capsule-letters/page                  everything the /time-capsule page shows (Action\Page)
 * POST   /api/time-capsule-letters/seal                  seal a letter, multipart with photos (Action\Seal)
 * POST   /api/time-capsule-letters/open                  open what the viewer was shown as due (Action\Open)
 * POST   /api/time-capsule-letters/{id}/read             the writer has read it (Action\Read)
 * GET    /api/time-capsule-letters/{id}/photos/{photo}   one photo, decrypted (Action\PhotoFile)
 * GET    /api/time-capsule-letters/community/{community} a community capsule and its letters (Action\CommunityLetters)
 *
 * How the seal holds here:
 * - scope(): a viewer finds only their own letters (sealed ones as cards) and opened letters they
 *   may read (public or community, with View time capsules). Anyone else's sealed or private letter
 *   is "not found", the same as one that doesn't exist.
 * - The letter's words, photos, author and discussion are fields visible only when Access::canRead()
 *   says so (opened and allowed, or the writer's own kept key), and Present is the one place a
 *   letter is decrypted.
 * - No other resource declares a relationship to this one, so no include can ever reach a letter.
 *
 * @extends AbstractDatabaseResource<Capsule>
 */
class LetterResource extends AbstractDatabaseResource
{
    /*
     * No constructor dependencies: Flarum lists a resource's endpoints and fields on an instance it
     * builds without one. What a request needs is made for that request (see action()).
     */

    /** @var WeakMap<Capsule, array<string, mixed>> */
    private WeakMap $rows;

    /** @var WeakMap<ServerRequestInterface, Present> */
    private WeakMap $presenters;

    public function type(): string
    {
        return 'time-capsule-letters';
    }

    public function model(): string
    {
        return Capsule::class;
    }

    /** @param Builder<Capsule> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        $actor = $context->getActor();

        // Moderators may delete any letter without reading it: the delete finds every letter.
        if ($context->endpoint instanceof Endpoint\Delete && $actor->can(Settings::MODERATE)) {
            return;
        }

        $own = ! $actor->isGuest();
        $shared = $actor->can(Settings::VIEW);
        if (! $own && ! $shared) {
            $query->whereKey([]);

            return;
        }

        $query->where(function (Builder $visible) use ($actor, $own, $shared) {
            if ($own) {
                $visible->where('time_capsules.user_id', $actor->id);
            }
            if ($shared) {
                $visible->orWhere(fn (Builder $open) => $open->whereNotNull('time_capsules.opened_at')
                    ->where(fn (Builder $public) => $public->whereNotNull('time_capsules.community_id')->orWhere('time_capsules.visibility', Capsule::PUBLIC)));
            }
        });
    }

    public function endpoints(): array
    {
        $relations = fn (Endpoint\Index|Endpoint\Show $endpoint) => $endpoint
            ->eagerLoad(['user', 'photos', 'discussion'])
            ->eagerLoadWhere('discussion', fn ($query, Context $context) => $query->whereVisibleTo($context->getActor()));

        return [
            // Static paths first: FastRoute won't add one after the /{id} it would be shadowed by.
            $this->action('page', 'GET', '/page', Action\Page::class)->can(Settings::VIEW),
            $this->action('seal', 'POST', '/seal', Action\Seal::class)->authenticated()->can(Settings::CREATE),
            $this->action('open', 'POST', '/open', Action\Open::class)->can(Settings::VIEW),
            $this->action('read', 'POST', '/{id}/read', Action\Read::class)->authenticated(),
            $this->action('photo', 'GET', '/{id}/photos/{photo}', Action\PhotoFile::class),
            $this->action('community', 'GET', '/community/{community}', Action\CommunityLetters::class)->can(Settings::VIEW),
            $relations(Endpoint\Index::make()
                ->can(Settings::VIEW)
                ->authenticated()
                ->query(function (Builder $query, ?Pagination $pagination, Context $context) {
                    OpenedLetters::constrain($query, $context->getActor());
                    if ($pagination && method_exists($pagination, 'apply')) {
                        $pagination->apply($query);
                    }

                    return $context->withQuery($query);
                })
                ->paginate(30, 50)),
            $relations(Endpoint\Show::make()->can(Settings::VIEW)),
            Endpoint\Delete::make()
                ->authenticated()
                ->can(fn (Capsule $capsule, Context $context) => Access::canDelete($capsule, $context->getActor()) ? null : Settings::MODERATE),
        ];
    }

    public function fields(): array
    {
        $row = fn (string $key) => fn (Capsule $capsule, Context $context) => $this->row($capsule, $context)[$key] ?? null;
        $readable = fn (Capsule $capsule, Context $context) => Access::canRead($capsule, $context->getActor());

        return [
            Schema\Boolean::make('sealed')->get($row('sealed')),
            Schema\Boolean::make('mine')->get($row('mine')),
            Schema\Str::make('envelope')->nullable()->get($row('envelope')),
            Schema\DateTime::make('opensAt')->property('opens_at'),
            Schema\DateTime::make('sealedAt')->property('sealed_at'),
            Schema\DateTime::make('openedAt')->property('opened_at'),
            Schema\Integer::make('words'),
            Schema\Integer::make('photoCount')->property('photo_count'),
            Schema\Str::make('visibility')->get($row('visibility')),
            Schema\Integer::make('community')->nullable()->property('community_id'),
            Schema\Boolean::make('post')->property('post_to_discussion'),
            Schema\Boolean::make('canDelete')->get($row('canDelete')),
            Schema\Boolean::make('peek')->visible(fn (Capsule $capsule, Context $context) => $this->row($capsule, $context)['mine'])->get($row('peek')),
            // Only for a viewer who may read it: never a sealed letter's words, photos or anything about them.
            Schema\Boolean::make('readable')->visible($readable)->get($row('readable')),
            Schema\Str::make('body')->visible($readable)->get($row('body')),
            Schema\Arr::make('photos')->visible($readable)->get($row('photos')),
            Schema\Arr::make('author')->visible($readable)->get($row('author')),
            Schema\Arr::make('discussion')->nullable()->visible($readable)->get($row('discussion')),
            Schema\Boolean::make('firstRead')->visible($readable)->get($row('firstRead')),
        ];
    }

    /** The writer, or a moderator; the letter and its photo files, never read first. */
    public function delete(object $model, BaseContext $context): void
    {
        $context->api->getContainer()->make(Photos::class)->deleteCapsules([$model->id]);
    }

    /** Present's row for a letter (decrypted at most once per letter per response). */
    private function row(Capsule $capsule, Context $context): array
    {
        $this->presenters ??= new WeakMap();
        $this->rows ??= new WeakMap();
        $present = $this->presenters[$context->request] ??= $context->api->getContainer()->make(Present::class);

        return $this->rows[$capsule] ??= $present->capsule($capsule, $context->getActor(), true);
    }

    /**
     * A custom endpoint answering with its own plain-JSON body (the shapes the page uses). Its
     * handler is made fresh for each request (as core's Index makes its SearchManager), so nothing
     * a request holds, such as the encryption key, outlives it in a long-running process.
     *
     * @param class-string $handler an invokable taking the Context
     */
    private function action(string $name, string $method, string $path, string $handler): Endpoint\Endpoint
    {
        return Endpoint\Endpoint::make($name)
            ->route($method, $path)
            ->action(fn (Context $context) => $context->api->getContainer()->make($handler)($context))
            ->response(fn (Context $context, ResponseInterface $response) => $response);
    }
}
