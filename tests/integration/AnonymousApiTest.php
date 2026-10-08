<?php

namespace AdminLby\Anonymous\Tests\integration;

use AdminLby\Anonymous\Settings;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

class AnonymousApiTest extends TestCase
{
    protected function repeatedQueryThreshold(): int
    {
        // rc.8's mentions/subscriptions creation path reloads the same actor
        // five times. Keep data-scaling detection enabled with a threshold of
        // six; the list fixtures below contain twenty distinct authors.
        // SSR composes several internal API requests; those fixed repeated
        // actor loads are checked separately from the 20-author list fixture.
        return $this->name() === 'test_ssr_and_preload_use_redacted_resources' ? 8 : 6;
    }
    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('flarum-lang-english', 'flarum-bbcode', 'flarum-mentions', 'flarum-subscriptions', 'flarum-likes', 'flarum-audit', 'adminlby-anonymous');
        $this->setting('allow_post_editing', '-1');
        $this->setting('allow_renaming', '-1');
        $this->setting('flarum-audit.enabled', '1');
        $this->prepareDatabase([
            User::class => [
                ['id' => 2, 'username' => 'secret_author_829', 'email' => 'secret829@example.test', 'is_email_confirmed' => 1],
                ['id' => 3, 'username' => 'reader', 'email' => 'reader@example.test', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'other_reader', 'email' => 'other@example.test', 'is_email_confirmed' => 1],
                ['id' => 5, 'username' => 'revealer', 'email' => 'revealer@example.test', 'is_email_confirmed' => 1],
            ],
            Group::class => [['id' => 10, 'name_singular' => 'Revealer', 'name_plural' => 'Revealers']],
            'group_user' => [['group_id' => 10, 'user_id' => 5]],
            'group_permission' => [['group_id' => 10, 'permission' => 'anonymous.reveal']],
            Discussion::class => [
                ['id' => 1, 'title' => 'Anonymous fixture', 'user_id' => 2, 'first_post_id' => 1, 'last_post_id' => 2,
                    'last_posted_user_id' => 2, 'created_at' => '2026-10-01 01:00:00', 'last_posted_at' => '2026-10-01 02:00:00', 'last_post_number' => 2, 'comment_count' => 2, 'is_anonymous' => 1],
                ['id' => 2, 'title' => 'Named fixture', 'user_id' => 3, 'first_post_id' => 3, 'last_post_id' => 3,
                    'last_posted_user_id' => 3, 'created_at' => '2026-10-01 01:00:00', 'last_posted_at' => '2026-10-01 01:00:00', 'last_post_number' => 1, 'comment_count' => 1, 'is_anonymous' => 0],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'type' => 'comment', 'user_id' => 2, 'anonymous_author_id' => 2, 'content' => '<t>Anonymous initial content</t>', 'created_at' => '2026-10-01 01:00:00', 'is_anonymous' => 1],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'type' => 'comment', 'user_id' => 2, 'anonymous_author_id' => 2, 'content' => '<t>Anonymous reply content</t>', 'created_at' => '2026-10-01 02:00:00', 'is_anonymous' => 1,
                    'edited_user_id' => 2, 'hidden_user_id' => 2, 'edited_at' => '2026-10-02 01:00:00', 'ip_address' => '192.0.2.829'],
                ['id' => 3, 'discussion_id' => 2, 'number' => 1, 'type' => 'comment', 'user_id' => 3, 'content' => '<t>Named initial content</t>', 'created_at' => '2026-10-01 01:00:00', 'is_anonymous' => 0],
            ],
        ]);
    }

    protected function api(string $method, string $url, int $actor = 3, ?array $data = null): ResponseInterface
    {
        $options = $actor ? ['authenticatedAs' => $actor] : [];
        if ($data !== null) {
            $options['json'] = ['data' => $data];
        }

        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

        return $this->send($this->request($method, $url, $options)->withQueryParams($query));
    }

    protected function app()
    {
        $firstBoot = $this->app === null;
        $app = parent::app();
        if ($firstBoot) {
            // Flarum/testing enables extensions in the same container before
            // registering their extenders. Reload the locale manager that was
            // resolved by onEnable(), as a fresh forum request does naturally.
            $container = $app->getContainer();
            $container->forgetInstance(\Flarum\Locale\LocaleManager::class);
            $container->make(\Flarum\Locale\LocaleManager::class);
        }

        return $app;
    }

    protected function json(ResponseInterface $response, int $status = 200): array
    {
        $body = (string) $response->getBody();
        $this->assertSame($status, $response->getStatusCode(), substr($body, 0, 6500));

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    protected function assertNoAuthor(array $json): void
    {
        $serialized = json_encode($json, JSON_THROW_ON_ERROR);
        foreach (['secret_author_829', 'secret829@example.test', '192.0.2.829'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        foreach (array_merge(isset($json['data']['type']) ? [$json['data']] : ($json['data'] ?? []), $json['included'] ?? []) as $resource) {
            if ($resource['type'] === 'users') {
                $this->assertNotSame('2', (string) $resource['id'], 'Anonymous author was included as a User');
            }
            if (($resource['attributes']['isAnonymous'] ?? false) && $resource['type'] === 'posts') {
                foreach (['user', 'editedUser', 'hiddenUser'] as $relation) {
                    $this->assertNull($resource['relationships'][$relation]['data'] ?? null, $relation.' leaks linkage');
                }
                foreach (['userId', 'user_id', 'anonymousAuthorId', 'anonymous_author_id', 'editedUserId', 'hiddenUserId', 'ipAddress'] as $attribute) {
                    $this->assertNull($resource['attributes'][$attribute] ?? null, $attribute.' leaks an identifier');
                }
            }
            if ($resource['type'] === 'discussions' && ($resource['attributes']['isAnonymous'] ?? false)) {
                $this->assertNull($resource['relationships']['user']['data'] ?? null);
            }
        }
    }

    public static function viewers(): array
    {
        return [['guest', 0], ['ordinary', 3], ['author', 2], ['admin', 1], ['revealer', 5]];
    }

    #[DataProvider('viewers')]
    public function test_post_and_nested_discussion_relationships_never_reveal(string $name, int $actor): void
    {
        $json = $this->json($this->api('GET', '/api/posts/2?include=user,editedUser,hiddenUser,discussion.user,discussion.lastPostedUser,discussion.firstPost.user,discussion.lastPost.user', $actor));
        $this->assertNoAuthor($json);
        $this->assertSame($actor === 2, $json['data']['attributes']['isMine']);
        $this->assertSame(in_array($actor, [1, 5]), $json['data']['attributes']['canRevealAnonymousAuthor']);
    }

    public function test_anonymous_discussion_creation_preserves_real_ids_and_anonymous_first_post(): void
    {
        $json = $this->json($this->api('POST', '/api/discussions', 2, ['type' => 'discussions', 'attributes' => ['title' => 'Anonymous creation test', 'content' => 'New content', 'isAnonymous' => true]]), 201);
        $this->assertNoAuthor($json);
        $discussion = $this->database()->table('discussions')->find($json['data']['id']);
        $first = $this->database()->table('posts')->find($discussion->first_post_id);
        $this->assertSame(2, (int) $discussion->user_id);
        $this->assertSame(2, (int) $first->user_id);
        $this->assertSame(1, (int) $first->is_anonymous);
        $this->assertSame(5, $this->database()->table('users')->count(), 'No synthetic User may be created');
    }

    public static function replyContents(): array
    {
        return [['Ordinary anonymous reply'], ['> A quoted passage'], ['@"Anything"#p3 hello']];
    }

    #[DataProvider('replyContents')]
    public function test_anonymous_replies_and_quotes_can_be_created(string $content): void
    {
        $json = $this->json($this->api('POST', '/api/posts', 2, ['type' => 'posts', 'attributes' => ['content' => $content, 'isAnonymous' => true], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]), 201);
        $this->assertNoAuthor($json);
        $this->assertTrue($json['data']['attributes']['isAnonymous']);
        $this->assertSame(2, (int) $this->database()->table('posts')->find($json['data']['id'])->user_id);
    }

    public function test_named_posts_still_expose_their_author(): void
    {
        $json = $this->json($this->api('GET', '/api/posts/3'));
        $this->assertSame('3', (string) $json['data']['relationships']['user']['data']['id']);
        $this->assertFalse($json['data']['attributes']['isAnonymous']);
    }

    public function test_public_author_searches_and_profile_lists_exclude_anonymous_content(): void
    {
        foreach (['posts', 'discussions'] as $type) {
            $json = $this->json($this->api('GET', '/api/'.$type.'?filter[author]=secret_author_829'));
            $this->assertSame([], $json['data']);
        }
        $profile = $this->json($this->api('GET', '/api/users/2'));
        $this->assertSame(0, $profile['data']['attributes']['commentCount']);
        $this->assertSame(0, $profile['data']['attributes']['discussionCount']);
    }

    public function test_search_and_discussion_listing_hide_first_last_and_relevant_authors(): void
    {
        foreach (['/api/discussions', '/api/discussions/1', '/api/posts?filter[discussion]=1', '/api/discussions?filter[q]=Anonymous'] as $url) {
            $this->assertNoAuthor($this->json($this->api('GET', $url)));
        }
    }

    public function test_anonymous_edit_keeps_anonymity_without_leaking_editor(): void
    {
        $json = $this->json($this->api('PATCH', '/api/posts/2', 2, ['type' => 'posts', 'id' => '2', 'attributes' => ['content' => 'Edited anonymously'] ]));
        $this->assertNoAuthor($json);
        $this->assertTrue($json['data']['attributes']['isAnonymous']);
        $this->assertSame(2, (int) $this->database()->table('posts')->find(2)->edited_user_id);
    }

    public static function immutableCases(): array { return [[2, false, 2], [3, true, 3]]; }

    #[DataProvider('immutableCases')]
    public function test_identity_cannot_be_changed_on_edit(int $id, bool $anonymous, int $actor): void
    {
        $response = $this->api('PATCH', '/api/posts/'.$id, $actor, ['type' => 'posts', 'id' => (string) $id, 'attributes' => ['isAnonymous' => $anonymous]]);
        $this->assertContains($response->getStatusCode(), [403, 422], (string) $response->getBody());
        $this->assertSame(! $anonymous, (bool) $this->database()->table('posts')->find($id)->is_anonymous);
    }

    public static function disabledSettings(): array { return [['enabled', 'Plain reply'], ['replies', 'Plain reply'], ['quotes', '> quoted'], ['quotes', '@"Any"#p3 quoted']]; }

    #[DataProvider('disabledSettings')]
    public function test_type_and_global_switches_are_enforced_on_the_server(string $key, string $content): void
    {
        $this->setting(Settings::PREFIX.$key, false);
        $response = $this->api('POST', '/api/posts', 2, ['type' => 'posts', 'attributes' => ['content' => $content, 'isAnonymous' => true], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]);
        $this->assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(3, $this->database()->table('posts')->count());
    }

    public function test_reveal_is_authorized_explicit_and_does_not_poison_normal_resources(): void
    {
        $this->assertSame(403, $this->api('POST', '/api/anonymous/posts/2/reveal', 3)->getStatusCode());
        $this->assertSame(403, $this->api('POST', '/api/anonymous/posts/2/reveal', 2)->getStatusCode());
        $response = $this->api('POST', '/api/anonymous/posts/2/reveal', 5);
        $json = $this->json($response);
        $this->assertSame(2, $json['data']['attributes']['userId']);
        $this->assertSame('secret_author_829', $json['data']['attributes']['username']);
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertNoAuthor($this->json($this->api('GET', '/api/posts/2', 5)));
    }

    public function test_disabling_new_submissions_keeps_existing_resources_private(): void
    {
        $this->setting(Settings::PREFIX.'enabled', false);
        $this->assertNoAuthor($this->json($this->api('GET', '/api/posts/2')));
    }

    public function test_quote_rendering_and_edit_content_do_not_reconstruct_anonymous_author(): void
    {
        $json = $this->json($this->api('POST', '/api/posts', 3, ['type' => 'posts', 'attributes' => ['content' => '@"Anything"#p1 quoted content'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]), 201);
        $this->assertNoAuthor($json);
        $id = $json['data']['id'];
        $show = $this->json($this->api('GET', '/api/posts/'.$id, 3));
        $this->assertNoAuthor($show);
        $this->assertStringNotContainsString('secret_author_829', $show['data']['attributes']['content'] ?? '');
        $this->assertStringContainsString('Anonymous user', $show['data']['attributes']['contentHtml']);
        $stored = $this->database()->table('posts')->find($id)->content;
        $this->assertStringNotContainsString('secret_author_829', $stored);
        $search = $this->json($this->api('GET', '/api/discussions?filter[q]=secret_author_829'));
        $this->assertSame([], $search['data'], 'Indexed quote metadata must not contain real author names');
    }

    public function test_negative_author_filters_cannot_be_used_as_an_identity_oracle(): void
    {
        foreach (['posts' => ['1', '2'], 'discussions' => ['1']] as $type => $anonymousIds) {
            foreach (['secret_author_829', 'reader', 'other_reader'] as $author) {
                $json = $this->json($this->api('GET', '/api/'.$type.'?filter[-author]='.$author));
                $ids = array_map('strval', array_column($json['data'], 'id'));
                foreach ($anonymousIds as $id) $this->assertContains($id, $ids);
            }
        }
    }

    public function test_sparse_fields_do_not_bypass_relationship_protection(): void
    {
        foreach (['/api/posts/2?fields[posts]=user,editedUser,hiddenUser,ipAddress&include=user,editedUser,hiddenUser',
            '/api/discussions/1?fields[discussions]=user,lastPostedUser,firstPost&include=user,lastPostedUser,firstPost.user'] as $url) {
            $json = $this->json($this->api('GET', $url, 1));
            $this->assertNoAuthor($json);
            $this->assertNull($json['data']['relationships']['user']['data'] ?? null);
        }
    }

    public function test_anonymous_reply_hides_last_poster_in_a_named_discussion(): void
    {
        $this->json($this->api('POST', '/api/posts', 2, ['type' => 'posts', 'attributes' => ['content' => 'Anonymous last reply', 'isAnonymous' => true], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]), 201);
        $json = $this->json($this->api('GET', '/api/discussions/2?include=user,lastPostedUser,lastPost.user'));
        $this->assertNoAuthor($json);
        $this->assertTrue($json['data']['attributes']['lastPostIsAnonymous']);
        $this->assertNull($json['data']['relationships']['lastPostedUser']['data'] ?? null);
        $this->assertSame('3', $json['data']['relationships']['user']['data']['id']);
    }

    public function test_first_post_deletion_does_not_remove_discussion_privacy(): void
    {
        $this->database()->table('posts')->where('id', 1)->delete();
        $this->database()->table('discussions')->where('id', 1)->update(['first_post_id' => null]);
        $this->assertNoAuthor($this->json($this->api('GET', '/api/discussions/1')));
    }

    public function test_missing_last_post_cannot_expose_a_stale_anonymous_poster_id(): void
    {
        $this->app();
        $this->database()->table('discussions')->where('id', 2)->update(['last_post_id' => null, 'last_posted_user_id' => 2]);
        $json = $this->json($this->api('GET', '/api/discussions/2?include=lastPostedUser,firstPost.user'));
        $this->assertNoAuthor($json);
        $this->assertNull($json['data']['relationships']['lastPostedUser']['data'] ?? null);
        $this->assertSame('3', $json['data']['relationships']['user']['data']['id']);
    }

    public function test_posting_permissions_are_independent_and_required(): void
    {
        $this->app();
        $permissions = $this->database()->table('group_permission');
        $permissions->where('permission', 'anonymous.startDiscussion')->delete();
        // Permission cache is populated during boot; explicitly refresh it.
        $this->app()->getContainer()->make(\Flarum\Group\PermissionCache::class)->flush();
        $this->assertSame(403, $this->api('POST', '/api/discussions', 2, ['type' => 'discussions', 'attributes' => ['title' => 'Forbidden anonymous start', 'content' => 'Content', 'isAnonymous' => true]])->getStatusCode());
        $this->json($this->api('POST', '/api/posts', 2, ['type' => 'posts', 'attributes' => ['content' => 'Still allowed reply', 'isAnonymous' => true], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]), 201);
    }

    public function test_anonymous_mentions_and_subscriptions_deliver_privacy_safe_notifications(): void
    {
        $this->prepareDatabase(['discussion_user' => [['discussion_id' => 2, 'user_id' => 4, 'subscription' => 'follow', 'last_read_post_number' => 1]]]);
        $this->json($this->api('POST', '/api/posts', 2, ['type' => 'posts', 'attributes' => ['content' => '@"reader"#p3 @"other_reader" hello', 'isAnonymous' => true], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]), 201);
        foreach ([3, 4] as $recipient) {
            $json = $this->json($this->api('GET', '/api/notifications?include=fromUser,subject,subject.user,subject.discussion.user,subject.lastPostedUser', $recipient));
            $this->assertNotEmpty($json['data']);
            $this->assertNoAuthor($json);
            foreach ($json['data'] as $notification) {
                $this->assertTrue($notification['attributes']['isAnonymous']);
                $this->assertNull($notification['relationships']['fromUser']['data'] ?? null);
                $row = $this->database()->table('notifications')->find($notification['id']);
                $this->assertNull($row->from_user_id);
                $this->assertStringNotContainsString('secret_author_829', $row->data);
            }
        }
    }

    public function test_legacy_notification_content_is_redacted_at_api_boundary(): void
    {
        $this->app();
        $this->database()->table('notifications')->insert(['user_id' => 3, 'from_user_id' => 2, 'type' => 'userMentioned', 'subject_id' => 2,
            'data' => json_encode(['username' => 'secret_author_829', 'userId' => 2]), 'created_at' => '2026-10-03 00:00:00', 'is_deleted' => false]);
        $json = $this->json($this->api('GET', '/api/notifications', 3));
        $this->assertNotEmpty($json['data']);
        $this->assertNoAuthor($json);
    }

    public function test_original_queued_blueprints_render_safe_emails_at_delivery(): void
    {
        $container = $this->app()->getContainer();
        $messages = [];
        $container->make(\Illuminate\Contracts\Events\Dispatcher::class)->listen(\Illuminate\Mail\Events\MessageSent::class, function ($event) use (&$messages) {
            $messages[] = $event->message;
        });
        $source = Post::query()->findOrFail(2);
        $recipient = User::query()->findOrFail(3);
        $mailer = $container->make(\Flarum\Notification\NotificationMailer::class);
        foreach ([new \Flarum\Subscriptions\Notification\NewPostBlueprint($source),
            new \Flarum\Mentions\Notification\UserMentionedBlueprint($source),
            new \Flarum\Mentions\Notification\PostMentionedBlueprint(Post::query()->findOrFail(3), $source)] as $blueprint) {
            $mailer->send($blueprint, $recipient);
        }
        $this->assertCount(3, $messages);
        foreach ($messages as $message) {
            $this->assertStringContainsString('Anonymous', $message->getSubject());
            $this->assertStringContainsString('anonymous user', $message->getTextBody());
            foreach ([$message->getTextBody(), $message->getHtmlBody(), $message->getSubject()] as $text) {
                $this->assertStringNotContainsString('secret_author_829', $text);
                $this->assertStringNotContainsString('secret829@example.test', $text);
            }
        }
    }

    public function test_late_email_jobs_cannot_reveal_the_author_of_a_deleted_source(): void
    {
        $container = $this->app()->getContainer();
        $messages = [];
        $container->make(\Illuminate\Contracts\Events\Dispatcher::class)->listen(\Illuminate\Mail\Events\MessageSent::class, function ($event) use (&$messages) {
            $messages[] = $event->message;
        });
        $source = Post::query()->findOrFail(2);
        $source->load('user', 'discussion');
        $originals = [new \Flarum\Subscriptions\Notification\NewPostBlueprint($source),
            new \Flarum\Mentions\Notification\UserMentionedBlueprint($source),
            new \Flarum\Mentions\Notification\PostMentionedBlueprint(Post::query()->findOrFail(3), $source)];
        $safe = $container->make(\AdminLby\Anonymous\Notification\ProtectBlueprint::class)($originals[1]);
        $this->database()->table('posts')->where('id', 2)->delete();
        $mailer = $container->make(\Flarum\Notification\NotificationMailer::class);
        $recipient = User::query()->findOrFail(3);
        foreach ([...$originals, $safe] as $blueprint) $mailer->send($blueprint, $recipient);
        $this->assertSame([], $messages, 'Deleted source posts must not deliver stale original or anonymized blueprints');
    }

    public static function legacyDeletedSources(): array { return [['postMentioned', 3, ['discussionId' => 1, 'replyNumber' => 2]], ['newPost', 1, ['postNumber' => 2]]]; }

    #[DataProvider('legacyDeletedSources')]
    public function test_legacy_notifications_with_deleted_sources_do_not_expose_cached_senders(string $type, int $subject, array $data): void
    {
        $this->app();
        $this->database()->table('notifications')->insert(['user_id' => 3, 'from_user_id' => 2, 'type' => $type, 'subject_id' => $subject,
            'data' => json_encode($data + ['username' => 'secret_author_829', 'userId' => 2]), 'created_at' => '2026-10-03 00:00:00', 'is_deleted' => false]);
        $this->database()->table('posts')->where('id', 2)->delete();
        $json = $this->json($this->api('GET', '/api/notifications', 3));
        $this->assertNotEmpty($json['data']);
        $this->assertNoAuthor($json);
        $this->assertTrue($json['data'][0]['attributes']['isAnonymous']);
    }

    public function test_anonymous_mentions_are_deleted_even_after_the_source_row_is_gone(): void
    {
        $container = $this->app()->getContainer();
        $blueprint = new \Flarum\Mentions\Notification\PostMentionedBlueprint(Post::query()->findOrFail(3), Post::query()->findOrFail(2));
        $syncer = $container->make(\Flarum\Notification\NotificationSyncer::class);
        $syncer->sync($blueprint, [User::query()->findOrFail(3)]);
        $row = $this->database()->table('notifications')->where('type', 'postMentioned')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->from_user_id);
        $this->assertFalse((bool) $row->is_deleted);
        $this->database()->table('posts')->where('id', 2)->delete();
        $syncer->delete($blueprint);
        $this->assertTrue((bool) $this->database()->table('notifications')->find($row->id)->is_deleted);
    }

    public function test_reveal_audit_retains_real_id_but_limited_audit_viewers_cannot_correlate(): void
    {
        $this->prepareDatabase(['group_permission' => [['group_id' => 3, 'permission' => 'flarum-audit.viewLimited']]]);
        $this->json($this->api('POST', '/api/anonymous/posts/2/reveal', 5));
        $row = $this->database()->table('audit_log')->where('action', 'anonymous.author_revealed')->first();
        $this->assertNotNull($row);
        $this->assertSame(2, json_decode($row->payload, true)['user_id']);
        $this->assertSame(5, json_decode($row->payload, true)['revealed_by']);
        $json = $this->json($this->api('GET', '/api/audit', 3));
        $this->assertNoAuthor($json);
        $this->assertNotContains('anonymous.author_revealed', array_column(array_column($json['data'], 'attributes'), 'action'));
    }

    public function test_discussion_rename_event_cannot_reveal_anonymous_starter(): void
    {
        $this->json($this->api('PATCH', '/api/discussions/1', 2, ['type' => 'discussions', 'id' => '1', 'attributes' => ['title' => 'Anonymous renamed fixture']]));
        $json = $this->json($this->api('GET', '/api/posts?filter[discussion]=1'));
        $this->assertNoAuthor($json);
        $events = array_filter($json['data'], fn ($post) => $post['attributes']['contentType'] === 'discussionRenamed');
        $this->assertNotEmpty($events);
        foreach ($events as $post) $this->assertTrue($post['attributes']['isAnonymous']);
    }

    public function test_listing_twenty_distinct_authors_does_not_add_one_query_per_resource(): void
    {
        $users = $discussions = $posts = [];
        for ($id = 20; $id < 40; $id++) {
            $users[] = ['id' => $id, 'username' => 'visible_author_'.$id, 'email' => 'author'.$id.'@example.test', 'is_email_confirmed' => 1];
            $discussions[] = ['id' => $id, 'title' => 'Visible discussion '.$id, 'user_id' => $id, 'first_post_id' => $id, 'last_post_id' => $id, 'last_posted_user_id' => $id, 'comment_count' => 1, 'last_post_number' => 1];
            $posts[] = ['id' => $id, 'discussion_id' => $id, 'number' => 1, 'type' => 'comment', 'user_id' => $id, 'content' => '<t>Visible content</t>'];
        }
        $this->prepareDatabase([User::class => $users, Discussion::class => $discussions, Post::class => $posts]);
        $json = $this->json($this->api('GET', '/api/discussions?page[limit]=30&include=user,lastPostedUser,firstPost.user,lastPost.user', 1));
        $this->assertCount(22, $json['data']);
        $this->assertNoAuthor($json);
    }

    public function test_core_model_writes_cannot_change_post_anonymity(): void
    {
        $this->app();
        $post = Post::query()->findOrFail(2);
        $post->is_anonymous = false;
        $this->expectException(\Flarum\Foundation\ValidationException::class);
        $post->save();
    }

    public function test_quote_and_reply_settings_are_independent(): void
    {
        $this->setting(Settings::PREFIX.'replies', false);
        $this->json($this->api('POST', '/api/posts', 2, ['type' => 'posts', 'attributes' => ['content' => '> A quote still allowed', 'isAnonymous' => true], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]), 201);
    }

    public function test_reveal_respects_visibility_and_handles_a_deleted_user(): void
    {
        $this->assertSame(403, $this->api('POST', '/api/anonymous/posts/3/reveal', 5)->getStatusCode());
        $this->assertSame(404, $this->api('POST', '/api/anonymous/posts/999/reveal', 5)->getStatusCode());
        $this->database()->table('posts')->where('id', 2)->update(['is_private' => true]);
        $this->assertSame(404, $this->api('POST', '/api/anonymous/posts/2/reveal', 5)->getStatusCode());
        $this->database()->table('posts')->where('id', 2)->update(['is_private' => false]);
        $this->database()->table('users')->where('id', 2)->delete();
        $json = $this->json($this->api('POST', '/api/anonymous/posts/2/reveal', 5));
        $this->assertTrue($json['data']['attributes']['deleted']);
        $this->assertSame(2, $json['data']['attributes']['userId']);
        $this->assertNoAuthor($this->json($this->api('GET', '/api/posts/2')));
    }

    public static function invalidFlags(): array { return [['true'], [1], [['user_id' => 2]]]; }

    #[DataProvider('invalidFlags')]
    public function test_anonymous_flag_rejects_non_boolean_input(mixed $flag): void
    {
        $response = $this->api('POST', '/api/posts', 2, ['type' => 'posts', 'attributes' => ['content' => 'Invalid flag', 'isAnonymous' => $flag], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]);
        $this->assertContains($response->getStatusCode(), [400, 422], (string) $response->getBody());
        $this->assertSame(3, $this->database()->table('posts')->count());
    }

    public function test_profile_counter_sorting_uses_only_named_activity(): void
    {
        $this->app();
        $this->database()->table('users')->where('id', 2)->update(['comment_count' => 900000, 'discussion_count' => 900000]);
        foreach (['-commentCount', '-discussionCount'] as $sort) {
            $json = $this->json($this->api('GET', '/api/users?sort='.$sort, 1));
            $this->assertSame('3', $json['data'][0]['id'], 'Anonymous activity cannot change public profile ranking');
            $secret = array_values(array_filter($json['data'], fn ($user) => $user['id'] === '2'))[0];
            $this->assertSame(0, $secret['attributes']['commentCount']);
            $this->assertSame(0, $secret['attributes']['discussionCount']);
        }
    }

    public function test_ssr_and_preload_use_redacted_resources(): void
    {
        $response = $this->api('GET', '/d/1', 0);
        $body = (string) $response->getBody();
        $this->assertSame(200, $response->getStatusCode(), substr($body, 0, 500));
        $this->assertStringNotContainsString('secret_author_829', $body);
        $this->assertStringContainsString('Anonymous user', $body);
        $this->assertSame(1, preg_match('/<script id="flarum-json-payload"[^>]*>(.*?)<\/script>/s', $body, $matches));
        $payload = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertNoAuthor($payload['apiDocument']);
    }

    public function test_real_participant_counts_are_not_an_author_correlation_oracle(): void
    {
        $this->app();
        $this->database()->table('discussions')->where('id', 1)->update(['participant_count' => 829]);
        foreach (['/api/discussions/1', '/api/discussions/1?fields[discussions]=participantCount'] as $url) {
            $json = $this->json($this->api('GET', $url));
            $this->assertArrayNotHasKey('participantCount', $json['data']['attributes'] ?? []);
        }
        $this->assertSame(829, (int) $this->database()->table('discussions')->find(1)->participant_count);
    }

    public function test_discussion_switch_is_independent_of_reply_switch(): void
    {
        $this->setting(Settings::PREFIX.'discussions', false);
        $this->assertSame(403, $this->api('POST', '/api/discussions', 2, ['type' => 'discussions', 'attributes' => [
            'title' => 'Disabled anonymous discussion', 'content' => 'Content', 'isAnonymous' => true,
        ]])->getStatusCode());
        $this->json($this->api('POST', '/api/posts', 2, ['type' => 'posts', 'attributes' => ['content' => 'Anonymous reply remains enabled', 'isAnonymous' => true],
            'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]), 201);
    }

    public function test_missing_reply_permission_blocks_quotes_but_allows_anonymous_discussions(): void
    {
        $this->app();
        $this->database()->table('group_permission')->where('permission', 'discussion.anonymousReply')->delete();
        $this->app()->getContainer()->make(\Flarum\Group\PermissionCache::class)->flush();
        $this->assertSame(403, $this->api('POST', '/api/posts', 2, ['type' => 'posts', 'attributes' => ['content' => '> Quoted reply', 'isAnonymous' => true],
            'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]])->getStatusCode());
        $this->json($this->api('POST', '/api/discussions', 2, ['type' => 'discussions', 'attributes' => [
            'title' => 'Anonymous discussion still allowed', 'content' => 'Content', 'isAnonymous' => true,
        ]]), 201);
    }

    public function test_missing_first_post_does_not_force_new_replies_to_inherit_anonymity(): void
    {
        $this->setting(Settings::PREFIX.'replies', false);
        $this->app();
        $this->database()->table('posts')->where('discussion_id', 1)->delete();
        $this->database()->table('discussions')->where('id', 1)->update(['first_post_id' => null, 'last_post_id' => null]);
        $data = ['type' => 'posts', 'attributes' => ['content' => 'Explicit named reply', 'isAnonymous' => false],
            'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]];
        $json = $this->json($this->api('POST', '/api/posts', 3, $data), 201);
        $this->assertFalse($json['data']['attributes']['isAnonymous']);
        $data['attributes']['isAnonymous'] = true;
        $this->assertSame(403, $this->api('POST', '/api/posts', 4, $data)->getStatusCode());
    }

    public static function otherTypes(): array { return [[true], [false]]; }

    #[DataProvider('otherTypes')]
    public function test_registered_user_submission_types_obey_their_own_switch(bool $enabled): void
    {
        $this->setting(Settings::PREFIX.'other', $enabled);
        $this->extend((new \Flarum\Extend\Post())->type(\AdminLby\Anonymous\Tests\Support\SubmissionPost::class));
        $container = $this->app()->getContainer();
        $post = new \AdminLby\Anonymous\Tests\Support\SubmissionPost();
        $post->discussion_id = 2;
        $post->user_id = 2;
        $post->content = 'Custom user submission';
        $post->created_at = \Carbon\Carbon::now();
        if (! $enabled) $this->expectException(\Flarum\User\Exception\PermissionDeniedException::class);
        $container->make(\Illuminate\Contracts\Events\Dispatcher::class)->dispatch(new \Flarum\Post\Event\Saving(
            $post, User::query()->findOrFail(2), ['attributes' => ['isAnonymous' => true, 'content' => $post->content]]
        ));
        $post->save();
        $json = $this->json($this->api('GET', '/api/posts/'.$post->id));
        $this->assertTrue($json['data']['attributes']['isAnonymous']);
        $this->assertNoAuthor($json);
        $this->assertSame(2, $post->user_id);
        $this->assertSame(2, $post->anonymous_author_id);
    }

    public function test_unknown_anonymous_post_notification_types_are_suppressed(): void
    {
        $container = $this->app()->getContainer();
        $protect = $container->make(\AdminLby\Anonymous\Notification\ProtectBlueprint::class);
        $this->assertNull($protect(new \AdminLby\Anonymous\Tests\Support\UnknownPostBlueprint(Post::query()->findOrFail(2))));
        $named = new \AdminLby\Anonymous\Tests\Support\UnknownPostBlueprint(Post::query()->findOrFail(3));
        $this->assertSame($named, $protect($named));
    }

    public function test_explicit_named_custom_submission_does_not_inherit_automatic_event_anonymity(): void
    {
        $this->setting(Settings::PREFIX.'other', false);
        $this->extend((new \Flarum\Extend\Post())->type(\AdminLby\Anonymous\Tests\Support\SubmissionPost::class));
        $container = $this->app()->getContainer();
        $post = new \AdminLby\Anonymous\Tests\Support\SubmissionPost();
        $post->discussion_id = 1;
        $post->user_id = 2;
        $post->content = 'The anonymous starter explicitly chose a named custom submission';
        $post->created_at = \Carbon\Carbon::now();
        $container->make(\Illuminate\Contracts\Events\Dispatcher::class)->dispatch(new \Flarum\Post\Event\Saving(
            $post, User::query()->findOrFail(2), ['attributes' => ['isAnonymous' => false]]
        ));
        $post->save();
        $this->assertFalse($post->is_anonymous);
        $this->assertNull($post->anonymous_author_id);
        $json = $this->json($this->api('GET', '/api/posts/'.$post->id));
        $this->assertSame('2', $json['data']['relationships']['user']['data']['id']);
    }

    public function test_private_original_author_reference_is_immutable(): void
    {
        $this->app();
        $post = Post::query()->findOrFail(2);
        $post->anonymous_author_id = 3;
        $this->expectException(\Flarum\Foundation\ValidationException::class);
        $post->save();
    }

    public function test_unregistered_post_types_still_protect_linkage_and_audit_metadata(): void
    {
        $this->prepareDatabase(['group_permission' => [['group_id' => 3, 'permission' => 'flarum-audit.viewLimited']]]);
        $this->app();
        $this->database()->table('posts')->insert(['id' => 999, 'discussion_id' => 2, 'number' => 2, 'type' => 'disabledThirdPartyType',
            'user_id' => 2, 'anonymous_author_id' => 2, 'content' => 'Archived anonymous submission', 'is_anonymous' => true, 'created_at' => '2026-10-01 03:00:00']);
        $linkage = new Post();
        $linkage->id = 999;
        $linkage->exists = true;
        $this->assertTrue(\AdminLby\Anonymous\Privacy::post($linkage));
        $id = $this->database()->table('audit_log')->insertGetId(['actor_id' => 2, 'action' => 'post.created', 'client' => 'session',
            'payload' => json_encode(['post_id' => 999, 'discussion_id' => 2]), 'created_at' => '2026-10-01 03:00:00']);
        $json = $this->json($this->api('GET', '/api/audit', 3));
        $this->assertNotContains((string) $id, array_map('strval', array_column($json['data'], 'id')));
        $this->assertNoAuthor($json);
    }

    public function test_deleted_linkage_models_cannot_fall_back_to_cached_real_authors(): void
    {
        $this->app();
        $partial = new Post();
        $partial->id = 2;
        $partial->user_id = 2;
        $partial->exists = true;
        $partial->setRelation('user', User::query()->findOrFail(2));
        $this->database()->table('posts')->where('id', 2)->delete();
        $this->assertTrue(\AdminLby\Anonymous\Privacy::post($partial));
        $discussion = new Discussion();
        $discussion->id = 999999;
        $discussion->user_id = 2;
        $discussion->exists = true;
        $discussion->setRelation('user', User::query()->findOrFail(2));
        $this->assertTrue(\AdminLby\Anonymous\Privacy::discussion($discussion));
    }

    public function test_creation_and_reveal_work_without_optional_extensions(): void
    {
        $this->extensions = ['flarum-lang-english', 'adminlby-anonymous'];
        $json = $this->json($this->api('POST', '/api/discussions', 2, ['type' => 'discussions', 'attributes' => [
            'title' => 'Minimal anonymous discussion', 'content' => 'No optional integrations', 'isAnonymous' => true,
        ]]), 201);
        $this->assertNoAuthor($json);
        $first = $this->database()->table('discussions')->find($json['data']['id'])->first_post_id;
        $reveal = $this->json($this->api('POST', '/api/anonymous/posts/'.$first.'/reveal', 5));
        $this->assertSame(2, $reveal['data']['attributes']['userId']);
    }
}
