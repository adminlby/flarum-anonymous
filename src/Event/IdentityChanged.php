<?php

namespace AdminLby\Anonymous\Event;

use Flarum\Post\Post;
use Flarum\User\User;

final readonly class IdentityChanged
{
    public function __construct(public User $actor, public Post $post, public bool $wasAnonymous, public bool $isAnonymous, public array $postIds)
    {
    }
}
