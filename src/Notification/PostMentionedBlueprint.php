<?php

namespace AdminLby\Anonymous\Notification;

use Flarum\Post\Post;

final class PostMentionedBlueprint extends AnonymousBlueprint
{
    public static function getType(): string { return 'postMentioned'; }
    public static function getSubjectModel(): string { return Post::class; }
}
