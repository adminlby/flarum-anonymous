<?php

namespace AdminLby\Anonymous;

use Flarum\Post\Post;
use WeakMap;

final class CreationContext
{
    private WeakMap $firstPosts;
    private WeakMap $submissions;

    public function __construct()
    {
        $this->firstPosts = new WeakMap();
        $this->submissions = new WeakMap();
    }

    public function mark(Post $post, bool $first): void
    {
        $this->firstPosts[$post] = $first;
    }

    public function isFirstPost(Post $post): bool
    {
        return $this->firstPosts[$post] ?? false;
    }

    public function markUserSubmission(Post $post): void
    {
        $this->submissions[$post] = true;
    }

    public function isUserSubmission(Post $post): bool
    {
        return $this->submissions[$post] ?? false;
    }
}
