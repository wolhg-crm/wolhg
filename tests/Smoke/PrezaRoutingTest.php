<?php

declare(strict_types=1);

return [
    'preza presentation routing is reserved and served as static folder' => function (TestCase $test): void {
        $tenant = file_get_contents(__DIR__ . '/../../src/ChurchTenant.php');
        $rootHtaccess = file_get_contents(__DIR__ . '/../../.htaccess');
        $publicHtaccess = file_get_contents(__DIR__ . '/../../public/.htaccess');
        $apache = file_get_contents(__DIR__ . '/../../apache.conf');
        $appJs = file_get_contents(__DIR__ . '/../../public/assets/app.js');

        $test->assertStringContains("'preza'", $tenant);
        $test->assertTrue(function_exists('church_slug_is_valid') || str_contains($tenant, 'function church_slug_is_valid'));
        $test->assertStringContains('"preza"', $appJs);

        $test->assertStringContains('DirectoryIndex index.php index.html', $rootHtaccess);
        $test->assertStringContains('DirectoryIndex index.php index.html', $publicHtaccess);
        $test->assertStringContains('RewriteRule ^preza/?$ preza/index.html [L]', $rootHtaccess);
        $test->assertStringContains('RewriteRule ^preza/(.*)$ preza/$1 [L]', $rootHtaccess);

        $test->assertStringContains('Alias /preza /var/www/html/preza', $apache);
        $test->assertStringContains('DirectoryIndex index.html', $apache);

        $test->assertFileExists(__DIR__ . '/../../preza/index.html');
    },
];
