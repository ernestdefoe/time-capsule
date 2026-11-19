<?php

namespace ErnestDefoe\TimeCapsule;

use Flarum\User\User;
use Illuminate\Support\Collection;

/** A member's own opened letters, newest first, a page at a time (the page and Api\MineController). */
class OpenedLetters
{
    public const PAGE = 30;

    /**
     * @return array{0: Collection<int, Capsule>, 1: bool} the letters, and whether there are more
     */
    public function page(User $actor, int $offset): array
    {
        $letters = Capsule::query()->where('user_id', $actor->id)->whereNotNull('opened_at')
            ->with(Present::with($actor, false))->orderByDesc('opened_at')->orderByDesc('id')
            ->offset(max(0, $offset))->limit(self::PAGE + 1)->get();
        $letters->each(fn (Capsule $capsule) => $capsule->setRelation('user', $actor));

        return [$letters->take(self::PAGE)->values(), $letters->count() > self::PAGE];
    }
}
