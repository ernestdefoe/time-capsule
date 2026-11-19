<?php

namespace ErnestDefoe\TimeCapsule;

use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** A member's own opened letters, newest first, a page at a time (the page, and the letters resource's Index after it). */
class OpenedLetters
{
    public const PAGE = 30;

    /**
     * A member's opened letters, newest first: the page's first page and the letters resource's Index.
     *
     * @param Builder<Capsule> $query
     * @return Builder<Capsule>
     */
    public static function constrain(Builder $query, User $actor): Builder
    {
        return $query->where('time_capsules.user_id', $actor->id)->whereNotNull('time_capsules.opened_at')
            ->orderByDesc('time_capsules.opened_at')->orderByDesc('time_capsules.id');
    }

    /**
     * @return array{0: Collection<int, Capsule>, 1: bool} the letters, and whether there are more
     */
    public function page(User $actor, int $offset): array
    {
        $letters = self::constrain(Capsule::query(), $actor)->with(Present::with($actor, false))
            ->offset(max(0, $offset))->limit(self::PAGE + 1)->get();
        $letters->each(fn (Capsule $capsule) => $capsule->setRelation('user', $actor));

        return [$letters->take(self::PAGE)->values(), $letters->count() > self::PAGE];
    }
}
