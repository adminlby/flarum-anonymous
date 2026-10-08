<?php

namespace AdminLby\Anonymous\Tests\Support;

use Flarum\Post\Post;

// A third-party user submission using the official Post type and Saving event.
final class SubmissionPost extends Post
{
    public static string $type = 'anonymousTestSubmission';
}
