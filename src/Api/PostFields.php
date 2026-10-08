<?php

namespace AdminLby\Anonymous\Api;

use AdminLby\Anonymous\Privacy;
use AdminLby\Anonymous\CreationContext;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Post\Post;

final class PostFields
{
    public function __construct(private CreationContext $creation)
    {
    }

    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('isAnonymous')->writableOnCreate()->default(fn () => false)->get(fn (Post $post) => Privacy::post($post))
                // rc.8 exposes the nested first-post marker on its API Context.
                // Capture it without inferring request type from database rows.
                ->set(fn (Post $post, bool $_value, Context $context) => $this->creation->mark($post, (bool) $context->internal('isFirstPost'))),
            Schema\Boolean::make('isMine')->get(fn (Post $post, Context $context) => Privacy::post($post)
                && $context->getActor()->exists && $post->user_id === $context->getActor()->id),
            Schema\Boolean::make('canRevealAnonymousAuthor')->get(fn (Post $post, Context $context) => $context->getActor()->can('revealAnonymousAuthor', $post)),
            Schema\Boolean::make('lastEditIsAnonymous')->get(fn (Post $post) => (bool) $post->last_edit_is_anonymous),
            Schema\Boolean::make('canChangeAnonymousIdentity')->get(fn (Post $post, Context $context) => $context->getActor()->can('changeAnonymousIdentity', $post)),
        ];
    }
}
