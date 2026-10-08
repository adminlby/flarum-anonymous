<?php

namespace AdminLby\Anonymous\Access;

use AdminLby\Anonymous\Settings;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

final class AnonymousPolicy extends AbstractPolicy
{
    public function __construct(private Settings $settings)
    {
    }

    public function anonymousStartDiscussion(User $actor): bool
    {
        return $actor->exists && $this->settings->enabled('discussions')
            && $actor->hasPermission('anonymous.startDiscussion') && $actor->can('startDiscussion');
    }

    public function anonymousReply(User $actor, Discussion $discussion): bool
    {
        return $actor->exists && $this->settings->boolean('enabled')
            && $actor->hasPermission('discussion.anonymousReply') && $actor->can('reply', $discussion);
    }

    public function revealAnonymousAuthor(User $actor, Post $post): bool
    {
        return $actor->exists && $actor->hasPermission('anonymous.reveal') && (bool) $post->is_anonymous;
    }

    public function changeAnonymousIdentity(User $actor, Post $post): bool
    {
        return $actor->exists && $actor->hasPermission('anonymous.changeIdentity');
    }
}
