<?php

namespace AdminLby\Anonymous\Notification;

use Flarum\Discussion\Discussion;

final class NewPostBlueprint extends AnonymousBlueprint
{
    public static function getType(): string { return 'newPost'; }
    public static function getSubjectModel(): string { return Discussion::class; }
}
