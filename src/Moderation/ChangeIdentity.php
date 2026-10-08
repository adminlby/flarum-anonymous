<?php

namespace AdminLby\Anonymous\Moderation;

use AdminLby\Anonymous\EditHistory\RevisionIdentity;
use AdminLby\Anonymous\Event\IdentityChanged;
use AdminLby\Anonymous\Privacy;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\AbstractEventPost;
use Flarum\Post\Post;
use Flarum\Post\PostRepository;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;

final class ChangeIdentity
{
    public function __construct(private PostRepository $posts, private Dispatcher $events, private ExtensionManager $extensions,
        private SynchronizeReferences $references)
    {
    }

    public function __invoke(Post $target, User $actor, bool $anonymous): array
    {
        return $target->getConnection()->transaction(function () use ($target, $actor, $anonymous): array {
            // Consistent locking order for concurrent changes in one topic.
            $discussion = Discussion::query()->whereKey($target->discussion_id)->lockForUpdate()->firstOrFail();
            $post = $this->posts->queryVisibleTo($actor)->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $actor->assertCan('changeAnonymousIdentity', $post);
            $wasAnonymous = Privacy::post($post);
            $first = (int) $discussion->first_post_id === (int) $post->id
                || ($discussion->first_post_id === null && (int) $post->number === 1);
            if ($wasAnonymous === $anonymous) {
                return ['isAnonymous' => $anonymous, 'discussionId' => (string) $discussion->id,
                    'discussionIsAnonymous' => Privacy::discussion($discussion), 'changed' => false];
            }

            $related = [$post];
            $authorId = Privacy::authorId($post);
            if ($first && $authorId !== null) {
                foreach ($discussion->posts()->where('type', '!=', 'comment')->get() as $eventPost) {
                    if ($eventPost instanceof AbstractEventPost && Privacy::authorId($eventPost) === $authorId) $related[] = $eventPost;
                }
            }

            foreach ($related as $relatedPost) {
                $owner = Privacy::authorId($relatedPost);
                $values = ['is_anonymous' => $anonymous];
                if ($anonymous && $relatedPost->anonymous_author_id === null) $values['anonymous_author_id'] = $relatedPost->user_id;
                if ($owner !== null && $relatedPost->edited_user_id === $owner) $values['last_edit_is_anonymous'] = $anonymous;
                Post::query()->allTypes()->whereKey($relatedPost->id)->update($values);
                $relatedPost->forceFill($values);
                $this->history($relatedPost, $anonymous);
            }
            if ($first) {
                Discussion::query()->whereKey($discussion->id)->update(['is_anonymous' => $anonymous]);
                $discussion->is_anonymous = $anonymous;
            }

            $ids = array_map(fn (Post $relatedPost) => (int) $relatedPost->id, $related);
            $this->references->synchronize($ids, $anonymous);
            // Event failure rolls the whole change back. No post-created/edit
            // events are emitted: changing identity sends no notification/mail.
            $this->events->dispatch(new IdentityChanged($actor, $post, $wasAnonymous, $anonymous, $ids));

            return ['isAnonymous' => $anonymous, 'discussionId' => (string) $discussion->id,
                'discussionIsAnonymous' => Privacy::discussion($discussion), 'changed' => true];
        });
    }

    private function history(Post $post, bool $anonymous): void
    {
        $owner = Privacy::authorId($post);
        if ($owner === null) return;
        // A moderator changes this author's history, not independently chosen
        // anonymous identities of other editors.
        RevisionIdentity::query()->where('post_id', $post->id)->where('actor_reference', $owner)
            ->update(['is_anonymous' => $anonymous]);
        if (! $this->extensions->isEnabled('huseyinfiliz-diff')) return;
        foreach (\HuseyinFiliz\Diff\Models\Diff::query()->where('post_id', $post->id)
            ->where(fn ($query) => $query->where('actor_id', $owner)->orWhere('revision', 0))->get() as $revision) {
            RevisionIdentity::query()->create(['diff_id' => $revision->id, 'post_id' => $post->id,
                'actor_reference' => $owner, 'is_anonymous' => $anonymous]);
        }
    }
}
