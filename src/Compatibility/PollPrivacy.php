<?php

namespace AdminLby\Anonymous\Compatibility;

use AdminLby\Anonymous\Privacy;
use Flarum\Post\Post;
use FoF\Polls\Poll;

final class PollPrivacy
{
    public static function anonymous(Poll $poll): bool
    {
        if ($poll->post_id === null) return false; // Global polls have no post.
        $post = $poll->relationLoaded('post') ? $poll->post : Post::query()->allTypes()->find($poll->post_id);

        return ! $post || Privacy::post($post);
    }
}
