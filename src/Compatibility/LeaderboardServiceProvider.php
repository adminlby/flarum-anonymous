<?php

namespace AdminLby\Anonymous\Compatibility;

use Flarum\Foundation\AbstractServiceProvider;
use HuseyinFiliz\Leaderboard\Api\Controller\ListLeaderboardController;

final class LeaderboardServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(ListLeaderboardController::class, PublicLeaderboardController::class);
    }
}
