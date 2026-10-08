<?php

namespace AdminLby\Anonymous\Tests\integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class LocaleTest extends TestCase
{
    public function test_all_three_locales_have_identical_nonempty_keys_and_cover_the_ui(): void
    {
        $flatten = function (array $values, string $prefix = '') use (&$flatten): array {
            $result = [];
            foreach ($values as $key => $value) {
                $name = $prefix.$key;
                if (is_array($value)) $result += $flatten($value, $name.'.');
                else $result[$name] = $value;
            }
            return $result;
        };
        $catalogues = [];
        foreach (['en', 'zh-Hans', 'zh-Hant'] as $locale) {
            $catalogues[$locale] = $flatten(Yaml::parseFile(__DIR__.'/../../locale/'.$locale.'.yml'));
            foreach ($catalogues[$locale] as $value) $this->assertNotEmpty($value);
        }
        $this->assertSame(array_keys($catalogues['en']), array_keys($catalogues['zh-Hans']));
        $this->assertSame(array_keys($catalogues['en']), array_keys($catalogues['zh-Hant']));
        foreach (['js/src', 'src', 'views'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../'.$directory));
            foreach ($files as $file) {
                if (! $file->isFile()) continue;
                preg_match_all('/adminlby-anonymous\.(?:forum|admin|api|email|audit)\.[\w.]+/', file_get_contents($file->getPathname()), $matches);
                foreach ($matches[0] as $key) {
                    if (str_ends_with($key, '.')) continue; // Dynamic keys have a fixed prefix.
                    $this->assertArrayHasKey($key, $catalogues['en'], $file->getPathname());
                }
            }
        }
    }
}
