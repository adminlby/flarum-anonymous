<?php

namespace AdminLby\Anonymous\Event;

use Flarum\Post\Post;
use Flarum\User\User;

// Public integration point, emitted on every successful explicit reveal.
final readonly class AuthorRevealed
{
    public function __construct(public User $actor, public Post $post)
    {
    }
}
