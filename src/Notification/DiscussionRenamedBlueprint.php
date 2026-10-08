<?php

namespace AdminLby\Anonymous\Notification;

use Flarum\Discussion\Discussion;

final class DiscussionRenamedBlueprint extends AnonymousBlueprint
{
    public static function getType(): string { return 'discussionRenamed'; }
    public static function getSubjectModel(): string { return Discussion::class; }
}
