<?php

namespace AdminLby\Anonymous\Api;

use AdminLby\Anonymous\Settings;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Extension\ExtensionManager;

final class ForumFields
{
    public function __construct(private Settings $settings, private ExtensionManager $extensions)
    {
    }

    public function __invoke(): array
    {
        $fields = [
            Schema\Str::make('anonymousMode')->get(fn () => $this->settings->mode()),
            Schema\Boolean::make('anonymousEditHistoryAvailable')->get(fn () => $this->extensions->isEnabled('huseyinfiliz-diff')),
            Schema\Boolean::make('anonymousEditHistoryChoice')->get(fn () => $this->extensions->isEnabled('huseyinfiliz-diff') && $this->settings->enabled('editHistoryChoice')),
            Schema\Boolean::make('canAnonymousStartDiscussion')->get(fn ($forum, Context $context) => $context->getActor()->can('anonymousStartDiscussion')),
            Schema\Boolean::make('canAnonymousReply')->get(fn ($forum, Context $context) => $context->getActor()->exists
                && $this->settings->boolean('enabled') && $context->getActor()->hasPermission('discussion.anonymousReply')),
        ];
        foreach (['enabled', 'discussions', 'replies', 'quotes', 'other', 'showOwn'] as $key) {
            $fields[] = Schema\Boolean::make('anonymous'.ucfirst($key))->get(fn () => $this->settings->boolean($key));
        }

        return $fields;
    }
}
