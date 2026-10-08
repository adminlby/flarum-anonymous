<?php

namespace AdminLby\Anonymous\Tests\integration;

use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

class EditHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('flarum-lang-english', 'flarum-bbcode', 'flarum-audit');
        if ($this->name() !== 'test_without_diff_the_setting_has_no_effect') $this->extension('huseyinfiliz-diff');
        // The testing manager takes explicit order. Production migrate sorts
        // this same order using extra.flarum-extension.optional-dependencies.
        $this->extension('adminlby-anonymous');
        $this->setting('allow_post_editing', '-1');
        $this->setting('adminlby-anonymous.editHistoryChoice', '1');
        $this->setting('flarum-audit.enabled', '1');
        $this->prepareDatabase([
            User::class => [
                ['id' => 2, 'username' => 'secret_original_author', 'email' => 'original@example.test', 'is_email_confirmed' => 1],
                ['id' => 3, 'username' => 'reader', 'email' => 'reader@example.test', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'secret_editor', 'email' => 'editor@example.test', 'is_email_confirmed' => 1],
                ['id' => 5, 'username' => 'revealer', 'email' => 'revealer@example.test', 'is_email_confirmed' => 1],
            ],
            Group::class => [['id' => 10, 'name_singular' => 'Revealer', 'name_plural' => 'Revealers']],
            'group_user' => [['group_id' => Group::MODERATOR_ID, 'user_id' => 4], ['group_id' => 10, 'user_id' => 5]],
            'group_permission' => [
                ['group_id' => 10, 'permission' => 'anonymous.reveal'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'flarum-audit.viewLimited'],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Anonymous fixture', 'user_id' => 2, 'first_post_id' => 1, 'last_post_id' => 1,
                    'last_posted_user_id' => 2, 'created_at' => '2026-10-01 01:00:00', 'last_posted_at' => '2026-10-01 01:00:00',
                    'last_post_number' => 1, 'comment_count' => 1, 'is_anonymous' => 1],
                ['id' => 2, 'title' => 'Named fixture', 'user_id' => 3, 'first_post_id' => 2, 'last_post_id' => 2,
                    'last_posted_user_id' => 3, 'created_at' => '2026-10-01 01:00:00', 'last_posted_at' => '2026-10-01 01:00:00',
                    'last_post_number' => 1, 'comment_count' => 1, 'is_anonymous' => 0],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'type' => 'comment', 'user_id' => 2, 'anonymous_author_id' => 2,
                    'content' => '<t>Anonymous original content</t>', 'created_at' => '2026-10-01 01:00:00', 'is_anonymous' => 1],
                ['id' => 2, 'discussion_id' => 2, 'number' => 1, 'type' => 'comment', 'user_id' => 3,
                    'content' => '<t>Named original content</t>', 'created_at' => '2026-10-01 01:00:00', 'is_anonymous' => 0],
            ],
        ]);
    }

    protected function app()
    {
        $first = $this->app === null;
        $app = parent::app();
        if ($first) {
            $app->getContainer()->forgetInstance(\Flarum\Locale\LocaleManager::class);
            $app->getContainer()->make(\Flarum\Locale\LocaleManager::class);
        }
        return $app;
    }

    protected function api(string $method, string $url, int $actor = 3, ?array $data = null): ResponseInterface
    {
        $options = $actor ? ['authenticatedAs' => $actor] : [];
        if ($data !== null) $options['json'] = ['data' => $data];
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        return $this->send($this->request($method, $url, $options)->withQueryParams($query));
    }

    protected function json(ResponseInterface $response, int $status = 200): array
    {
        $body = (string) $response->getBody();
        $this->assertSame($status, $response->getStatusCode(), substr($body, 0, 5000));
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    protected function edit(int $post, int $actor, ?bool $anonymous, string $content): array
    {
        $attributes = ['content' => $content];
        if ($anonymous !== null) $attributes['editHistoryAnonymous'] = $anonymous;
        return $this->json($this->api('PATCH', '/api/posts/'.$post, $actor, [
            'type' => 'posts', 'id' => (string) $post, 'attributes' => $attributes,
        ]));
    }

    protected function history(int $post, int $actor = 3): array
    {
        return $this->json($this->api('GET', '/api/diff?filter[post_id]='.$post.'&include=actor,deletedUser,rollbackedUser', $actor));
    }

    protected function assertHidden(array $json, int $id, string $name): void
    {
        $this->assertStringNotContainsString($name, json_encode($json));
        foreach (array_merge(isset($json['data']['type']) ? [$json['data']] : $json['data'], $json['included'] ?? []) as $row) {
            if ($row['type'] === 'users') $this->assertNotSame((string) $id, (string) $row['id']);
            if (in_array($row['type'], ['posts', 'diff'])) {
                foreach (['actorId', 'actor_id', 'actorReference', 'actor_reference', 'anonymous_author_id'] as $key) {
                    $this->assertArrayNotHasKey($key, $row['attributes'] ?? []);
                }
            }
            if ($row['type'] === 'diff' && ($row['attributes']['isAnonymous'] ?? false)) {
                foreach (['actor', 'deletedUser', 'rollbackedUser'] as $relation) $this->assertNull($row['relationships'][$relation]['data'] ?? null);
            }
        }
    }

    public static function viewers(): array { return [[2], [3], [1], [5]]; }

    #[DataProvider('viewers')]
    public function test_original_anonymous_author_remains_hidden_for_every_viewer(int $viewer): void
    {
        $created = $this->edit(1, 2, null, 'Original author changed anonymous content');
        $this->assertTrue($created['data']['attributes']['isAnonymous']);
        $this->assertTrue($created['data']['attributes']['lastEditIsAnonymous']);
        $history = $this->history(1, $viewer);
        $this->assertCount(2, $history['data']);
        $this->assertHidden($history, 2, 'secret_original_author');
        foreach ($history['data'] as $row) {
            $this->assertTrue($row['attributes']['isAnonymous']);
            $this->assertSame($viewer === 2, $row['attributes']['isMine']);
        }
        $this->assertSame(2, (int) $this->database()->table('posts')->find(1)->user_id);
        $this->assertSame(2, (int) $this->database()->table('post_edit_histories')->where('revision', 1)->value('actor_id'));
    }

    public function test_original_author_cannot_choose_real_identity_even_through_direct_api(): void
    {
        $json = $this->json($this->api('PATCH', '/api/posts/1', 2, ['type' => 'posts', 'id' => '1',
            'attributes' => ['content' => 'Attempted disclosure', 'editHistoryAnonymous' => false]]), 422);
        $this->assertStringContainsString('must remain anonymous', json_encode($json));
        $this->assertSame(0, $this->database()->table('post_edit_histories')->count());
    }

    public function test_anonymous_editor_of_named_post_is_hidden_in_post_and_diff_api(): void
    {
        $post = $this->edit(2, 4, true, 'Anonymous moderator edit');
        $this->assertFalse($post['data']['attributes']['isAnonymous']);
        $this->assertTrue($post['data']['attributes']['lastEditIsAnonymous']);
        $this->assertNull($post['data']['relationships']['editedUser']['data'] ?? null);
        $history = $this->history(2, 4);
        $this->assertHidden($history, 4, 'secret_editor');
        $this->assertTrue($history['data'][0]['attributes']['isMine']);
        $this->assertFalse($history['data'][1]['attributes']['isAnonymous']);
        $this->assertSame('3', $history['data'][1]['relationships']['actor']['data']['id']);
        $this->assertSame(4, (int) $this->database()->table('posts')->find(2)->edited_user_id);
    }

    public function test_named_revision_does_not_rewrite_an_earlier_anonymous_revision(): void
    {
        $this->edit(2, 4, true, 'First anonymous edit');
        $this->edit(2, 4, false, 'Second named edit');
        $history = $this->history(2);
        $this->assertFalse($history['data'][0]['attributes']['isAnonymous']);
        $this->assertSame('4', $history['data'][0]['relationships']['actor']['data']['id']);
        $this->assertTrue($history['data'][1]['attributes']['isAnonymous']);
        $this->assertNull($history['data'][1]['relationships']['actor']['data'] ?? null);
        $this->assertFalse((bool) $this->database()->table('posts')->find(2)->is_anonymous);
    }

    public function test_other_editor_can_be_named_without_disclosing_the_anonymous_original_author(): void
    {
        $this->edit(1, 4, false, 'A named moderator edited an anonymous post');
        $history = $this->history(1);
        $this->assertHidden($history, 2, 'secret_original_author');
        $this->assertSame('4', $history['data'][0]['relationships']['actor']['data']['id']);
        $this->assertTrue($history['data'][1]['attributes']['isAnonymous']);
    }

    public function test_disabling_choice_keeps_history_private_and_forces_original_author_anonymous(): void
    {
        $this->edit(2, 4, true, 'Anonymous edit before switch off');
        $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set('adminlby-anonymous.editHistoryChoice', '0');
        $this->assertHidden($this->history(2), 4, 'secret_editor');
        $this->json($this->api('PATCH', '/api/posts/2', 4, ['type' => 'posts', 'id' => '2',
            'attributes' => ['content' => 'Forbidden explicit choice', 'editHistoryAnonymous' => false]]), 422);
        $this->edit(1, 2, true, 'Original author always anonymous');
        $this->assertHidden($this->history(1), 2, 'secret_original_author');
    }

    public function test_noop_edit_cannot_change_the_last_revision_identity(): void
    {
        $this->edit(2, 4, true, 'Unchanged anonymous edit');
        $this->edit(2, 4, false, 'Unchanged anonymous edit');
        $this->assertHidden($this->history(2), 4, 'secret_editor');
        $this->assertTrue((bool) $this->database()->table('posts')->find(2)->last_edit_is_anonymous);
        $this->assertSame(2, $this->database()->table('post_edit_histories')->count());
    }

    public function test_deep_and_sparse_includes_cannot_bypass_edit_history_privacy(): void
    {
        $this->edit(1, 2, true, 'Nested anonymous revision');
        foreach (['/api/posts/1?include=diff.actor,diff.deletedUser,diff.rollbackedUser',
            '/api/discussions/1?include=firstPost.diff.actor,lastPost.diff.actor',
            '/api/diff?filter[post_id]=1&fields[diff]=actor,deletedUser,rollbackedUser'] as $url) {
            $this->assertHidden($this->json($this->api('GET', $url)), 2, 'secret_original_author');
        }
    }

    public function test_guests_cannot_bypass_history_permission_through_post_includes(): void
    {
        $this->edit(1, 2, true, 'Permission-protected revision');
        $response = $this->api('GET', '/api/posts/1?include=diff.actor', 0);
        $this->assertContains($response->getStatusCode(), [200, 400]);
        $post = $this->json($response, $response->getStatusCode());
        $this->assertEmpty($post['data']['relationships']['diff']['data'] ?? [], json_encode($post));
        $this->assertNotContains('diff', array_column($post['included'] ?? [], 'type'));
        $this->assertSame(403, $this->api('GET', '/api/diff?filter[post_id]=1', 0)->getStatusCode());
    }

    public function test_explicit_reveal_is_permission_checked_private_and_audited(): void
    {
        $this->edit(2, 4, true, 'Revision for reveal test');
        $id = $this->database()->table('post_edit_histories')->where('post_id', 2)->where('revision', 1)->value('id');
        foreach ([0, 3, 4] as $actor) $this->assertSame($actor ? 403 : 400, $this->api('POST', '/api/anonymous/diff/'.$id.'/reveal', $actor)->getStatusCode());
        $response = $this->api('POST', '/api/anonymous/diff/'.$id.'/reveal', 5);
        $reveal = $this->json($response);
        $this->assertSame(4, $reveal['data']['attributes']['userId']);
        $this->assertSame('secret_editor', $reveal['data']['attributes']['username']);
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $log = $this->database()->table('audit_log')->where('action', 'anonymous.edit_author_revealed')->first();
        $this->assertSame(5, (int) $log->actor_id);
        $this->assertSame(4, json_decode($log->payload, true)['user_id']);
        $this->assertHidden($this->history(2, 5), 4, 'secret_editor');
        $audit = $this->json($this->api('GET', '/api/audit', 3));
        $this->assertNotContains('anonymous.edit_author_revealed', array_column(array_column($audit['data'], 'attributes'), 'action'));
        $this->assertHidden($audit, 4, 'secret_editor');
    }

    public function test_deleted_editor_is_traceable_without_a_public_user_relationship(): void
    {
        $this->edit(2, 4, true, 'A deleted editor made this revision');
        $id = $this->database()->table('post_edit_histories')->where('revision', 1)->value('id');
        $this->database()->table('users')->where('id', 4)->delete();
        $json = $this->json($this->api('POST', '/api/anonymous/diff/'.$id.'/reveal', 1));
        $this->assertSame(4, $json['data']['attributes']['userId']);
        $this->assertTrue($json['data']['attributes']['deleted']);
        $this->assertHidden($this->history(2), 4, 'secret_editor');
    }

    public function test_legacy_revisions_and_actions_cannot_reveal_original_anonymous_author(): void
    {
        $this->edit(1, 4, false, 'Public moderator revision');
        $this->database()->table('anonymous_edit_history')->delete();
        $this->database()->table('post_edit_histories')->update(['deleted_user_id' => 2, 'rollbacked_user_id' => 2]);
        $this->assertHidden($this->history(1), 2, 'secret_original_author');
    }

    public function test_rollback_by_original_author_stays_anonymous(): void
    {
        $this->edit(1, 2, true, 'Changed anonymous content');
        $id = $this->database()->table('post_edit_histories')->where('revision', 0)->value('id');
        $this->json($this->api('POST', '/api/diff/'.$id, 2));
        $this->assertHidden($this->history(1), 2, 'secret_original_author');
        $this->assertTrue((bool) $this->database()->table('posts')->find(1)->last_edit_is_anonymous);
    }

    public function test_identity_requires_boolean_and_cannot_be_selected_on_creation(): void
    {
        $this->json($this->api('PATCH', '/api/posts/2', 3, ['type' => 'posts', 'id' => '2',
            'attributes' => ['content' => 'Invalid choice', 'editHistoryAnonymous' => 'true']]), 422);
        $this->json($this->api('POST', '/api/posts', 3, ['type' => 'posts', 'attributes' => ['content' => 'Invalid creation', 'editHistoryAnonymous' => true],
            'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]), 403);
    }

    public function test_without_diff_the_setting_has_no_effect(): void
    {
        $forum = $this->json($this->api('GET', '/api'));
        $this->assertFalse($forum['data']['attributes']['anonymousEditHistoryAvailable']);
        $this->assertFalse($forum['data']['attributes']['anonymousEditHistoryChoice']);
        $post = $this->edit(1, 2, null, 'Anonymous edit without Diff');
        $this->assertTrue($post['data']['attributes']['isAnonymous']);
        $this->assertNull($post['data']['relationships']['editedUser']['data'] ?? null);
    }

    public function test_migration_is_additive_and_repeatable_without_touching_diff_tables(): void
    {
        $this->edit(1, 2, true, 'Migration snapshot revision');
        $db = $this->database();
        $histories = $db->table('post_edit_histories')->get()->toJson();
        $identities = $db->table('anonymous_edit_history')->get()->toJson();
        $migration = require __DIR__.'/../../migrations/2026_10_07_000004_add_edit_history_identity.php';
        $migration['up']($db->getSchemaBuilder());
        $migration['up']($db->getSchemaBuilder());
        $this->assertSame($histories, $db->table('post_edit_histories')->get()->toJson());
        $this->assertSame($identities, $db->table('anonymous_edit_history')->get()->toJson());
    }

    public function test_partial_diff_failure_cannot_expose_an_anonymous_editor_in_later_history(): void
    {
        $this->edit(2, 4, true, 'A revision with a durable anonymous reservation');
        $this->database()->table('anonymous_edit_history')->where('diff_id', '>', 0)->where('actor_reference', 4)->delete();
        $this->assertHidden($this->history(2), 4, 'secret_editor');
        $this->edit(2, 4, false, 'Later named revision');
        $rows = $this->history(2)['data'];
        $this->assertFalse($rows[0]['attributes']['isAnonymous']);
        $this->assertTrue($rows[1]['attributes']['isAnonymous']);
        $this->assertNull($rows[1]['relationships']['actor']['data'] ?? null);
    }

    public function test_diff_main_post_only_setting_disables_choices_for_untracked_replies(): void
    {
        $this->setting('huseyinfiliz-diff.mainPostOnly', '1');
        $this->prepareDatabase([Post::class => [['id' => 3, 'discussion_id' => 2, 'number' => 2, 'type' => 'comment', 'user_id' => 3,
            'content' => '<t>Untracked reply</t>', 'created_at' => '2026-10-01 02:00:00']]]);
        $json = $this->json($this->api('GET', '/api/posts/3'));
        $this->assertFalse($json['data']['attributes']['canChooseEditHistoryIdentity']);
        $this->json($this->api('PATCH', '/api/posts/3', 3, ['type' => 'posts', 'id' => '3',
            'attributes' => ['content' => 'This edit would have no history', 'editHistoryAnonymous' => true]]), 422);
        $this->edit(3, 3, null, 'Normal edit of an untracked reply');
        $this->assertSame(0, $this->database()->table('post_edit_histories')->count());
    }

    public function test_revision_identity_has_no_update_endpoint(): void
    {
        $this->edit(2, 4, true, 'Immutable anonymous revision');
        $id = $this->database()->table('post_edit_histories')->where('revision', 1)->value('id');
        $before = $this->database()->table('anonymous_edit_history')->orderBy('id')->get()->toJson();
        $response = $this->api('PATCH', '/api/diff/'.$id, 4, ['type' => 'diff', 'id' => (string) $id, 'attributes' => ['isAnonymous' => false]]);
        $this->assertContains($response->getStatusCode(), [404, 405]);
        $this->assertSame($before, $this->database()->table('anonymous_edit_history')->orderBy('id')->get()->toJson());
        $this->assertHidden($this->history(2), 4, 'secret_editor');
    }

    public function test_twenty_revision_sparse_include_batches_private_metadata_loading(): void
    {
        $db = $this->database();
        for ($i = 1; $i <= 20; $i++) {
            $id = $db->table('post_edit_histories')->insertGetId(['post_id' => 2, 'revision' => $i, 'actor_id' => 4, 'created_at' => '2026-10-01 02:00:00']);
            $db->table('anonymous_edit_history')->insert(['diff_id' => $id, 'post_id' => 2, 'actor_reference' => 4, 'is_anonymous' => true]);
        }
        $db->enableQueryLog();
        $json = $this->json($this->api('GET', '/api/diff?filter[post_id]=2&page[limit]=20&fields[diff]=revision,isAnonymous,actor&include=actor'));
        $this->assertCount(20, $json['data']);
        $this->assertHidden($json, 4, 'secret_editor');
        $privateQueries = array_filter($db->getQueryLog(), fn ($query) => str_contains($query['query'], 'anonymous_edit_history'));
        $this->assertLessThanOrEqual(3, count($privateQueries), 'Private identity loading must not query once per revision');
    }

    public function test_original_revision_created_after_account_deletion_uses_private_author_snapshot(): void
    {
        $this->database()->table('users')->where('id', 2)->delete();
        $this->edit(1, 1, false, 'A moderator edited after the anonymous author was deleted');
        $id = $this->database()->table('post_edit_histories')->where('revision', 0)->value('id');
        $reveal = $this->json($this->api('POST', '/api/anonymous/diff/'.$id.'/reveal', 1));
        $this->assertSame(2, $reveal['data']['attributes']['userId']);
        $this->assertTrue($reveal['data']['attributes']['deleted']);
        $this->assertHidden($this->history(1), 2, 'secret_original_author');
    }
}
