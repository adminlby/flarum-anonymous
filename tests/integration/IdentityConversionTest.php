<?php

namespace AdminLby\Anonymous\Tests\integration;

use AdminLby\Anonymous\Event\IdentityChanged;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

class IdentityConversionTest extends TestCase
{
    protected function repeatedQueryThreshold(): int
    {
        // Native Diff renders the same mention through several HTML variants
        // within one fixed-size revision request. Keep detection enabled.
        return $this->name() === 'test_stored_mentions_plain_and_archived_history_are_sanitized_and_can_be_restored' ? 14 : 6;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('flarum-lang-english', 'flarum-bbcode', 'flarum-mentions', 'flarum-subscriptions', 'flarum-audit');
        if ($this->name() !== 'test_conversion_without_diff_and_with_posting_disabled') $this->extension('huseyinfiliz-diff');
        if ($extra = $this->additionalExtensions()) $this->extension(...$extra);
        $this->extension('adminlby-anonymous');
        $this->setting('allow_post_editing', '-1');
        $this->setting('allow_renaming', '-1');
        $this->setting('adminlby-anonymous.editHistoryChoice', '1');
        $this->setting('flarum-audit.enabled', '1');
        $this->prepareDatabase([
            User::class => [
                ['id' => 2, 'username' => 'converted_secret_author', 'email' => 'converted@example.test', 'is_email_confirmed' => 1],
                ['id' => 3, 'username' => 'reader', 'email' => 'reader@example.test', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'identity_operator', 'email' => 'operator@example.test', 'is_email_confirmed' => 1],
                ['id' => 5, 'username' => 'independent_secret_editor', 'email' => 'editor@example.test', 'is_email_confirmed' => 1],
                ['id' => 6, 'username' => 'converted_secret_reply', 'email' => 'reply@example.test', 'is_email_confirmed' => 1],
                ['id' => 7, 'username' => 'only_revealer', 'email' => 'revealer@example.test', 'is_email_confirmed' => 1],
            ],
            Group::class => [['id' => 10, 'name_singular' => 'Identity operator', 'name_plural' => 'Identity operators'],
                ['id' => 11, 'name_singular' => 'Revealer', 'name_plural' => 'Revealers']],
            'group_user' => [['group_id' => 10, 'user_id' => 4], ['group_id' => 11, 'user_id' => 7], ['group_id' => Group::MODERATOR_ID, 'user_id' => 5]],
            'group_permission' => [['group_id' => 10, 'permission' => 'anonymous.changeIdentity'], ['group_id' => 11, 'permission' => 'anonymous.reveal'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'flarum-audit.viewLimited']],
            Discussion::class => [
                ['id' => 1, 'title' => 'Conversion fixture', 'user_id' => 2, 'first_post_id' => 1, 'last_post_id' => 2, 'last_posted_user_id' => 3,
                    'last_post_number' => 2, 'comment_count' => 2, 'created_at' => '2026-10-01 01:00:00', 'last_posted_at' => '2026-10-01 02:00:00'],
                ['id' => 2, 'title' => 'Other fixture', 'user_id' => 3, 'first_post_id' => 3, 'last_post_id' => 4, 'last_posted_user_id' => 6,
                    'last_post_number' => 2, 'comment_count' => 2, 'created_at' => '2026-10-01 01:00:00', 'last_posted_at' => '2026-10-01 02:00:00'],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'type' => 'comment', 'user_id' => 2, 'content' => '<t>Named original</t>', 'created_at' => '2026-10-01 01:00:00', 'ip_address' => '192.0.2.88'],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'type' => 'comment', 'user_id' => 3, 'content' => '<t>Unrelated named reply</t>', 'created_at' => '2026-10-01 02:00:00'],
                ['id' => 3, 'discussion_id' => 2, 'number' => 1, 'type' => 'comment', 'user_id' => 3, 'content' => '<t>Other initial content</t>', 'created_at' => '2026-10-01 01:00:00'],
                ['id' => 4, 'discussion_id' => 2, 'number' => 2, 'type' => 'comment', 'user_id' => 6, 'content' => '<t>Named reply to convert</t>', 'created_at' => '2026-10-01 02:00:00'],
            ],
        ]);
    }

    protected function additionalExtensions(): array { return []; }

    protected function app()
    {
        $firstBoot = $this->app === null;
        $app = parent::app();
        if ($firstBoot) {
            $app->getContainer()->forgetInstance(\Flarum\Locale\LocaleManager::class);
            $app->getContainer()->make(\Flarum\Locale\LocaleManager::class);
        }
        return $app;
    }

    protected function api(string $method, string $url, int $actor = 3, ?array $attributes = null): ResponseInterface
    {
        $options = $actor ? ['authenticatedAs' => $actor] : [];
        if ($attributes !== null) $options['json'] = ['data' => ['type' => 'posts', 'attributes' => $attributes]];
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        return $this->send($this->request($method, $url, $options)->withQueryParams($query));
    }

    protected function json(ResponseInterface $response, int $status = 200): array
    {
        $body = (string) $response->getBody();
        $this->assertSame($status, $response->getStatusCode(), substr($body, 0, 5000));
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    protected function convert(int $post, bool $anonymous, int $actor = 4): array
    {
        return $this->json($this->api('POST', '/api/anonymous/posts/'.$post.'/identity', $actor, ['isAnonymous' => $anonymous]));
    }

    protected function privateDocument(array $json, int $author, string $name): void
    {
        $this->assertStringNotContainsString($name, json_encode($json));
        $this->assertStringNotContainsString('192.0.2.88', json_encode($json));
        $rows = isset($json['data']['type']) ? [$json['data']] : $json['data'];
        foreach (array_merge($rows, $json['included'] ?? []) as $row) {
            if ($row['type'] === 'users') $this->assertNotSame((string) $author, (string) $row['id']);
            if ($row['type'] === 'posts' && ($row['attributes']['isAnonymous'] ?? false)) {
                foreach (['user', 'editedUser', 'hiddenUser'] as $relation) $this->assertNull($row['relationships'][$relation]['data'] ?? null);
            }
        }
    }

    public static function readers(): array { return [[0], [2], [3], [1], [4], [7]]; }

    #[DataProvider('readers')]
    public function test_named_first_post_conversion_hides_api_history_discussion_and_preload(int $reader): void
    {
        $this->json($this->api('PATCH', '/api/posts/1', 2, ['content' => 'Original author edited before conversion']));
        $result = $this->convert(1, true);
        $this->assertTrue($result['data']['attributes']['isAnonymous']);
        $this->assertTrue($result['data']['attributes']['discussionIsAnonymous']);
        $post = $this->json($this->api('GET', '/api/posts/1?include=user,editedUser,hiddenUser,discussion.user,discussion.firstPost.user,discussion.lastPostedUser', $reader));
        $this->privateDocument($post, 2, 'converted_secret_author');
        if ($reader) $this->privateDocument($this->json($this->api('GET', '/api/diff?filter[post_id]=1&include=actor,deletedUser,rollbackedUser', $reader)), 2, 'converted_secret_author');
        $this->assertSame(2, (int) $this->database()->table('posts')->find(1)->user_id);
        $this->assertSame(2, (int) $this->database()->table('discussions')->find(1)->user_id);
    }

    public function test_separate_permission_is_required_and_ordinary_patch_cannot_bypass_it(): void
    {
        foreach ([0, 2, 3, 5, 7] as $actor) $this->json($this->api('POST', '/api/anonymous/posts/1/identity', $actor, ['isAnonymous' => true]), $actor ? 403 : 400);
        foreach ([2, 4, 1] as $actor) $this->json($this->api('PATCH', '/api/posts/1', $actor, ['isAnonymous' => true]), 403);
        $this->assertFalse((bool) $this->database()->table('posts')->find(1)->is_anonymous);
        $this->convert(1, true, 1);
        $this->assertTrue((bool) $this->database()->table('posts')->find(1)->is_anonymous);
    }

    public function test_reverse_conversion_restores_author_and_own_history_but_keeps_other_editor_private(): void
    {
        $this->json($this->api('PATCH', '/api/posts/1', 2, ['content' => 'Author revision before conversion']));
        $this->json($this->api('PATCH', '/api/posts/1', 5, ['content' => 'Independent anonymous edit', 'editHistoryAnonymous' => true]));
        $this->convert(1, true);
        $this->convert(1, false);
        $json = $this->json($this->api('GET', '/api/posts/1?include=user,editedUser,discussion.user'));
        $this->assertSame('2', $json['data']['relationships']['user']['data']['id']);
        $this->assertNull($json['data']['relationships']['editedUser']['data'] ?? null);
        $history = $this->json($this->api('GET', '/api/diff?filter[post_id]=1&include=actor'));
        $this->privateDocument($history, 5, 'independent_secret_editor');
        $this->assertTrue($history['data'][0]['attributes']['isAnonymous']);
        foreach (array_slice($history['data'], 1) as $revision) {
            $this->assertFalse($revision['attributes']['isAnonymous']);
            $this->assertSame('2', $revision['relationships']['actor']['data']['id']);
        }
    }

    public function test_last_reply_conversion_updates_terminal_user_and_author_filters_without_changing_starter(): void
    {
        $this->convert(4, true);
        $discussion = $this->json($this->api('GET', '/api/discussions/2?include=lastPostedUser,lastPost.user,user'));
        $this->privateDocument($discussion, 6, 'converted_secret_reply');
        $this->assertFalse($discussion['data']['attributes']['isAnonymous']);
        $this->assertTrue($discussion['data']['attributes']['lastPostIsAnonymous']);
        $this->assertSame('3', $discussion['data']['relationships']['user']['data']['id']);
        $this->assertNull($discussion['data']['relationships']['lastPostedUser']['data'] ?? null);
        foreach (['posts', 'discussions'] as $type) $this->assertSame([], $this->json($this->api('GET', '/api/'.$type.'?filter[author]=converted_secret_reply'))['data']);
        $this->assertSame(0, $this->json($this->api('GET', '/api/users/6'))['data']['attributes']['commentCount']);
        $this->convert(4, false);
        $this->assertSame('6', $this->json($this->api('GET', '/api/discussions/2'))['data']['relationships']['lastPostedUser']['data']['id']);
    }

    public function test_stored_mentions_plain_and_archived_history_are_sanitized_and_can_be_restored(): void
    {
        $this->json($this->api('PATCH', '/api/posts/3', 3, ['content' => '@"converted_secret_author"#p1 quoted content']));
        $this->json($this->api('PATCH', '/api/posts/3', 3, ['content' => '@"converted_secret_author"#p1 later quote']));
        $row = $this->database()->table('post_edit_histories')->where('post_id', 3)->where('revision', 1)->first();
        $repository = new \HuseyinFiliz\Diff\Repositories\DiffArchiveRepository();
        $archive = $repository->archiveContent(3, $row->id, $row->content);
        $this->database()->table('post_edit_histories')->where('id', $row->id)->update(['archive_id' => $archive->id, 'content' => null]);
        $this->convert(1, true);
        $this->assertStringNotContainsString('converted_secret_author', $this->database()->table('posts')->find(3)->content);
        $this->assertStringNotContainsString('converted_secret_author', $repository->getArchivedContent($archive->id, $row->id));
        $this->privateDocument($this->json($this->api('GET', '/api/posts/3')), 2, 'converted_secret_author');
        $history = $this->json($this->api('GET', '/api/diff?filter[post_id]=3'));
        $this->privateDocument($history, 2, 'converted_secret_author');
        $search = $this->json($this->api('GET', '/api/discussions?filter[q]=converted_secret_author'));
        $this->assertSame([], $search['data']);
        $this->convert(1, false);
        $this->assertStringContainsString('converted_secret_author', $this->database()->table('posts')->find(3)->content);
        $this->assertStringContainsString('converted_secret_author', $repository->getArchivedContent($archive->id, $row->id));
    }

    public function test_existing_notification_sender_and_payload_follow_both_directions(): void
    {
        $this->database()->table('notifications')->insert(['user_id' => 3, 'from_user_id' => 2, 'type' => 'userMentioned', 'subject_id' => 1,
            'created_at' => '2026-10-01 03:00:00', 'data' => json_encode(['username' => 'converted_secret_author'])]);
        $this->convert(1, true);
        $this->privateDocument($this->json($this->api('GET', '/api/notifications?include=fromUser,subject.user', 3)), 2, 'converted_secret_author');
        $this->database()->table('notifications')->update(['from_user_id' => null, 'data' => json_encode(['isAnonymous' => true])]);
        $this->convert(1, false);
        $json = $this->json($this->api('GET', '/api/notifications?include=fromUser', 3));
        $this->assertFalse($json['data'][0]['attributes']['isAnonymous']);
        $this->assertSame('2', $json['data'][0]['relationships']['fromUser']['data']['id']);
    }

    public function test_conversion_is_audited_idempotent_and_emits_no_post_or_notification_events(): void
    {
        $events = $this->app()->getContainer()->make(\Illuminate\Contracts\Events\Dispatcher::class);
        $sent = 0;
        foreach ([\Flarum\Post\Event\Posted::class, \Flarum\Post\Event\Revised::class, \Flarum\Notification\Event\Sending::class] as $type) {
            $events->listen($type, function () use (&$sent) { $sent++; });
        }
        $response = $this->api('POST', '/api/anonymous/posts/1/identity', 4, ['isAnonymous' => true]);
        $this->json($response);
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertFalse($this->convert(1, true)['data']['attributes']['changed']);
        $this->assertSame(0, $sent);
        $this->assertSame(0, $this->database()->table('notifications')->count());
        $log = $this->database()->table('audit_log')->where('action', 'anonymous.identity_changed')->first();
        $this->assertSame(4, (int) $log->actor_id);
        $this->assertSame(2, json_decode($log->payload, true)['user_id']);
        $audit = $this->json($this->api('GET', '/api/audit', 3));
        $this->assertNotContains('anonymous.identity_changed', array_column(array_column($audit['data'], 'attributes'), 'action'));
    }

    public function test_failure_rolls_back_flags_history_and_references_together(): void
    {
        $this->json($this->api('PATCH', '/api/posts/3', 3, ['content' => '@"converted_secret_author"#p1 linked quote']));
        $before = $this->database()->table('posts')->orderBy('id')->get()->toJson();
        $this->app()->getContainer()->make(\Illuminate\Contracts\Events\Dispatcher::class)->listen(IdentityChanged::class, function () {
            throw new \RuntimeException('Simulated late failure');
        });
        $this->json($this->api('POST', '/api/anonymous/posts/1/identity', 4, ['isAnonymous' => true]), 500);
        $this->assertSame($before, $this->database()->table('posts')->orderBy('id')->get()->toJson());
        $this->assertFalse((bool) $this->database()->table('discussions')->find(1)->is_anonymous);
        $this->assertSame(0, $this->database()->table('anonymous_edit_history')->where('post_id', 1)->count());
    }

    public function test_conversion_without_diff_and_with_posting_disabled(): void
    {
        $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set('adminlby-anonymous.enabled', '0');
        $this->convert(1, true);
        $this->privateDocument($this->json($this->api('GET', '/api/posts/1?include=user,discussion.user')), 2, 'converted_secret_author');
        $this->convert(1, false);
        $this->assertSame('2', $this->json($this->api('GET', '/api/posts/1'))['data']['relationships']['user']['data']['id']);
    }

    public function test_invalid_archive_cannot_commit_partial_anonymity(): void
    {
        $this->app();
        $repository = new \HuseyinFiliz\Diff\Repositories\DiffArchiveRepository();
        $archive = $repository->archiveContent(3, 999, '@"converted_secret_author"#p1 old reference');
        $this->database()->table('post_edit_histories_archive')->where('id', $archive->id)->update(['contents' => gzcompress('invalid archive JSON')]);
        $this->json($this->api('POST', '/api/anonymous/posts/1/identity', 4, ['isAnonymous' => true]), 500);
        $this->assertFalse((bool) $this->database()->table('posts')->find(1)->is_anonymous);
        $this->assertFalse((bool) $this->database()->table('discussions')->find(1)->is_anonymous);
        $this->assertSame(0, $this->database()->table('anonymous_edit_history')->where('post_id', 1)->count());
        $this->assertSame(0, $this->database()->table('audit_log')->where('action', 'anonymous.identity_changed')->count());
    }

    public function test_deleted_account_remains_traceable_and_boolean_input_is_strict(): void
    {
        foreach ([null, 'true', 1, []] as $invalid) $this->json($this->api('POST', '/api/anonymous/posts/1/identity', 4, ['isAnonymous' => $invalid]), 422);
        $this->convert(1, true);
        $this->database()->table('users')->where('id', 2)->delete();
        $reveal = $this->json($this->api('POST', '/api/anonymous/posts/1/reveal', 7));
        $this->assertSame(2, $reveal['data']['attributes']['userId']);
        $this->assertTrue($reveal['data']['attributes']['deleted']);
        $this->convert(1, false);
        $this->assertNull($this->json($this->api('GET', '/api/posts/1'))['data']['relationships']['user']['data'] ?? null);
    }

    public function test_guest_ssr_and_preload_do_not_retain_the_pre_conversion_author(): void
    {
        $this->convert(1, true);
        $response = $this->send($this->request('GET', '/d/1-conversion-fixture'));
        $this->assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        $this->assertStringNotContainsString('converted_secret_author', $html);
        $this->assertStringContainsString('Anonymous user', $html);
        $this->assertStringContainsString('flarum-json-payload', $html);
    }

    public function test_existing_author_event_posts_follow_first_post_conversion(): void
    {
        $options = ['authenticatedAs' => 2, 'json' => ['data' => ['type' => 'discussions', 'id' => '1', 'attributes' => ['title' => 'Renamed conversion fixture']]]];
        $this->json($this->send($this->request('PATCH', '/api/discussions/1', $options)));
        $id = $this->database()->table('posts')->where('type', 'discussionRenamed')->value('id');
        $this->assertNotNull($id);
        $this->convert(1, true);
        $this->privateDocument($this->json($this->api('GET', '/api/posts/'.$id.'?include=user')), 2, 'converted_secret_author');
        $this->assertTrue((bool) $this->database()->table('posts')->find($id)->is_anonymous);
        $this->convert(1, false);
        $this->assertSame('2', $this->json($this->api('GET', '/api/posts/'.$id))['data']['relationships']['user']['data']['id']);
    }
}
