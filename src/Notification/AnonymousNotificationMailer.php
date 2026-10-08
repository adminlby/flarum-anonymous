<?php

namespace AdminLby\Anonymous\Notification;

use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\Notification\NotificationMailer;
use Flarum\User\User;

final class AnonymousNotificationMailer extends NotificationMailer
{
    public function send(MailableInterface&BlueprintInterface $blueprint, User $user): void
    {
        // Also protects jobs queued before this extension was enabled.
        $safe = (new ProtectBlueprint())($blueprint);
        if ($safe instanceof MailableInterface) {
            parent::send($safe, $user);
        }
    }
}
