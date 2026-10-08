<?php

namespace AdminLby\Anonymous\Notification;

use AdminLby\Anonymous\Privacy;
use Flarum\Discussion\Discussion;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Post\Post;

final class ProtectBlueprint
{
    public function __invoke(BlueprintInterface $blueprint, bool $forRemoval = false): ?BlueprintInterface
    {
        if ($blueprint instanceof AnonymousBlueprint) {
            return $forRemoval || Post::query()->whereKey($blueprint->source->id)->exists() ? $blueprint : null;
        }

        $subject = $blueprint->getSubject();
        $data = $blueprint->getData() ?? [];
        $data = is_array($data) ? $data : [];
        $type = $blueprint::getType();
        $source = null;
        $postBased = is_a($blueprint::getSubjectModel(), Post::class, true)
            || in_array($type, ['newPost', 'postMentioned', 'userMentioned', 'groupMentioned', 'discussionRenamed'], true);

        // Resolve persisted source posts; do not trust a stale queued model.
        if ($type === 'postMentioned') {
            $source = Post::query()->where('discussion_id', $data['discussionId'] ?? 0)->where('number', $data['replyNumber'] ?? 0)->first();
        } elseif ($subject instanceof Post) {
            $source = Post::query()->find($subject->id);
        } elseif ($subject instanceof Discussion && isset($data['postNumber'])) {
            $postBased = true;
            $source = $subject->posts()->where('number', $data['postNumber'])->first();
        }

        // Deletion has already removed the source row. Its cached model may
        // still be used to match and delete notifications, never to deliver.
        if (! $source && $forRemoval) {
            $properties = get_object_vars($blueprint);
            $cached = $type === 'postMentioned' ? ($properties['reply'] ?? null) : ($properties['post'] ?? $subject);
            $source = $cached instanceof Post ? $cached : null;
        }
        if ($postBased && ! $source) {
            return null; // A deleted source cannot prove that an old sender was public.
        }

        if (! $source || ! Privacy::post($source)) {
            // Unknown discussion notifications cannot identify an anonymous
            // starter via an undocumented blueprint payload or email template.
            if ($subject instanceof Discussion && Privacy::discussion($subject)
                && $blueprint->getFromUser()?->id === $subject->user_id) {
                return null;
            }

            return $blueprint;
        }

        $class = match ($type) {
            'newPost' => NewPostBlueprint::class,
            'postMentioned' => PostMentionedBlueprint::class,
            'userMentioned' => UserMentionedBlueprint::class,
            'groupMentioned' => GroupMentionedBlueprint::class,
            'discussionRenamed' => DiscussionRenamedBlueprint::class,
            default => null,
        };
        // Third-party post notification types are fail-closed until integrated.
        if (! $class || ! $subject) {
            return null;
        }

        $safeData = array_intersect_key($data, array_flip(['postNumber', 'replyNumber', 'discussionId']));

        return new $class($subject, $source, $safeData);
    }
}
