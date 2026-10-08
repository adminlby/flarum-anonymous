<?php

namespace AdminLby\Anonymous\Api;

use AdminLby\Anonymous\Privacy;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;

final class DiscussionFields
{
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('isAnonymous')->writableOnCreate()->get(fn (Discussion $discussion) => Privacy::discussion($discussion))->set(fn () => null),
            Schema\Boolean::make('lastPostIsAnonymous')->get(fn (Discussion $discussion) => Privacy::lastPost($discussion)),
            Schema\Boolean::make('canAnonymousReply')->get(fn (Discussion $discussion, Context $context) => $context->getActor()->can('anonymousReply', $discussion)),
        ];
    }
}
