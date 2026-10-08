<?php

namespace AdminLby\Anonymous\EditHistory;

use AdminLby\Anonymous\Privacy;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Saving;
use Illuminate\Support\Arr;

final class SaveEditIdentity
{
    public function __construct(private EditEligibility $eligible, private EditContext $context, private TranslatorInterface $translator)
    {
    }

    public function __invoke(Saving $event): void
    {
        $post = $event->post;
        if (! $post->exists || ! $post instanceof CommentPost) return;
        $requested = Arr::get($event->data, 'attributes.editHistoryAnonymous');
        if ($requested !== null && ! is_bool($requested)) {
            throw new ValidationException(['editHistoryAnonymous' => $this->translator->trans('adminlby-anonymous.api.invalid_identity')]);
        }
        $locked = RevisionPrivacy::originalAuthor($post, $event->actor->id);
        if ($locked && $requested === false) {
            throw new ValidationException(['editHistoryAnonymous' => $this->translator->trans('adminlby-anonymous.api.edit_original_author_locked')]);
        }
        if ($requested !== null && ! $locked && ! ($this->eligible)($post)) {
            throw new ValidationException(['editHistoryAnonymous' => $this->translator->trans('adminlby-anonymous.api.edit_choice_disabled')]);
        }
        if (! $post->isDirty('content')) return;

        $anonymous = $locked || ($requested ?? Privacy::post($post));
        if ($anonymous && ! Privacy::post($post)) {
            // Reserve privacy before core saves the edited post. If Diff fails
            // partway through creating a revision, its exposed actor must not
            // turn a requested anonymous edit into a named one. Unmapped rows
            // by this actor default to anonymous; mapped named rows still work.
            RevisionIdentity::query()->create(['diff_id' => 0, 'post_id' => $post->id,
                'actor_reference' => $event->actor->id, 'is_anonymous' => true]);
        }
        $post->last_edit_is_anonymous = $anonymous;
        $this->context->remember($post, $event->actor->id, $anonymous);
    }
}
