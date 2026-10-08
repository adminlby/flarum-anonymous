<?php

namespace AdminLby\Anonymous\Compatibility;

use AdminLby\Anonymous\Privacy;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource;
use Flarum\Api\Schema;
use Flarum\Extend;

/** Shared by the current extension and the prepared production rollback. */
final class Extenders
{
    public static function make(): array
    {
        return [
            (new Extend\Conditional())->whenExtensionEnabled('fof-seo', fn () => [
                (new Extend\Event())->listen(\FoF\Seo\Event\PreparingPageMeta::class, ProtectSeo::class),
            ]),
            (new Extend\Conditional())->whenExtensionEnabled('fof-polls', fn () => [
                (new Extend\ApiResource(\FoF\Polls\Api\Resource\PollResource::class))
                    ->field('user', fn (Schema\Relationship\ToOne $field) => $field->get(fn (\FoF\Polls\Poll $poll, Context $context) => PollPrivacy::anonymous($poll)
                        ? null : $context->resource->getValue($poll, $field, $context)))
                    ->fields(fn () => [Schema\Boolean::make('isAnonymous')->get(fn (\FoF\Polls\Poll $poll) => PollPrivacy::anonymous($poll))])
                    ->endpoint([Endpoint\Index::class, Endpoint\Show::class, Endpoint\Create::class, Endpoint\Update::class], fn (Endpoint\Endpoint $endpoint) => $endpoint->eagerLoad(['post'])),
                (new Extend\ApiResource(\FoF\Polls\Api\Resource\PollVoteResource::class))
                    ->fields(fn () => [Schema\Boolean::make('isAnonymous')->get(self::anonymousVote(...))])
                    ->field('user', fn (Schema\Relationship\ToOne $field) => $field->get(fn (\FoF\Polls\PollVote $vote, Context $context) =>
                        self::anonymousVote($vote) ? null : $context->resource->getValue($vote, $field, $context))),
            ]),
            (new Extend\Conditional())->whenExtensionEnabled('fof-split', fn () => [
                (new Extend\Event())->listen(\FoF\Split\Events\DiscussionWasSplit::class, InheritSplitIdentity::class),
            ]),
            (new Extend\Conditional())->whenExtensionEnabled('huseyinfiliz-leaderboard', function () {
                $user = new Extend\Model(\Flarum\User\User::class);
                foreach (PublicLeaderboard::REASONS as $reason) $user->relationship('anonymousLeaderboard_'.$reason,
                    fn ($user) => $user->hasMany(\HuseyinFiliz\Leaderboard\Model\LeaderboardPoint::class, 'user_id'));
                return [
                    (new Extend\ServiceProvider())->register(LeaderboardServiceProvider::class), $user,
                    (new Extend\ApiResource(Resource\UserResource::class))->field('leaderboardPoints', PublicLeaderboardPoints::class),
                ];
            }),
        ];
    }

    private static function anonymousVote(\FoF\Polls\PollVote $vote): bool
    {
        $poll = $vote->poll;
        $post = $poll?->post;
        return $poll && PollPrivacy::anonymous($poll) && (! $post || $vote->user_id === Privacy::authorId($post));
    }
}
