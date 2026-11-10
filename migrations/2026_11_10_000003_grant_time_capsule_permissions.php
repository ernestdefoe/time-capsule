<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'time-capsule.view' => Group::GUEST_ID,
    'time-capsule.create' => Group::MEMBER_ID,
    'time-capsule.moderate' => Group::MODERATOR_ID,
]);
