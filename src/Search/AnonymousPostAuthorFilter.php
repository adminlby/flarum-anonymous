<?php

namespace AdminLby\Anonymous\Search;

use Flarum\Post\Filter\AuthorFilter;
use Flarum\Search\SearchState;

final class AnonymousPostAuthorFilter extends AuthorFilter
{
    public function filter(SearchState $state, string|array $value, bool $negate): void
    {
        $query = $state->getQuery();
        // For both positive and negative queries, anonymous results must be
        // independent of the requested identity. Negation cannot be an oracle.
        $query->where(function ($nested) use ($state, $value, $negate): void {
            $original = $state->getQuery();
            $state->setQuery($nested);
            parent::filter($state, $value, $negate);
            $state->setQuery($original);
            $nested->where('posts.is_anonymous', false);
            if ($negate) {
                $nested->orWhere('posts.is_anonymous', true);
            }
        });
    }
}
