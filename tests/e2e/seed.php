<?php

// This destructive fixture is restricted to the explicitly named test database.
try {
$sitePath = getenv('ANONYMOUS_E2E_SITE') ?: __DIR__.'/../../.workbench/site';
$config = require $sitePath.'/config.php';
if (! str_starts_with($config['database']['database'] ?? '', 'anonymous_extension_test')) {
    throw new RuntimeException('E2E fixtures require a dedicated anonymous_extension_test* database.');
}
$site = require $sitePath.'/site.php';
$container = $site->bootApp()->getContainer();
$db = $container->make(\Illuminate\Database\ConnectionInterface::class);
$db->getSchemaBuilder()->disableForeignKeyConstraints();

foreach (['anonymous_edit_history', 'post_edit_histories', 'post_edit_histories_archive', 'audit_log', 'notifications', 'post_mentions_post', 'post_mentions_user', 'post_mentions_group', 'discussion_user', 'discussion_tag', 'poll_votes', 'poll_options', 'polls', 'leaderboard_points', 'leaderboard_user_totals', 'posts', 'discussions', 'access_tokens'] as $table) {
    if ($db->getSchemaBuilder()->hasTable($table)) $db->table($table)->delete();
}
foreach ([2 => 'secret_author_829', 3 => 'reader', 4 => 'quote_reader', 5 => 'toggle_reader', 6 => 'named_reader'] as $id => $username) {
    $db->table('users')->updateOrInsert(['id' => $id], ['username' => $username, 'email' => $username.'@example.test',
        'password' => password_hash('anonymous-browser-test-only', PASSWORD_BCRYPT), 'is_email_confirmed' => true,
        'joined_at' => '2026-10-01 00:00:00', 'preferences' => '{}', 'discussion_count' => 0, 'comment_count' => 0]);
}
$db->table('discussions')->insert([
    ['id' => 1, 'title' => 'Anonymous fixture', 'slug' => 'anonymous-fixture', 'user_id' => 2, 'first_post_id' => 1, 'last_post_id' => 2, 'last_posted_user_id' => 2, 'comment_count' => 2, 'participant_count' => 1, 'last_post_number' => 2, 'is_anonymous' => true,
        'created_at' => '2026-10-01 01:00:00', 'last_posted_at' => '2026-10-01 02:00:00'],
    ['id' => 2, 'title' => 'Named fixture', 'slug' => 'named-fixture', 'user_id' => 3, 'first_post_id' => 3, 'last_post_id' => 3, 'last_posted_user_id' => 3, 'comment_count' => 1, 'participant_count' => 1, 'last_post_number' => 1, 'is_anonymous' => false,
        'created_at' => '2026-10-01 01:00:00', 'last_posted_at' => '2026-10-01 01:00:00'],
]);
$db->table('posts')->insert([
    ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'type' => 'comment', 'user_id' => 2, 'anonymous_author_id' => 2, 'content' => '<t>Anonymous initial content</t>', 'is_anonymous' => true, 'created_at' => '2026-10-01 01:00:00'],
    ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'type' => 'comment', 'user_id' => 2, 'anonymous_author_id' => 2, 'content' => '<t>Anonymous reply content</t>', 'is_anonymous' => true, 'created_at' => '2026-10-01 02:00:00'],
    ['id' => 3, 'discussion_id' => 2, 'number' => 1, 'type' => 'comment', 'user_id' => 3, 'anonymous_author_id' => null, 'content' => '<t>Named initial content</t>', 'is_anonymous' => false, 'created_at' => '2026-10-01 01:00:00'],
]);
$settings = $container->make(\Flarum\Settings\SettingsRepositoryInterface::class);
foreach (['enabled', 'discussions', 'replies', 'quotes', 'other', 'showOwn'] as $key) $settings->set('adminlby-anonymous.'.$key, '1');
$settings->set('adminlby-anonymous.mode', 'ask');
$settings->set('adminlby-anonymous.editHistoryChoice', '0');
$settings->set('allow_post_editing', '-1');
$settings->set('min_primary_tags', '0');
$settings->set('min_secondary_tags', '0');
if ($db->getSchemaBuilder()->hasTable('polls')) {
    $poll = \FoF\Polls\Poll::build('Anonymous fixture poll', 1, 2, null, true);
    $poll->save();
    $option = \FoF\Polls\PollOption::build('Fixture option');
    $poll->options()->save($option);
    \FoF\Polls\PollVote::build($poll->id, 2, $option->id)->save();
    \FoF\Polls\PollVote::build($poll->id, 3, $option->id)->save();
}
$tokens = [];
foreach (['admin' => 1, 'author' => 2, 'reader' => 3] as $name => $id) {
    $token = \Flarum\Http\SessionAccessToken::generate($id);
    $token->save();
    $tokens[$name] = $token->token;
}
file_put_contents($sitePath.'/e2e-tokens.json', json_encode($tokens, JSON_THROW_ON_ERROR));
$db->getSchemaBuilder()->enableForeignKeyConstraints();
echo "Isolated E2E fixture ready.\n";
} catch (\Throwable $error) {
    fwrite(STDERR, (string) $error."\n");
    exit(1);
}
