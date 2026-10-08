<?php

namespace AdminLby\Anonymous;

use Flarum\Discussion\Discussion;
use Flarum\Notification\Notification;
use Flarum\Post\Post;

final class Privacy
{
    public static function authorId(Post $post): ?int
    {
        return $post->user_id ?? $post->anonymous_author_id;
    }

    public static function post(Post $post): bool
    {
        // Linkage-only models can lack the flag. Read it rather than trusting a
        // missing attribute to mean public (notably inverse relationships).
        if (! array_key_exists('is_anonymous', $post->getAttributes()) && $post->id) {
            $flag = Post::query()->allTypes()->whereKey($post->id)->value('is_anonymous');
            // A concurrent deletion must not turn a partial anonymous model
            // with an already-loaded author relationship into a named model.
            return $flag === null || (bool) $flag;
        }

        return (bool) $post->is_anonymous;
    }

    public static function discussion(Discussion $discussion): bool
    {
        if (! array_key_exists('is_anonymous', $discussion->getAttributes()) && $discussion->id) {
            $flag = Discussion::query()->whereKey($discussion->id)->value('is_anonymous');
            return $flag === null || (bool) $flag;
        }

        return (bool) $discussion->is_anonymous;
    }

    public static function lastPost(Discussion $discussion): bool
    {
        if ($discussion->last_post_id && ($post = $discussion->lastPost)) {
            return self::post($post);
        }

        // A missing/deleted last-post reference cannot prove that a cached
        // last_posted_user_id was public. Fail closed until core recalculates.
        return $discussion->last_posted_user_id !== null || self::discussion($discussion);
    }

    public static function notification(Notification $notification): bool
    {
        $subject = $notification->subject;
        if ($subject instanceof Post) {
            if ($notification->type === 'postMentioned') {
                $data = $notification->data ?? [];
                $source = Post::query()->where('discussion_id', $data['discussionId'] ?? 0)
                    ->where('number', $data['replyNumber'] ?? 0)->first();
                return ! $source || self::post($source);
            }

            return self::post($subject);
        }
        if ($subject instanceof Discussion) {
            $source = $subject->posts()->where('number', $notification->data['postNumber'] ?? 0)->first();
            return ! $source || self::post($source);
        }

        // Legacy anonymous payloads remain private when their source cannot
        // be resolved; live posts take precedence after a moderator conversion.
        return ($notification->data['isAnonymous'] ?? false) === true;
    }

    public static function notificationSender(Notification $notification): mixed
    {
        if (self::notification($notification)) return null;
        if ($notification->fromUser) return $notification->fromUser;
        if (! in_array($notification->type, ['newPost', 'postMentioned', 'userMentioned', 'groupMentioned', 'discussionRenamed'], true)) return null;
        $subject = $notification->subject;
        $source = $notification->type === 'postMentioned'
            ? Post::query()->where('discussion_id', $notification->data['discussionId'] ?? 0)->where('number', $notification->data['replyNumber'] ?? 0)->first()
            : ($subject instanceof Post ? $subject : ($subject instanceof Discussion
                ? $subject->posts()->where('number', $notification->data['postNumber'] ?? 0)->first() : null));

        return $source?->user;
    }
}
