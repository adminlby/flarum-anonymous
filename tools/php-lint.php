<?php

$root = dirname(__DIR__);
$paths = ["$root/extend.php"];
foreach (['src', 'migrations', 'tests', 'tools'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir")) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR)) {
            $paths[] = $file->getPathname();
        }
    }
}
$failed = 0;
foreach (array_unique($paths) as $path) {
    passthru(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path), $status);
    $failed += $status !== 0;
}
exit($failed ? 1 : 0);
