<?php

namespace AdminLby\Anonymous\Search;

use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context;
use Tobyz\JsonApiServer\Schema\Sort;

final class PublicUserCountSort extends Sort
{
    public function apply(object $query, string $direction, Context $context): void
    {
        $comments = $this->name === 'commentCount';
        $alias = 'anonymous_public_sort_'.($comments ? 'comments' : 'discussions');
        $relation = $comments ? 'posts' : 'discussions';
        $query->withCount([$relation.' as '.$alias => function (Builder $related) use ($comments): void {
            $related->where('is_anonymous', false)->whereNull('hidden_at')->where('is_private', false);
            if ($comments) {
                $related->where('type', 'comment');
            }
        }])->orderBy($alias, $direction);
    }
}
