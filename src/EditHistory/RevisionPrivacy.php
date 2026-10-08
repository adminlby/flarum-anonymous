<?php

namespace AdminLby\Anonymous\EditHistory;

use AdminLby\Anonymous\Privacy;
use Flarum\Post\Post;
use HuseyinFiliz\Diff\Models\Diff;

final class RevisionPrivacy
{
    public static function identity(Diff $diff): ?RevisionIdentity
    {
        $identity = $diff->anonymousIdentity;

        return $identity && (int) $identity->post_id === (int) $diff->post_id ? $identity : null;
    }

    public static function anonymous(Diff $diff): bool
    {
        $post = $diff->post;
        if (! $post) return true;
        $identity = self::identity($diff);
        $actor = self::editorId($diff);
        // Revision zero describes the original submission, never this edit.
        // The original anonymous author remains anonymous in every revision.
        if (Privacy::post($post) && ((int) $diff->revision === 0 || $actor === Privacy::authorId($post))) {
            return true;
        }

        if ($identity) return $identity->is_anonymous;

        return Privacy::post($post) || $post->anonymousEditingActors->contains(fn (RevisionIdentity $pending) => (int) $pending->actor_reference === $actor);
    }

    public static function editorId(Diff $diff): ?int
    {
        $id = $diff->actor_id ?? self::identity($diff)?->actor_reference;
        if ($id === null && (int) $diff->revision === 0 && $diff->post && Privacy::post($diff->post)) {
            $id = Privacy::authorId($diff->post);
        }

        return $id === null ? null : (int) $id;
    }

    public static function hidesAction(Diff $diff, string $relation): bool
    {
        if (self::anonymous($diff)) return true;
        $post = $diff->post;
        $user = $diff->$relation;

        return $post && Privacy::post($post) && $user && $user->id === Privacy::authorId($post);
    }

    public static function originalAuthor(Post $post, ?int $actor): bool
    {
        return Privacy::post($post) && $actor !== null && $actor === Privacy::authorId($post);
    }
}
