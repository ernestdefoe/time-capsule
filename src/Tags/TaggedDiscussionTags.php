<?php

namespace ErnestDefoe\TimeCapsule\Tags;

use ErnestDefoe\TimeCapsule\DiscussionTags;
use ErnestDefoe\TimeCapsule\Settings;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;
use Flarum\User\User;

/** DiscussionTags with flarum/tags enabled: the admin's tag for opened letters. */
class TaggedDiscussionTags extends DiscussionTags
{
    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function forAuthor(User $author): array|null|false
    {
        $id = (int) $this->settings->get(Settings::TAG);
        $tag = $id ? Tag::whereVisibleTo($author)->find($id) : null;
        if ($tag) {
            // Asked here first, so a writer who may no longer post there is recorded as done, not retried.
            return $author->can('startDiscussion', $tag) ? [$id] : false;
        }

        return $author->hasPermission('bypassTagCounts') ? null : false;
    }

    public function allowsPosting(User $actor): bool
    {
        return (int) $this->settings->get(Settings::TAG) > 0 || $actor->hasPermission('bypassTagCounts');
    }
}
