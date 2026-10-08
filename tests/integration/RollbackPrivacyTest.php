<?php

namespace AdminLby\Anonymous\Tests\integration;

use Flarum\Api\Context;
use Flarum\Api\Resource\PostResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\Post\Post;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;

// The new Anonymous backend is deliberately NOT enabled in these tests.
// The emergency tool must operate with only core, Diff and legacy redaction.
class RollbackPrivacyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('flarum-lang-english', 'flarum-audit', 'huseyinfiliz-diff');
        $legacy = new Extend\ApiResource(PostResource::class);
        foreach (['user', 'hiddenUser'] as $name) $legacy->field($name, fn (Schema\Relationship\ToOne $field) =>
            $field->get(fn (Post $post, Context $context) => $post->is_anonymous ? null : $context->resource->getValue($post, $field, $context)));
        $this->extend($legacy, ...(require __DIR__.'/../../tools/rollback-privacy.php'));
        $this->prepareDatabase([
            User::class => [
                ['id' => 2, 'username' => 'secret_original', 'email' => 'original@example.test'],
                ['id' => 3, 'username' => 'reader', 'email' => 'reader@example.test'],
                ['id' => 4, 'username' => 'secret_editor', 'email' => 'editor@example.test'],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Anonymous old version fixture', 'user_id' => 2, 'first_post_id' => 1, 'last_post_id' => 1,
                    'created_at' => '2026-10-01 01:00:00', 'comment_count' => 1],
                ['id' => 2, 'title' => 'Named old version fixture', 'user_id' => 3, 'first_post_id' => 2, 'last_post_id' => 2,
                    'created_at' => '2026-10-01 01:00:00', 'comment_count' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'type' => 'comment', 'user_id' => 2, 'edited_user_id' => 2,
                    'content' => '<t>Anonymous original</t>', 'is_anonymous' => 1, 'last_edit_is_anonymous' => 1, 'created_at' => '2026-10-01 01:00:00'],
                ['id' => 2, 'discussion_id' => 2, 'number' => 1, 'type' => 'comment', 'user_id' => 3, 'edited_user_id' => 4,
                    'content' => '<t>Named original</t>', 'is_anonymous' => 0, 'last_edit_is_anonymous' => 1, 'created_at' => '2026-10-01 01:00:00'],
            ],
            'post_edit_histories' => [
                ['id' => 1, 'post_id' => 1, 'revision' => 1, 'actor_id' => 2, 'deleted_user_id' => 2, 'rollbacked_user_id' => 2, 'created_at' => '2026-10-01 02:00:00'],
                ['id' => 2, 'post_id' => 2, 'revision' => 1, 'actor_id' => 4, 'deleted_user_id' => 4, 'rollbacked_user_id' => 4, 'created_at' => '2026-10-01 02:00:00'],
            ],
            'anonymous_edit_history' => [['diff_id' => 2, 'post_id' => 2, 'actor_reference' => 4, 'is_anonymous' => 1]],
        ]);
    }

    protected function get(string $url, int $actor = 3): array
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $response = $this->send($this->request('GET', $url, $actor ? ['authenticatedAs' => $actor] : [])->withQueryParams($query));
        $body = (string) $response->getBody();
        $this->assertSame(200, $response->getStatusCode(), substr($body, 0, 4000));
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_guard_hides_legacy_diff_actors_and_new_anonymous_editors_without_the_new_backend(): void
    {
        foreach ([1, 2] as $post) {
            $json = $this->get('/api/diff?filter[post_id]='.$post.'&include=actor,deletedUser,rollbackedUser');
            $this->assertTrue($json['data'][0]['attributes']['isAnonymous']);
            foreach (['actor', 'deletedUser', 'rollbackedUser'] as $relation) $this->assertNull($json['data'][0]['relationships'][$relation]['data']);
            $this->assertStringNotContainsString('secret_', json_encode($json));
            $post = $this->get('/api/posts/'.$post.'?include=editedUser');
            $this->assertNull($post['data']['relationships']['editedUser']['data']);
        }
    }

    public function test_guard_keeps_deep_includes_private_for_admins_too(): void
    {
        foreach ([1, 2] as $id) {
            $json = $this->get('/api/posts/'.$id.'?include=editedUser,diff.actor,diff.deletedUser,diff.rollbackedUser', 1);
            $this->assertStringNotContainsString('secret_', json_encode($json));
        }
    }
}
