<?php

namespace AdminLby\Anonymous\Search;

use Flarum\Discussion\Search\Filter\AuthorFilter;
use Flarum\Search\SearchState;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

final class AnonymousDiscussionAuthorFilter extends AuthorFilter
{
    protected function constrain(Builder $query, string|array $rawSlugs, User $actor, bool $negate): void
    {
        $query->where(function (Builder $nested) use ($rawSlugs, $actor, $negate): void {
            parent::constrain($nested, $rawSlugs, $actor, $negate);
            $nested->where('discussions.is_anonymous', false);
            if ($negate) {
                $nested->orWhere('discussions.is_anonymous', true);
            }
        });
    }
}
