<?php

// Emergency guard for the previous extension version. prepare-rollback.php
// retains the compatibility bundle under its existing PSR-4 namespace.
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\PostResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Post\Post;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;

if (! class_exists('AnonymousRollbackPrivacyProvider', false)) {
final class AnonymousRollbackPrivacyProvider extends AbstractServiceProvider
{
    public function boot(Container $container, ConnectionInterface $db, ExtensionManager $extensions): void
    {
        foreach (\AdminLby\Anonymous\Compatibility\Extenders::make() as $extender) $extender->extend($container);
        (new Extend\ApiResource(\Flarum\Api\Resource\NotificationResource::class))
            ->field('fromUser', fn (Schema\Relationship\ToOne $field) => $field->get(\AdminLby\Anonymous\Privacy::notificationSender(...)))
            ->field('content', fn (Schema\Arr $field) => $field->get(fn ($notification) => \AdminLby\Anonymous\Privacy::notification($notification)
                ? array_intersect_key($notification->data ?? [], array_flip(['postNumber', 'replyNumber', 'discussionId'])) + ['isAnonymous' => true]
                : array_diff_key($notification->data ?? [], ['isAnonymous' => true])))
            ->fields(fn () => [Schema\Boolean::make('isAnonymous')->get(\AdminLby\Anonymous\Privacy::notification(...))])
            ->extend($container);
        $schema = $db->getSchemaBuilder();
        $hasMetadata = $schema->hasTable('anonymous_edit_history');
        $hasLastEdit = $schema->hasColumn('posts', 'last_edit_is_anonymous');
        $postFlags = [];
        $postPrivacy = function (int $id) use ($db, &$postFlags): ?object {
            if (! array_key_exists($id, $postFlags)) $postFlags[$id] = $db->table('posts')->find($id);
            return $postFlags[$id];
        };
        (new Extend\ApiResource(PostResource::class))->field('editedUser', fn (Schema\Relationship\ToOne $field) => $field->get(
            function (Post $post, Context $context) use ($postPrivacy, $hasLastEdit, $field) {
                $flags = $postPrivacy((int) $post->id);
                return ! $flags || ($flags->is_anonymous ?? true) || ($hasLastEdit && $flags->last_edit_is_anonymous)
                    ? null : $context->resource->getValue($post, $field, $context);
            }
        ))->extend($container);

        if ($extensions->isEnabled('huseyinfiliz-diff')) {
            $identities = [];
            $anonymous = function ($revision) use ($db, $postPrivacy, $hasMetadata, &$identities): bool {
                $post = $postPrivacy((int) $revision->post_id);
                // Emergency rollback conceals every editor of anonymous posts.
                if (! $post || ($post->is_anonymous ?? true) || ! $hasMetadata) return true;
                $id = (int) $revision->id;
                if (! array_key_exists($id, $identities)) $identities[$id] = $db->table('anonymous_edit_history')->where('diff_id', $id)->orderByDesc('id')->first();
                $identity = $identities[$id];
                if ($identity && (int) $identity->post_id === (int) $revision->post_id) return (bool) $identity->is_anonymous;
                return $db->table('anonymous_edit_history')->where('post_id', $revision->post_id)->where('diff_id', 0)
                    ->where('actor_reference', $revision->actor_id)->where('is_anonymous', true)->exists();
            };
            $resource = (new Extend\ApiResource(\HuseyinFiliz\Diff\Api\Resource\DiffResource::class))
                ->fields(fn () => [Schema\Boolean::make('isAnonymous')->get($anonymous)]);
            foreach (['actor', 'deletedUser', 'rollbackedUser'] as $relation) {
                $resource->field($relation, fn (Schema\Relationship\ToOne $field) => $field->get(fn ($revision, Context $context) =>
                    $anonymous($revision) ? null : $context->resource->getValue($revision, $field, $context)));
            }
            $resource->extend($container);
            (new Extend\ApiResource(PostResource::class))->field('diff', fn (Schema\Relationship\ToMany $field) =>
                $field->visible(fn (Post $post, Context $context) => $context->getActor()->can('viewEditHistory')))->extend($container);
        }
        if ($extensions->isEnabled('flarum-audit')) {
            (new Extend\ModelVisibility(\Flarum\Audit\AuditLog::class))->scope(function ($actor, Builder $query) use ($db, $hasMetadata): void {
                if ($actor->hasPermission('anonymous.reveal')) return;
                $query->whereNotIn('action', ['anonymous.author_revealed', 'anonymous.edit_author_revealed', 'anonymous.identity_changed']);
                foreach (['post_id' => 'posts', 'discussion_id' => 'discussions', 'new_discussion_id' => 'discussions', 'original_discussion_id' => 'discussions'] as $key => $table) {
                    $query->where(fn (Builder $query) => $query->whereNull('payload->'.$key)->orWhereNotIn('payload->'.$key,
                        $db->table($table)->where('is_anonymous', true)->select('id')));
                }
                if ($hasMetadata) $query->where(fn (Builder $query) => $query->whereNull('payload->post_id')->orWhereNotIn('payload->post_id',
                    $db->table('anonymous_edit_history')->where('is_anonymous', true)->select('post_id')));
            })->extend($container);
        }
    }
}
}

return [(new Extend\ServiceProvider())->register(AnonymousRollbackPrivacyProvider::class)];
