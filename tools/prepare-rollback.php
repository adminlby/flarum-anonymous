<?php

// Run this from the NEW source tree before restoring its .old sibling.
// This changes only the rollback extension's own files, never vendor.
try {
    if (PHP_SAPI !== 'cli' || $argc !== 2) throw new RuntimeException('Usage: php tools/prepare-rollback.php /absolute/path/flarum-anonymous.old');
    $current = realpath(dirname(__DIR__));
    $target = realpath($argv[1]);
    $expected = $current ? dirname($current).DIRECTORY_SEPARATOR.basename($current).'.old' : null;
    if (! $target || $target !== $expected || is_link($argv[1])) throw new RuntimeException('Only the current extension\'s exact .old sibling is allowed.');
    $composer = json_decode(file_get_contents($target.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($composer['name'] ?? null) !== 'adminlby/flarum-anonymous' || ! is_file($target.'/extend.php')) throw new RuntimeException('Invalid rollback extension.');
    foreach (['extend.php', 'extend.before-rollback-privacy.php', 'extend.rollback-new.php', 'tools', 'tools/rollback-privacy.php', 'tools/rollback-backups', 'tools/rollback-backups/Compatibility', 'src', 'src/Privacy.php', 'src/Compatibility'] as $path) {
        if (is_link($target.'/'.$path)) throw new RuntimeException('Rollback paths must not contain symbolic links.');
    }
    $original = $target.'/extend.before-rollback-privacy.php';
    $wrapper = "<?php\n\$legacy = require __DIR__.'/extend.before-rollback-privacy.php';\nreturn array_merge(is_array(\$legacy) ? \$legacy : [\$legacy], require __DIR__.'/tools/rollback-privacy.php');\n";
    if (file_exists($original) && file_get_contents($target.'/extend.php') !== $wrapper) throw new RuntimeException('An unrelated rollback backup already exists; refusing to overwrite it.');
    if (! is_dir($target.'/tools') && ! mkdir($target.'/tools', 0755)) throw new RuntimeException('Cannot create rollback tools directory.');
    foreach (['src/Compatibility', 'tools/rollback-backups/Compatibility'] as $path) {
        if (! is_dir($target.'/'.$path) && ! mkdir($target.'/'.$path, 0755, true)) throw new RuntimeException('Cannot create rollback compatibility directory.');
    }
    $files = [dirname(__DIR__).'/src/Privacy.php' => 'src/Privacy.php'];
    foreach (glob(dirname(__DIR__).'/src/Compatibility/*.php') as $file) $files[$file] = 'src/Compatibility/'.basename($file);
    foreach ($files as $source => $path) {
        $destination = $target.'/'.$path;
        $backup = $target.'/tools/rollback-backups/'.substr($path, 4).'.txt';
        if (is_link($destination) || is_link($backup)) throw new RuntimeException('Rollback files must not be symbolic links.');
        if (is_file($destination) && ! file_exists($backup) && ! copy($destination, $backup)) throw new RuntimeException('Cannot preserve rollback compatibility file.');
        if (! is_file($source) || ! copy($source, $destination)) throw new RuntimeException('Cannot retain the rollback compatibility bundle.');
    }
    if (! file_exists($original) && ! copy($target.'/extend.php', $original)) throw new RuntimeException('Cannot preserve the previous extend.php.');
    if (! copy(__DIR__.'/rollback-privacy.php', $target.'/tools/rollback-privacy.php')) throw new RuntimeException('Cannot install the rollback privacy guard.');
    $temporary = $target.'/extend.rollback-new.php';
    if (file_put_contents($temporary, $wrapper) !== strlen($wrapper) || ! rename($temporary, $target.'/extend.php')) throw new RuntimeException('Cannot install rollback entry point.');
    echo "Rollback privacy guard and optional compatibility bundle prepared. Keep the anonymous database metadata and reinstall the previous extension normally.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
