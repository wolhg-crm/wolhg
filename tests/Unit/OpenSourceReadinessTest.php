<?php

declare(strict_types=1);

/**
 * Guards that keep the public tree safe to publish and easy for other churches to install.
 */
return [
    'license is MIT and documented' => function (TestCase $test): void {
        $root = __DIR__ . '/../..';
        $test->assertFileExists($root . '/LICENSE');
        $license = (string) file_get_contents($root . '/LICENSE');
        $test->assertStringContains('MIT License', $license);
        $test->assertFileExists($root . '/SECURITY.md');
        $test->assertFileExists($root . '/CONTRIBUTING.md');

        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
        $test->assertTrue(is_array($composer));
        $test->assertSame('MIT', $composer['license'] ?? null);

        $readme = (string) file_get_contents($root . '/README.md');
        $test->assertStringContains('[MIT](LICENSE)', $readme);
        $test->assertStringNotContains('Слово Жизни Пушкино', $readme);
    },

    'install docs cover demo empty vendor and default password' => function (TestCase $test): void {
        $root = __DIR__ . '/../..';
        $test->assertFileExists($root . '/docs/install.md');
        $install = (string) file_get_contents($root . '/docs/install.md');
        $test->assertStringContains('password', $install);
        $test->assertStringContains('database/init/empty/002_seed.sql', $install);
        $test->assertStringContains('composer install', $install);
        $test->assertStringContains('vendor/', $install);

        $test->assertFileExists($root . '/database/init/empty/002_seed.sql');
        $empty = (string) file_get_contents($root . '/database/init/empty/002_seed.sql');
        $test->assertStringContains("'example'", $empty);
        $test->assertStringContains('INSERT INTO churches', $empty);
        $test->assertStringNotContains('INSERT INTO people', $empty);
        $test->assertStringNotContains('INSERT INTO home_groups', $empty);
        $test->assertFileExists($root . '/database/init/empty/README.md');
    },

    'demo seed is generic example church without Word of Life branding' => function (TestCase $test): void {
        $seed = (string) file_get_contents(__DIR__ . '/../../database/init/002_seed.sql');
        $test->assertStringContains("'example'", $seed);
        $test->assertStringContains('Примерная церковь', $seed);
        $test->assertStringNotContains('pushkino', $seed);
        $test->assertStringNotContains('korolev', $seed);
        $test->assertStringNotContains('Слово Жизни', $seed);
        $test->assertStringNotContains('skycrew42', $seed);
    },

    'church upload media is not tracked; only directory guards remain' => function (TestCase $test): void {
        $dir = __DIR__ . '/../../public/uploads/church';
        $test->assertFileExists($dir . '/.gitkeep');
        $test->assertFileExists($dir . '/.htaccess');
        $files = array_values(array_filter(scandir($dir) ?: [], static fn (string $n): bool => $n !== '.' && $n !== '..'));
        sort($files);
        $test->assertSame(['.gitkeep', '.htaccess'], $files);

        $gitignore = (string) file_get_contents(__DIR__ . '/../../.gitignore');
        $test->assertStringContains('/public/uploads/church/*', $gitignore);
        $test->assertStringContains('!/public/uploads/church/.gitkeep', $gitignore);
    },

    'github actions runs make test on php 8.5' => function (TestCase $test): void {
        $workflow = __DIR__ . '/../../.github/workflows/test.yml';
        $test->assertFileExists($workflow);
        $yaml = (string) file_get_contents($workflow);
        $test->assertStringContains("php-version: '8.5'", $yaml);
        $test->assertStringContains('make test', $yaml);
    },

    'default config no longer hardcodes a city church slug' => function (TestCase $test): void {
        $config = (string) file_get_contents(__DIR__ . '/../../config/app.php');
        $test->assertStringContains("'legacy_default_slug' => ''", $config);
        $test->assertStringContains('Примерная церковь', $config);
        $test->assertStringNotContains("'legacy_default_slug' => 'pushkino'", $config);
        $test->assertStringNotContains('Слово Жизни Пушкино', $config);

        $tenant = (string) file_get_contents(__DIR__ . '/../../src/ChurchTenant.php');
        $test->assertStringContains("return 'example'", $tenant);
        $test->assertStringNotContains("?: 'pushkino'", $tenant);
        $test->assertStringNotContains(": 'pushkino'", $tenant);

        $index = (string) file_get_contents(__DIR__ . '/../../public/index.php');
        $test->assertStringNotContains('Слово Жизни Пушкино', $index);
        $test->assertStringContains("app_config('default_church_name')", $index);
    },

    'legacy_default_slug resolver documents config then db then example' => function (TestCase $test): void {
        $source = (string) file_get_contents(__DIR__ . '/../../src/ChurchTenant.php');
        $test->assertStringContains("app_config('legacy_default_slug'", $source);
        $test->assertStringContains('ORDER BY id ASC LIMIT 1', $source);
        $test->assertStringContains("return 'example'", $source);
        $test->assertStringContains('COALESCE(is_demo, 0) = 0', $source);
    },
];
