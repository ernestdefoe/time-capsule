<?php

namespace ErnestDefoe\TimeCapsule\Api;

use ErnestDefoe\TimeCapsule\Settings;
use Flarum\Api\Context;
use Flarum\Api\Schema;

/** The viewer's own permission on the forum resource: already loaded, so no query. */
class ForumFields
{
    /** @return array<Schema\Boolean> */
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('timeCapsuleCanView')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::VIEW)),
        ];
    }
}
