<?php

namespace AdminLby\Anonymous\EditHistory;

use AdminLby\Anonymous\Privacy;
use Flarum\Post\Post;
use HuseyinFiliz\Diff\Models\Diff;

final class RecordRevisionIdentity
{
    public function __construct(private EditContext $context)
    {
    }

    public function __invoke(Diff $diff): void
    {
        $post = $diff->post;
        if (! $post) return;
        $actor = $diff->actor_id === null ? null : (int) $diff->actor_id;
        $original = (int) $diff->revision === 0;
        $anonymous = $original ? Privacy::post($post)
            : (RevisionPrivacy::originalAuthor($post, $actor)
                || ($this->context->choice($post->id, $actor) ?? Privacy::post($post)));

        // Append, rather than overwrite, so resetting/reinstalling Diff cannot
        // erase the audit privacy of an earlier anonymous edit with the same id.
        RevisionIdentity::query()->create([
            'diff_id' => $diff->id, 'post_id' => $diff->post_id,
            'actor_reference' => $original ? Privacy::authorId($post) : $actor, 'is_anonymous' => $anonymous,
        ]);
        if (! $original) {
            Post::query()->allTypes()->whereKey($post->id)->update(['last_edit_is_anonymous' => $anonymous]);
            $this->context->saved($post->id, $anonymous);
        }
    }
}
