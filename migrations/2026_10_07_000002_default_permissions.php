<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'anonymous.startDiscussion' => Group::MEMBER_ID,
    'discussion.anonymousReply' => Group::MEMBER_ID,
]);
