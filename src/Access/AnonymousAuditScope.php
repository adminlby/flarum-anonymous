<?php

namespace AdminLby\Anonymous\Access;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

final class AnonymousAuditScope
{
    public function __invoke(User $actor, Builder $query): void
    {
        if ($actor->hasPermission('anonymous.reveal')) {
            return;
        }

        // Audit viewers without reveal permission must not correlate actor_id,
        // payload.user_id, IP addresses or author filters with anonymous posts.
        $query->whereNotIn('action', ['anonymous.author_revealed', 'anonymous.edit_author_revealed', 'anonymous.identity_changed']);
        foreach (['post_id' => Post::class, 'discussion_id' => Discussion::class, 'new_discussion_id' => Discussion::class, 'original_discussion_id' => Discussion::class] as $key => $model) {
            $column = 'payload->'.$key;
            $anonymous = $model::query();
            // Privacy metadata remains authoritative if another extension's
            // Post type is no longer registered after that extension is disabled.
            if ($model === Post::class) $anonymous->allTypes();
            $query->where(fn (Builder $query) => $query->whereNull($column)
                ->orWhereNotIn($column, $anonymous->where('is_anonymous', true)->select('id')));
            if ($model === Post::class) {
                $query->where(fn (Builder $query) => $query->whereNull($column)->orWhereNotIn($column,
                    \AdminLby\Anonymous\EditHistory\RevisionIdentity::query()->where('is_anonymous', true)->select('post_id')));
            }
        }
    }
}
