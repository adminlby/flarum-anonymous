<?php

namespace AdminLby\Anonymous\Compatibility;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use HuseyinFiliz\Leaderboard\Model\LeaderboardPoint;
use Illuminate\Database\Eloquent\Builder;

final class PublicLeaderboard
{
    public const REASONS = ['discussion_started', 'post_created', 'daily_login', 'like_received', 'like_given', 'reaction_received', 'reaction_given', 'best_answer', 'badge_earned', 'upvote_received', 'downvote_received'];

    public static function scope(Builder $query): Builder
    {
        // Public ranking never derives points from an anonymous source, even
        // for admins. Keep native ledgers and totals intact for administration.
        return $query->where(function (Builder $query): void {
            $query->whereNull('subject_type')->orWhereNotIn('subject_type', ['post', 'discussion'])
                ->orWhere(fn (Builder $query) => $query->where('subject_type', 'post')->whereIn('subject_id',
                    Post::query()->allTypes()->where('is_anonymous', false)->where('is_private', false)->whereNull('hidden_at')->select('id')))
                ->orWhere(fn (Builder $query) => $query->where('subject_type', 'discussion')->whereIn('subject_id',
                    Discussion::query()->where('is_anonymous', false)->where('is_private', false)->whereNull('hidden_at')->select('id')));
        });
    }

    public static function query(): Builder
    {
        return self::scope(LeaderboardPoint::query());
    }
}
