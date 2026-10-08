<?php

namespace AdminLby\Anonymous\Api;

use Flarum\Api\Schema\Integer;
use Illuminate\Database\Eloquent\Builder;

final class PublicUserCounts
{
    public function __invoke(Integer $field): Integer
    {
        $comments = $field->name === 'commentCount';
        $alias = 'anonymousPublic'.ucfirst($field->name);
        $field->property(\Illuminate\Support\Str::snake($alias))->countRelation($comments ? 'posts' : 'discussions', function (Builder $query) use ($comments): void {
            $query->where('is_anonymous', false)->whereNull('hidden_at')->where('is_private', false);
            if ($comments) {
                $query->where('type', 'comment');
            }
        });
        // Use the resource aggregate's own alias instead of the persisted core
        // counter. This lets rc.8 batch every user's public count in one query.
        $field->relationAggregate['name'] = $alias;

        return $field;
    }
}
