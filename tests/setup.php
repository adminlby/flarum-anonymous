<?php

require __DIR__.'/bootstrap.php';

// The official setup script drops all tables. Defaults always point at a
// dedicated, ignored test SQLite database, never the host forum.
if (! getenv('DB_DRIVER')) {
    putenv('DB_DRIVER=sqlite');
}
if (! getenv('DB_DATABASE')) {
    $databasePath = __DIR__.'/tmp/anonymous-test.sqlite';
    if (! is_dir(dirname($databasePath))) {
        mkdir(dirname($databasePath), 0777, true);
    }
    if (! file_exists($databasePath)) {
        touch($databasePath);
    }
    putenv('DB_DATABASE='.$databasePath);
}
if (getenv('DB_DRIVER') !== 'sqlite' && ! str_starts_with(getenv('DB_DATABASE'), 'anonymous_extension_test')) {
    throw new RuntimeException('Use an isolated DB_DATABASE named anonymous_extension_test* for integration setup.');
}
(new Flarum\Testing\integration\Setup\SetupScript())->run();
