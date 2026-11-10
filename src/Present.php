<?php

namespace ErnestDefoe\TimeCapsule;

use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;

/**
 * Turns letters into the rows the browser gets. The one place a letter is
 * decrypted for output, and only after Access::canRead(). Every other field
 * is safe to show for a sealed letter: its envelope note, dates, length and
 * photo count — never a word of the letter or a photo id.
 */
class Present
{
    private const EXCERPT = 280;

    public function __construct(private readonly Vault $vault, private readonly UrlGenerator $url, private readonly SlugManager $slugs)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function capsule(Capsule $capsule, User $actor, bool $full = false): array
    {
        $row = [
            'id' => $capsule->id,
            'sealed' => ! $capsule->isOpen(),
            'mine' => ! $actor->isGuest() && $actor->id === $capsule->user_id,
            'envelope' => $capsule->envelope,
            'opensAt' => $capsule->opens_at->toIso8601String(),
            'sealedAt' => $capsule->sealed_at->toIso8601String(),
            'openedAt' => $capsule->opened_at?->toIso8601String(),
            'words' => $capsule->words,
            'photoCount' => $capsule->photo_count,
            'visibility' => $capsule->community_id ? Capsule::PUBLIC : $capsule->visibility,
            'community' => $capsule->community_id,
            'post' => $capsule->post_to_discussion,
            'canDelete' => Access::canDelete($capsule, $actor),
        ];

        if ($row['mine']) {
            $row['peek'] = $capsule->author_can_read;
        }

        if (! Access::canRead($capsule, $actor)) {
            return $row;
        }

        $body = $this->vault->open($capsule->sealed_body, $capsule->context());
        $row['readable'] = $body !== null;
        $row['body'] = $full ? $body : self::excerpt((string) $body);
        $row['truncated'] = ! $full && $body !== null && mb_strlen($body) > self::EXCERPT;
        $row['photos'] = $capsule->relationLoaded('photos') ? $capsule->photos->map(fn (Photo $p) => [
            'url' => $this->url->to('api')->path('time-capsule/'.$capsule->id.'/photos/'.$p->id),
            'width' => $p->width,
            'height' => $p->height,
        ])->values()->all() : [];
        $row['author'] = $this->user($capsule->user);
        $row['discussion'] = $capsule->discussion_id && $capsule->relationLoaded('discussion') && $capsule->discussion
            ? ['id' => $capsule->discussion->id, 'slug' => $capsule->discussion->slug, 'title' => $capsule->discussion->title]
            : null;
        $row['firstRead'] = $row['mine'] && $capsule->isOpen() && $capsule->read_at === null;

        return $row;
    }

    /**
     * @param array{letters?: int, members?: int, mine?: int} $counts
     * @return array<string, mixed>
     */
    public function community(Community $community, User $actor, array $counts = []): array
    {
        return [
            'id' => $community->id,
            'title' => $community->title,
            'description' => $community->description,
            'opensAt' => $community->opens_at->toIso8601String(),
            'openedAt' => $community->opened_at?->toIso8601String(),
            'createdAt' => $community->created_at->toIso8601String(),
            'letters' => $counts['letters'] ?? 0,
            'members' => $counts['members'] ?? 0,
            'mine' => $counts['mine'] ?? 0,
            'post' => $community->post_to_discussion,
            'notifyEveryone' => $community->notify_everyone,
            'discussionId' => $community->discussion_id,
            'canManage' => $actor->can(Settings::COMMUNITY),
        ];
    }

    /** @return array<string, mixed>|null */
    public function user(?User $user): ?array
    {
        return $user ? [
            'id' => $user->id,
            'name' => $user->display_name,
            'slug' => $this->slugs->forResource(User::class)->toSlug($user),
            'avatar' => $user->avatar_url,
        ] : null;
    }

    private static function excerpt(string $body): string
    {
        return mb_strlen($body) > self::EXCERPT ? rtrim(mb_substr($body, 0, self::EXCERPT)).'…' : $body;
    }
}
