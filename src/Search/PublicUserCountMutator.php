<?php

namespace AdminLby\Anonymous\Search;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\SearchCriteria;
use Illuminate\Database\Eloquent\Builder;

final class PublicUserCountMutator
{
    public function __invoke(DatabaseSearchState $state, SearchCriteria $criteria): void
    {
        $query = $state->getQuery();
        // rc.8's searchable Index endpoints apply database sorts directly,
        // bypassing resource Sort callbacks. Replace their private counters too.
        foreach ($query->getQuery()->orders ?? [] as $index => $order) {
            $column = $order['column'] ?? '';
            $comments = $column === 'comment_count';
            if (! $comments && $column !== 'discussion_count') continue;
            $alias = 'anonymous_public_sort_'.($comments ? 'comments' : 'discussions');
            $query->withCount([($comments ? 'posts' : 'discussions').' as '.$alias => function (Builder $related) use ($comments): void {
                $related->where('is_anonymous', false)->whereNull('hidden_at')->where('is_private', false);
                if ($comments) $related->where('type', 'comment');
            }]);
            $query->getQuery()->orders[$index]['column'] = $alias;
        }
    }
}
