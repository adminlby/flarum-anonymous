<?php

namespace AdminLby\Anonymous;

use AdminLby\Anonymous\Notification\AnonymousNotificationMailer;
use AdminLby\Anonymous\Notification\AnonymousNotificationSyncer;
use AdminLby\Anonymous\Formatter\SanitizeMentions;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\NotificationMailer;
use Flarum\Notification\NotificationSyncer;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Illuminate\Contracts\Events\Dispatcher;

final class AnonymousServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(CreationContext::class);
        $this->container->bind(NotificationSyncer::class, AnonymousNotificationSyncer::class);
        $this->container->bind(NotificationMailer::class, AnonymousNotificationMailer::class);
    }

    public function boot(): void
    {
        $translator = $this->container->make(TranslatorInterface::class);
        $sanitize = $this->container->make(SanitizeMentions::class);
        $creation = $this->container->make(CreationContext::class);
        // Eloquent dispatches subclass-specific event names for CommentPost
        // and action posts. A wildcard listener covers every registered type.
        $this->container->make(Dispatcher::class)->listen('eloquent.saving: *', function (string $_event, array $payload) use ($translator, $sanitize, $creation): void {
            $post = $payload[0];
            if (! $post instanceof Post) {
                return;
            }
            if ($post->exists && $post->isDirty('is_anonymous') && (bool) $post->is_anonymous !== (bool) $post->getOriginal('is_anonymous')) {
                throw new ValidationException(['isAnonymous' => $translator->trans('adminlby-anonymous.api.identity_immutable')]);
            }
            if ($post->exists && $post->isDirty('anonymous_author_id')) {
                throw new ValidationException(['isAnonymous' => $translator->trans('adminlby-anonymous.api.identity_immutable')]);
            }
            // Core action/event posts can identify the anonymous starter when
            // they rename their discussion. Carry existing anonymity forward.
            if (! $post->exists && ! $creation->isUserSubmission($post) && ! ($post instanceof CommentPost) && $post->discussion
                && Privacy::discussion($post->discussion) && $post->user_id === $post->discussion->user_id) {
                $post->is_anonymous = true;
            }
            if (! $post->exists && $post->is_anonymous) {
                $post->anonymous_author_id = $post->user_id;
            }
            $sanitize($post);
        });
    }
}
