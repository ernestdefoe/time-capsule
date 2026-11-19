<?php

namespace ErnestDefoe\TimeCapsule\Tags;

use ErnestDefoe\TimeCapsule\DiscussionTags;
use Flarum\Foundation\AbstractServiceProvider;

/** Registered only while flarum/tags is enabled (extend.php): opened letters' discussions get the admin's tag. */
class TagsServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(DiscussionTags::class, TaggedDiscussionTags::class);
    }
}
