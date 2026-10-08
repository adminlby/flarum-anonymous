<?php

namespace AdminLby\Anonymous\Api;

use AdminLby\Anonymous\EditHistory\EditEligibility;
use Flarum\Api\Schema;
use Flarum\Post\Post;

final class EditHistoryInputFields
{
    public function __construct(private EditEligibility $eligible)
    {
    }

    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('editHistoryAnonymous')->writableOnUpdate()
                ->get(fn (Post $post) => (bool) $post->last_edit_is_anonymous)->set(fn () => null),
            Schema\Boolean::make('canChooseEditHistoryIdentity')->get(fn (Post $post) => ($this->eligible)($post)),
        ];
    }
}
