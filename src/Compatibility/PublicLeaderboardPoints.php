<?php

namespace AdminLby\Anonymous\Compatibility;

use Flarum\Api\Context;
use Flarum\Api\Schema\Integer;
use HuseyinFiliz\Leaderboard\Service\PointService;
use Illuminate\Database\Eloquent\Builder;

final class PublicLeaderboardPoints
{
    public function __construct(private PointService $points)
    {
    }

    public function __invoke(Integer $field): Integer
    {
        $bindings = $this->points->buildPointsCaseSql()['bindings'];
        $counts = [];
        for ($i = 0; $i < count($bindings); $i += 2) {
            $reason = $bindings[$i];
            $weight = (int) $bindings[$i + 1];
            if ($weight === 0) continue;
            $name = 'anonymousLeaderboardCount_'. $reason;
            $counter = Integer::make($name)->property(\Illuminate\Support\Str::snake($name))->countRelation('anonymousLeaderboard_'.$reason, function (Builder $query) use ($reason): void {
                PublicLeaderboard::scope($query)->where('reason', $reason);
            });
            $counts[] = [$weight, $counter];
        }
        // Use rc.8's supported countRelation buffer. Private counters are not
        // registered as API fields; all users' counts batch once per reason.
        return $field->get(function ($user, Context $context) use ($counts) {
            $values = array_map(fn ($count) => [$count[0], $context->resource->getValue($user, $count[1], $context)], $counts);
            return function () use ($values): int {
                $total = 0;
                foreach ($values as [$weight, $value]) $total += $weight * (is_callable($value) ? (int) $value() : (int) $value);
                return $total;
            };
        });
    }
}
