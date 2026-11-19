<?php

namespace ErnestDefoe\TimeCapsule;

use Flarum\User\User;

/**
 * Which tags an opened letter's discussion goes in. This is the forum without
 * flarum/tags: no tags needed. With Tags enabled, extend.php binds
 * Tags\TaggedDiscussionTags in its place (Extend\Conditional), so nothing here
 * ever loads a Tags class on a forum that doesn't have it.
 */
class DiscussionTags
{
    /**
     * The tags for a discussion the author starts.
     *
     * @return list<int>|null|false null: no tags needed; false: the author can't post one
     */
    public function forAuthor(User $author): array|null|false
    {
        return null;
    }

    /** Whether tags leave "also start a discussion" possible for this member (asked again on the day). */
    public function allowsPosting(User $actor): bool
    {
        return true;
    }
}
