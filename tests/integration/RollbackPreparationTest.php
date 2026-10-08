<?php

namespace AdminLby\Anonymous\Tests\integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RollbackPreparationTest extends TestCase
{
    public function test_preparation_is_idempotent_and_rejects_directories_outside_its_exact_sibling(): void
    {
        $root = __DIR__.'/../tmp/rollback-'.bin2hex(random_bytes(4));
        mkdir($root.'/flarum-anonymous/tools', 0777, true);
        mkdir($root.'/flarum-anonymous/src/Compatibility', 0777, true);
        mkdir($root.'/flarum-anonymous.old');
        foreach (['prepare-rollback.php', 'rollback-privacy.php'] as $file) copy(__DIR__.'/../../tools/'.$file, $root.'/flarum-anonymous/tools/'.$file);
        copy(__DIR__.'/../../src/Privacy.php', $root.'/flarum-anonymous/src/Privacy.php');
        foreach (glob(__DIR__.'/../../src/Compatibility/*.php') as $file) copy($file, $root.'/flarum-anonymous/src/Compatibility/'.basename($file));
        mkdir($root.'/flarum-anonymous.old/src/Compatibility', 0777, true);
        file_put_contents($root.'/flarum-anonymous.old/src/Privacy.php', "<?php // Previous privacy implementation.\n");
        $privacy = hash_file('sha256', $root.'/flarum-anonymous.old/src/Privacy.php');
        file_put_contents($root.'/flarum-anonymous.old/composer.json', '{"name":"adminlby/flarum-anonymous"}');
        file_put_contents($root.'/flarum-anonymous.old/extend.php', "<?php return [];\n");
        $original = hash_file('sha256', $root.'/flarum-anonymous.old/extend.php');
        for ($i = 0; $i < 2; $i++) {
            $process = new Process([PHP_BINARY, $root.'/flarum-anonymous/tools/prepare-rollback.php', $root.'/flarum-anonymous.old']);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame($original, hash_file('sha256', $root.'/flarum-anonymous.old/extend.before-rollback-privacy.php'));
            $this->assertSame($privacy, hash_file('sha256', $root.'/flarum-anonymous.old/tools/rollback-backups/Privacy.php.txt'));
            $this->assertSame(hash_file('sha256', __DIR__.'/../../src/Compatibility/Extenders.php'),
                hash_file('sha256', $root.'/flarum-anonymous.old/src/Compatibility/Extenders.php'));
        }
        $process = new Process([PHP_BINARY, $root.'/flarum-anonymous/tools/prepare-rollback.php', $root.'/flarum-anonymous']);
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('exact .old sibling', $process->getErrorOutput());
    }
}
