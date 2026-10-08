<?php

// Exercises real core CLI commands against an explicitly isolated test site.
// Never use this fixture tool to operate a production forum.
try {
    $sitePath = realpath(getenv('ANONYMOUS_E2E_SITE') ?: __DIR__.'/../.workbench/site');
    if (! $sitePath || ! is_file($sitePath.'/config.php')) {
        throw new RuntimeException('Set ANONYMOUS_E2E_SITE to an installed isolated test site.');
    }
    $config = require $sitePath.'/config.php';
    if (! str_starts_with($config['database']['database'] ?? '', 'anonymous_extension_test')) {
        throw new RuntimeException('Lifecycle checks require an anonymous_extension_test* database.');
    }
    $site = require $sitePath.'/site.php';
    $container = $site->bootApp()->getContainer();
    $db = $container->make(\Illuminate\Database\ConnectionInterface::class);
    $schema = $db->getSchemaBuilder();
    $manager = $container->make(\Flarum\Extension\ExtensionManager::class);
    if (! $manager->isEnabled('adminlby-anonymous')) {
        throw new RuntimeException('Enable the extension before running this test.');
    }

    $posts = $db->table('posts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $discussions = $db->table('discussions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $users = $db->table('users')->count();
    $editPrivacy = $db->table('anonymous_edit_history')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $nativeHistories = $schema->hasTable('post_edit_histories') ? $db->table('post_edit_histories')->orderBy('id')->get()->toJson() : null;
    $permissionNames = ['anonymous.startDiscussion', 'discussion.anonymousReply', 'anonymous.reveal', 'anonymous.changeIdentity'];
    $permissions = $db->table('group_permission')->whereIn('permission', $permissionNames)->get()->map(fn ($row) => (array) $row)->all();
    $baseRows = function (string $table) use ($db): array {
        return $db->table($table)->orderBy('id')->get()->map(function ($row) {
            $row = (array) $row;
            unset($row['is_anonymous'], $row['anonymous_author_id'], $row['last_edit_is_anonymous']);
            return $row;
        })->all();
    };
    $basePosts = $baseRows('posts');
    $baseDiscussions = $baseRows('discussions');
    $check = function (bool $value, string $description): void {
        if (! $value) throw new RuntimeException($description);
        echo 'PASS '.$description."\n";
    };
    $cli = function (array $arguments) use ($sitePath): void {
        $process = new \Symfony\Component\Process\Process([PHP_BINARY, $sitePath.'/flarum', ...$arguments], $sitePath);
        $process->setTimeout(120);
        $process->mustRun();
        echo $process->getOutput();
    };
    $coreHttp = function () use ($config, $posts, $check): void {
        $host = parse_url($config['url'], PHP_URL_HOST);
        if (! in_array($host, ['localhost', '127.0.0.1'], true) || ! $posts) return;
        $json = json_decode(file_get_contents($config['url'].'/api/posts/'.$posts[0]['id']), true, 512, JSON_THROW_ON_ERROR);
        $check(isset($json['data']['id']), 'Core JSON API still reads existing posts while the extension is disabled');
        $check(! isset($json['data']['attributes']['isAnonymous']), 'Disabled extension has no required serializer or fake-user dependency');
    };

    try {
        $cli(['extension:disable', 'adminlby-anonymous']);
        $check($baseRows('posts') === $basePosts && $baseRows('discussions') === $baseDiscussions, 'Disable preserves core posts, authors and discussions');
        $check($db->table('posts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all() === $posts, 'Disable preserves anonymous flags and original author references');
        $coreHttp();
        $cli(['extension:enable', 'adminlby-anonymous']);
        $check($db->table('posts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all() === $posts, 'Re-enable preserves existing anonymous content');
        $cli(['extension:disable', 'adminlby-anonymous']);
        $cli(['migrate:reset', '--extension=adminlby-anonymous']);
        $check(! $schema->hasColumn('posts', 'is_anonymous') && ! $schema->hasColumn('posts', 'anonymous_author_id')
            && ! $schema->hasColumn('discussions', 'is_anonymous') && ! $schema->hasColumn('posts', 'last_edit_is_anonymous')
            && ! $schema->hasTable('anonymous_edit_history'), 'Migration down removes only extension columns and private edit metadata');
        $check($baseRows('posts') === $basePosts && $baseRows('discussions') === $baseDiscussions && $db->table('users')->count() === $users,
            'Migration down preserves all core content and real user IDs');
        $coreHttp();
        $cli(['extension:enable', 'adminlby-anonymous']);
        $cli(['migrate']);
        $check($schema->hasColumn('posts', 'is_anonymous') && $schema->hasColumn('posts', 'anonymous_author_id')
            && $schema->hasColumn('discussions', 'is_anonymous') && $schema->hasColumn('posts', 'last_edit_is_anonymous')
            && $schema->hasTable('anonymous_edit_history'), 'Migration up reinstalls all extension columns and private edit metadata');
        $check($db->table('posts')->where('is_anonymous', true)->count() === 0, 'Fresh migration defaults existing content to named');
    } finally {
        // A reset deliberately discards extension metadata. Restore our test
        // snapshot so the preview site ends with its original privacy state.
        $enabled = json_decode($db->table('settings')->where('key', 'extensions_enabled')->value('value') ?? '[]', true, 512, JSON_THROW_ON_ERROR);
        if (! in_array('adminlby-anonymous', $enabled, true)) {
            $cli(['extension:enable', 'adminlby-anonymous']);
        }
        if (! $schema->hasColumn('posts', 'anonymous_author_id')) {
            $cli(['migrate']);
        }
        foreach ($posts as $row) $db->table('posts')->where('id', $row['id'])->update([
            'is_anonymous' => $row['is_anonymous'], 'anonymous_author_id' => $row['anonymous_author_id'],
            'last_edit_is_anonymous' => $row['last_edit_is_anonymous'],
        ]);
        $db->table('anonymous_edit_history')->delete();
        if ($editPrivacy) $db->table('anonymous_edit_history')->insert($editPrivacy);
        foreach ($discussions as $row) $db->table('discussions')->where('id', $row['id'])->update(['is_anonymous' => $row['is_anonymous']]);
        $db->table('group_permission')->whereIn('permission', $permissionNames)->delete();
        if ($permissions) $db->table('group_permission')->insert($permissions);
        $cli(['cache:clear']);
    }
    $check($baseRows('posts') === $basePosts && $baseRows('discussions') === $baseDiscussions, 'Round trip preserves every core column');
    $check($nativeHistories === null || $db->table('post_edit_histories')->orderBy('id')->get()->toJson() === $nativeHistories, 'Migration round trip preserves native Diff rows');
    $check($db->table('anonymous_edit_history')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all() === $editPrivacy, 'Private revision metadata restored after the destructive test reset');
    $check($db->table('posts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all() === $posts, 'Test snapshot restored without changing real user IDs');
    echo "Lifecycle and migration round trip passed.\n";
} catch (\Throwable $error) {
    fwrite(STDERR, (string) $error."\n");
    exit(1);
}
