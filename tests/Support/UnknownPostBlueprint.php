<?php

namespace AdminLby\Anonymous\Tests\Support;

use Flarum\Database\AbstractModel;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Post\Post;
use Flarum\User\User;

final class UnknownPostBlueprint implements BlueprintInterface
{
    public function __construct(private Post $post) {}
    public static function getType(): string { return 'anonymousTestUnknown'; }
    public static function getSubjectModel(): string { return Post::class; }
    public function getSubject(): ?AbstractModel { return $this->post; }
    public function getFromUser(): ?User { return $this->post->user; }
    public function getData(): array { return ['username' => $this->post->user?->username]; }
}
