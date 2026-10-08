<?php

use AdminLby\Anonymous\Access\AnonymousPolicy;
use AdminLby\Anonymous\Access;
use AdminLby\Anonymous\Compatibility;
use AdminLby\Anonymous\AnonymousServiceProvider;
use AdminLby\Anonymous\Api;
use AdminLby\Anonymous\Event\AuthorRevealed;
use AdminLby\Anonymous\Event\EditorRevealed;
use AdminLby\Anonymous\Event\IdentityChanged;
use AdminLby\Anonymous\EditHistory;
use AdminLby\Anonymous\Formatter;
use AdminLby\Anonymous\Listener\SaveAnonymity;
use AdminLby\Anonymous\Privacy;
use AdminLby\Anonymous\Search;
use AdminLby\Anonymous\Settings;
use Flarum\Api\Resource;
use Flarum\Api\Schema;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Extend;
use Flarum\Notification\Notification;
use Flarum\Post\Filter\PostSearcher;
use Flarum\Post\Post;
use Flarum\Search\Database\DatabaseSearchDriver;

$post = (new Extend\ApiResource(Resource\PostResource::class))->fields(Api\PostFields::class);
foreach (['user', 'editedUser', 'hiddenUser'] as $relation) {
    $post->field($relation, fn (Schema\Relationship\ToOne $field) => $field->get(fn (Post $post, Context $context) => Privacy::post($post)
        || ($relation === 'editedUser' && $post->last_edit_is_anonymous) ? null : $context->resource->getValue($post, $field, $context)));
}
$post->field('ipAddress', fn (Schema\Str $field) => $field->get(fn (Post $post) => Privacy::post($post) ? null : $post->ip_address));

$discussion = (new Extend\ApiResource(Resource\DiscussionResource::class))->fields(Api\DiscussionFields::class)
    // A distinct count of real participants is an author-correlation oracle:
    // an anonymous reply would reveal whether its author already took part.
    // Core does not display this field. Keep its database value for policies.
    ->field('participantCount', fn (Schema\Integer $field) => $field->visible(false))
    ->field('user', fn (Schema\Relationship\ToOne $field) => $field->get(fn (Discussion $discussion, Context $context) => Privacy::discussion($discussion) ? null : $context->resource->getValue($discussion, $field, $context)))
    ->field('lastPostedUser', fn (Schema\Relationship\ToOne $field) => $field->get(fn (Discussion $discussion, Context $context) => Privacy::lastPost($discussion) ? null : $context->resource->getValue($discussion, $field, $context)))
    ->field('hideUser', fn (Schema\Relationship\ToOne $field) => $field->get(fn (Discussion $discussion, Context $context) => Privacy::discussion($discussion) ? null : $context->resource->getValue($discussion, $field, $context)))
    ->endpoint([Endpoint\Index::class, Endpoint\Show::class, Endpoint\Create::class, Endpoint\Update::class], fn (Endpoint\Endpoint $endpoint) => $endpoint->eagerLoad(['lastPost.user.groups', 'firstPost.user.groups']));

$post->endpoint([Endpoint\Index::class, Endpoint\Show::class, Endpoint\Create::class, Endpoint\Update::class], fn (Endpoint\Endpoint $endpoint) => $endpoint->eagerLoad(['discussion.lastPost', 'user.groups', 'editedUser.groups', 'hiddenUser.groups']));

