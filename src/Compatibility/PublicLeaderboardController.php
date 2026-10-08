<?php

namespace AdminLby\Anonymous\Compatibility;

use Carbon\Carbon;
use Flarum\User\User;
use HuseyinFiliz\Leaderboard\Api\Controller\ListLeaderboardController;
use HuseyinFiliz\Leaderboard\Api\Data\LeaderboardEntryData;

final class PublicLeaderboardController extends ListLeaderboardController
{
    protected function getAllTimeResults(int $offset, int $limit, array $excludedGroupIds): array
    {
        return $this->publicResults(null, $offset, $limit, $excludedGroupIds);
    }

    protected function getPeriodResults(Carbon $periodStart, int $offset, int $limit, array $excludedGroupIds): array
    {
        return $this->publicResults($periodStart, $offset, $limit, $excludedGroupIds);
    }

    private function publicResults(?Carbon $start, int $offset, int $limit, array $excluded): array
    {
        $weights = $this->pointService->buildPointsCaseSql();
        $query = PublicLeaderboard::query()->selectRaw('user_id, SUM('.$weights['sql'].') as public_points', $weights['bindings'])
            ->groupBy('user_id')->havingRaw('SUM('.$weights['sql'].') > 0', $weights['bindings'])
            ->orderByDesc('public_points')->orderBy('user_id');
        if ($start) $query->where('created_at', '>=', $start);
        if ($excluded) $query->whereNotIn('user_id', fn ($query) => $query->select('user_id')->from('group_user')->whereIn('group_id', $excluded));
        $total = (clone $query)->getQuery()->getCountForPagination(['user_id']);
        $rows = $query->offset($offset)->limit($limit)->get();
        $users = User::query()->whereIn('id', $rows->pluck('user_id'))->withCount([
            'posts as public_comment_count' => fn ($query) => $query->where('type', 'comment')->where('is_anonymous', false)->where('is_private', false)->whereNull('hidden_at'),
            'discussions as public_discussion_count' => fn ($query) => $query->where('is_anonymous', false)->where('is_private', false)->whereNull('hidden_at'),
        ])->get()->keyBy('id');
        $entries = [];
        foreach ($rows as $index => $row) {
            if ($user = $users->get($row->user_id)) $entries[] = new LeaderboardEntryData($user->id, (int) $row->public_points, $offset + $index + 1, $user);
        }

        return ['entries' => $entries, 'total' => $total];
    }

    protected function serializeUser(User $user): array
    {
        $serialized = parent::serializeUser($user);
        $serialized['attributes']['commentCount'] = (int) $user->public_comment_count;
        $serialized['attributes']['discussionCount'] = (int) $user->public_discussion_count;

        return $serialized;
    }
}
