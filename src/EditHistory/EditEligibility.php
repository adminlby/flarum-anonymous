<?php

namespace AdminLby\Anonymous\EditHistory;

use AdminLby\Anonymous\Settings;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;

final class EditEligibility
{
    public function __construct(private Settings $settings, private SettingsRepositoryInterface $repository)
    {
    }

    public function __invoke(Post $post): bool
    {
        return $post instanceof CommentPost && $this->settings->enabled('editHistoryChoice')
            && (! filter_var($this->repository->get('huseyinfiliz-diff.mainPostOnly', false), FILTER_VALIDATE_BOOLEAN) || (int) $post->number === 1);
    }
}
