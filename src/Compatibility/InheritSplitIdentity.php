<?php

namespace AdminLby\Anonymous\Compatibility;

use AdminLby\Anonymous\Privacy;
use Flarum\Discussion\Discussion;
use FoF\Split\Events\DiscussionWasSplit;

final class InheritSplitIdentity
{
    public function __invoke(DiscussionWasSplit $event): void
    {
        $first = $event->newDiscussion->firstPost;
        $anonymous = ! $first || Privacy::post($first);
        Discussion::query()->whereKey($event->newDiscussion->id)->update(['is_anonymous' => $anonymous]);
        $event->newDiscussion->is_anonymous = $anonymous;
    }
}
