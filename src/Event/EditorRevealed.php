<?php

namespace AdminLby\Anonymous\Event;

use Flarum\User\User;
use HuseyinFiliz\Diff\Models\Diff;

final class EditorRevealed
{
    public function __construct(public User $actor, public Diff $revision, public ?int $editorId)
    {
    }
}
