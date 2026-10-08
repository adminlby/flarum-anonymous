<?php

// Configure a local path repository without shell-specific JSON escaping.
try {
    $host = realpath($argv[1] ?? getcwd());
    $source = realpath(dirname(__DIR__));
    if (! $host || ! is_file($host.'/composer.json') || ! is_file($host.'/flarum')) {
        throw new RuntimeException('The first argument must be an installed Flarum root directory.');
    }
    $file = $host.'/composer.json';
    $original = file_get_contents($file);
    $composer = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
    $repository = [
        'type' => 'path', 'url' => str_replace('\\', '/', $source),
        'options' => ['symlink' => false, 'versions' => ['adminlby/flarum-anonymous' => 'dev-main']],
    ];
    // Composer accepts named repositories. Preserve existing entries and put
    // this checkout first so it has precedence over remote packages.
    $composer['repositories'] = ['anonymous' => $repository] + ($composer['repositories'] ?? []);
    if (! file_exists($file.'.anonymous-backup') && file_put_contents($file.'.anonymous-backup', $original) === false) {
        throw new RuntimeException('Unable to back up composer.json.');
    }
    if (file_put_contents($file, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n") === false) {
        throw new RuntimeException('Unable to write composer.json.');
    }
    echo "Local Anonymous Posting repository configured; composer.json.anonymous-backup preserves the previous file.\n";
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
