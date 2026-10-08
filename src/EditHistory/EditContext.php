<?php

namespace AdminLby\Anonymous\EditHistory;

use Flarum\Post\Post;

final class EditContext
{
    /** @var array<int, array{actor: int, anonymous: bool, post: Post}> */
    private array $decisions = [];

    public function remember(Post $post, int $actor, bool $anonymous): void
    {
        $this->decisions[$post->id] = compact('actor', 'anonymous', 'post');
    }

    public function choice(int $post, ?int $actor): ?bool
    {
        $decision = $this->decisions[$post] ?? null;

        return $decision && $decision['actor'] === $actor ? $decision['anonymous'] : null;
    }

    public function saved(int $post, bool $anonymous): void
    {
        if (isset($this->decisions[$post])) {
            $this->decisions[$post]['post']->last_edit_is_anonymous = $anonymous;
        }
    }
}
