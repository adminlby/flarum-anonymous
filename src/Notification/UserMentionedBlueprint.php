<?php

namespace AdminLby\Anonymous\Notification;

use Flarum\Post\Post;

final class UserMentionedBlueprint extends AnonymousBlueprint
{
    public static function getType(): string { return 'userMentioned'; }
    public static function getSubjectModel(): string { return Post::class; }
}
