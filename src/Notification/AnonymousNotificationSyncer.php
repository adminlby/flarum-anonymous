<?php

namespace AdminLby\Anonymous\Notification;

use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\NotificationSyncer;

final class AnonymousNotificationSyncer extends NotificationSyncer
{
    public function __construct(private ProtectBlueprint $protect)
    {
    }

    public function sync(BlueprintInterface $blueprint, array $users): void
    {
        if ($safe = ($this->protect)($blueprint)) {
            parent::sync($safe, $users);
        }
    }

    public function delete(BlueprintInterface $blueprint): void
    {
        $safe = ($this->protect)($blueprint, true);
        if ($safe) {
            parent::delete($safe);
        }
        // Also remove matching legacy rows, without ever delivering them.
        if ($safe !== $blueprint) {
            parent::delete($blueprint);
        }
    }

    public function restore(BlueprintInterface $blueprint): void
    {
        if ($safe = ($this->protect)($blueprint)) {
            parent::restore($safe);
        }
    }
}
