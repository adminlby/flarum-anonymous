<?php

namespace AdminLby\Anonymous\Notification;

use Flarum\Post\Post;

final class GroupMentionedBlueprint extends AnonymousBlueprint
{
    public static function getType(): string { return 'groupMentioned'; }
    public static function getSubjectModel(): string { return Post::class; }
}