return [
    (new Extend\Frontend('forum'))->js(__DIR__.'/js/dist/forum.js')->css(__DIR__.'/less/forum.less'),
    (new Extend\Frontend('admin'))->js(__DIR__.'/js/dist/admin.js'),
    new Extend\Locales(__DIR__.'/locale'),
    (new Extend\View())->namespace('adminlby-anonymous', __DIR__.'/views')->extendNamespace('flarum.forum', __DIR__.'/views/forum'),
    // Database defaults cover new models. Do not install model defaults: a
    // linkage-only model must distinguish an unloaded flag from false.
    (new Extend\Model(Post::class))->cast('is_anonymous', 'boolean')->cast('anonymous_author_id', 'integer')->cast('last_edit_is_anonymous', 'boolean'),
    (new Extend\Model(Discussion::class))->cast('is_anonymous', 'boolean'),
    (new Extend\Settings())->default(Settings::PREFIX.'enabled', true)->default(Settings::PREFIX.'discussions', true)
        ->default(Settings::PREFIX.'replies', true)->default(Settings::PREFIX.'quotes', true)->default(Settings::PREFIX.'other', true)
        ->default(Settings::PREFIX.'mode', 'ask')->default(Settings::PREFIX.'showOwn', true)->default(Settings::PREFIX.'editHistoryChoice', false),
    (new Extend\Policy())->globalPolicy(AnonymousPolicy::class)->modelPolicy(Discussion::class, AnonymousPolicy::class)->modelPolicy(Post::class, AnonymousPolicy::class),
    (new Extend\Event())->listen(Flarum\Discussion\Event\Saving::class, SaveAnonymity::class)->listen(Flarum\Post\Event\Saving::class, SaveAnonymity::class),
    (new Extend\ServiceProvider())->register(AnonymousServiceProvider::class),
    $post,
    $discussion,
    (new Extend\ApiResource(Resource\ForumResource::class))->fields(Api\ForumFields::class),
    (new Extend\ApiResource(Resource\NotificationResource::class))
        ->field('fromUser', fn (Schema\Relationship\ToOne $field) => $field->get(fn (Notification $notification) => Privacy::notificationSender($notification)))
        ->field('content', fn (Schema\Arr $field) => $field->get(fn (Notification $notification) => Privacy::notification($notification)
            ? array_intersect_key($notification->data ?? [], array_flip(['postNumber', 'replyNumber', 'discussionId'])) + ['isAnonymous' => true]
            : array_diff_key($notification->data ?? [], ['isAnonymous' => true])))
        ->fields(fn () => [Schema\Boolean::make('isAnonymous')->get(fn (Notification $notification) => Privacy::notification($notification))]),
    (new Extend\ApiResource(Resource\UserResource::class))
        ->field(['commentCount', 'discussionCount'], Api\PublicUserCounts::class)
        ->sort(['commentCount', 'discussionCount'], fn ($sort) => new Search\PublicUserCountSort($sort->name)),
    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->replaceFilter(PostSearcher::class, Flarum\Post\Filter\AuthorFilter::class, Search\AnonymousPostAuthorFilter::class)
        ->replaceFilter(DiscussionSearcher::class, Flarum\Discussion\Search\Filter\AuthorFilter::class, Search\AnonymousDiscussionAuthorFilter::class)
        ->addMutator(Flarum\User\Search\UserSearcher::class, Search\PublicUserCountMutator::class),
    (new Extend\Routes('api'))->post('/anonymous/posts/{id}/reveal', 'anonymous.reveal', Api\RevealAuthorController::class),
    (new Extend\Routes('api'))->post('/anonymous/posts/{id}/identity', 'anonymous.change-identity', Api\ChangeIdentityController::class),
    // Optional dependencies are extended after their own registrations.
    ...Compatibility\Extenders::make(),
    (new Extend\Conditional())->whenExtensionEnabled('flarum-mentions', fn () => [
        (new Extend\Formatter())->render(Formatter\AnonymousMentions::class)->unparse(Formatter\AnonymousUnparse::class),
        (new Extend\ApiResource(Resource\PostResource::class))
            ->endpoint([Endpoint\Index::class, Endpoint\Show::class, Endpoint\Create::class, Endpoint\Update::class], fn (Endpoint\Endpoint $endpoint) => $endpoint->eagerLoad(['mentionsPosts.user', 'mentionsPosts.discussion', 'mentionsUsers', 'mentionsGroups'])),
    ]),
    (new Extend\Conditional())->whenExtensionEnabled('huseyinfiliz-diff', function () {
        $diff = (new Extend\ApiResource(HuseyinFiliz\Diff\Api\Resource\DiffResource::class))->fields(Api\EditHistoryFields::class)
            ->endpoint([Endpoint\Index::class, Endpoint\Show::class], fn (Endpoint\Endpoint $endpoint) => $endpoint->eagerLoad(['post.anonymousEditingActors', 'anonymousIdentity']));
        foreach (['actor', 'deletedUser', 'rollbackedUser'] as $relation) {
            $diff->field($relation, fn (Schema\Relationship\ToOne $field) => $field->get(fn (HuseyinFiliz\Diff\Models\Diff $revision, Context $context) =>
                ($relation === 'actor' ? EditHistory\RevisionPrivacy::anonymous($revision) : EditHistory\RevisionPrivacy::hidesAction($revision, $relation))
                    ? null : $context->resource->getValue($revision, $field, $context)));
        }

        return [
            (new Extend\Model(Post::class))->relationship('anonymousEditingActors', fn (Post $post) =>
                $post->hasMany(EditHistory\RevisionIdentity::class, 'post_id')->where('diff_id', 0)->where('is_anonymous', true)),
            (new Extend\Model(HuseyinFiliz\Diff\Models\Diff::class))->relationship('anonymousIdentity', fn ($revision) =>
                $revision->hasOne(EditHistory\RevisionIdentity::class, 'diff_id')->latestOfMany()),
            (new Extend\ServiceProvider())->register(EditHistory\DiffServiceProvider::class),
            (new Extend\Event())->listen(Flarum\Post\Event\Saving::class, EditHistory\SaveEditIdentity::class),
            (new Extend\ApiResource(Resource\PostResource::class))
                ->fields(Api\EditHistoryInputFields::class)
                ->field('diff', fn (Schema\Relationship\ToMany $field) => $field->visible(fn (Post $post, Context $context) => $context->getActor()->can('viewEditHistory'))
                    ->scope(fn ($relation) => $relation->with(['post.anonymousEditingActors', 'anonymousIdentity']))),
            $diff,
            (new Extend\Routes('api'))->post('/anonymous/diff/{id}/reveal', 'anonymous.edit-reveal', Api\RevealEditorController::class),
        ];
    }),
    (new Extend\Conditional())->whenExtensionEnabled('flarum-audit', fn () => [
        (new Extend\ModelVisibility(Flarum\Audit\AuditLog::class))->scope(Access\AnonymousAuditScope::class),
        (new Flarum\Audit\Extend\Audit())->listen(AuthorRevealed::class, 'anonymous.author_revealed', fn (AuthorRevealed $event) => [
            'post_id' => $event->post->id, 'discussion_id' => $event->post->discussion_id,
            'user_id' => Privacy::authorId($event->post), 'revealed_by' => $event->actor->id,
        ]),
        (new Flarum\Audit\Extend\Audit())->listen(EditorRevealed::class, 'anonymous.edit_author_revealed', fn (EditorRevealed $event) => [
            'diff_id' => $event->revision->id, 'post_id' => $event->revision->post_id,
            'user_id' => $event->editorId, 'revealed_by' => $event->actor->id,
        ]),
        (new Flarum\Audit\Extend\Audit())->listen(IdentityChanged::class, 'anonymous.identity_changed', fn (IdentityChanged $event) => [
            'post_id' => $event->post->id, 'discussion_id' => $event->post->discussion_id,
            'user_id' => Privacy::authorId($event->post), 'changed_by' => $event->actor->id,
            'was_anonymous' => $event->wasAnonymous, 'is_anonymous' => $event->isAnonymous,
        ]),
    ]),
];
