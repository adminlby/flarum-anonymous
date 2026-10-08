<?php

namespace AdminLby\Anonymous\Listener;

use AdminLby\Anonymous\Settings;
use AdminLby\Anonymous\CreationContext;
use Flarum\Discussion\Event\Saving as DiscussionSaving;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Saving as PostSaving;
use Illuminate\Support\Arr;

final class SaveAnonymity
{
    public function __construct(private Settings $settings, private TranslatorInterface $translator, private CreationContext $creation)
    {
    }

    public function __invoke(DiscussionSaving|PostSaving $event): void
    {
        $model = $event instanceof DiscussionSaving ? $event->discussion : $event->post;
        $requested = Arr::get($event->data, 'attributes.isAnonymous');

        if ($model->exists) {
            if ($requested !== null && $requested !== (bool) $model->getOriginal('is_anonymous')) {
                throw new ValidationException(['isAnonymous' => $this->translator->trans('adminlby-anonymous.api.identity_immutable')]);
            }

            return;
        }

        if ($requested !== null && ! is_bool($requested)) {
            throw new ValidationException(['isAnonymous' => $this->translator->trans('adminlby-anonymous.api.invalid_identity')]);
        }

        if ($event instanceof DiscussionSaving) {
            $model->is_anonymous = $requested ?? false;
            if ($model->is_anonymous) {
                $event->actor->assertCan('anonymousStartDiscussion');
                $model->user_id = $event->actor->id;
            }

            return;
        }

        // rc.8's nested first-post request omits custom attributes. Inherit the
        // persisted discussion flag only for the official isFirstPost context.
        $this->creation->markUserSubmission($model);
        $discussion = $model->discussion;
        $first = $this->creation->isFirstPost($model);
        $model->is_anonymous = $first ? (bool) $discussion->is_anonymous : ($requested ?? false);

        if (! $model->is_anonymous) {
            return;
        }

        if ($first) {
            $event->actor->assertCan('anonymousStartDiscussion');
        } else {
            $content = Arr::get($event->data, 'attributes.content', $model->getAttributes()['content'] ?? '');
            $kind = $model instanceof CommentPost ? ($this->isQuote($content) ? 'quotes' : 'replies') : 'other';
            $event->actor->assertPermission($this->settings->enabled($kind));
            $event->actor->assertCan('anonymousReply', $discussion);
        }

        $model->user_id = $event->actor->id;
    }

    private function isQuote(string|array $content): bool
    {
        return is_string($content) && (str_contains($content, '<POSTMENTION') || str_contains($content, '<QUOTE') || preg_match('/(^|\n)\s*>|@"[^\n]+"#p?\d+|\[quote\b/iu', $content));
    }
}
