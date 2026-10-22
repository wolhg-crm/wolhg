<?php

declare(strict_types=1);

return [
    'service logo asset is available locally' => function (TestCase $test): void {
        $test->assertFileExists(__DIR__ . '/../../public/assets/logo.svg');
        $logo = file_get_contents(__DIR__ . '/../../public/assets/logo.svg');
        $test->assertStringContains('<svg', $logo);
        $test->assertStringContains('#FFBA0D', $logo);
        $test->assertStringNotContains('<text', $logo);
        $test->assertStringNotContains('CRM ДГ', $logo);

        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $test->assertStringContains('render_brand_markup', $bootstrap);
        $test->assertStringContains('render_brand_markup', $index);
        $test->assertStringNotContains('wolrus-logo.svg', $bootstrap);
        $test->assertStringNotContains('wolrus-logo.svg', $index);
    },

    'app icon is wired for favicon and PWA installs' => function (TestCase $test): void {
        $root = __DIR__ . '/../../public';
        $test->assertFileExists($root . '/assets/icon.svg');
        $test->assertFileExists($root . '/assets/icon-192.png');
        $test->assertFileExists($root . '/assets/icon-512.png');
        $test->assertFalse(is_file($root . '/manifest.webmanifest'));
        $test->assertFileExists(__DIR__ . '/../../src/PwaManifest.php');
        $test->assertSame([192, 192], array_slice(getimagesize($root . '/assets/icon-192.png'), 0, 2));
        $test->assertSame([512, 512], array_slice(getimagesize($root . '/assets/icon-512.png'), 0, 2));

        $index = file_get_contents($root . '/index.php');
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        $manifestModule = file_get_contents(__DIR__ . '/../../src/PwaManifest.php');
        $htaccess = file_get_contents(__DIR__ . '/../../.htaccess');

        $test->assertStringContains('public_page_header', $index);
        $test->assertStringContains('send_pwa_manifest_response(current_church_slug())', $index);
        $test->assertStringContains("'/manifest.webmanifest'", $index);
        $test->assertStringContains('html_pwa_meta()', $bootstrap);
        $test->assertStringContains('pwa_manifest_href(', $bootstrap);
        $test->assertStringContains('<link rel="icon" href="/assets/icon.svg" type="image/svg+xml">', $bootstrap);
        $test->assertStringContains('<link rel="apple-touch-icon" href="/assets/icon-192.png">', $bootstrap);
        $test->assertStringContains('html_pwa_meta()', $account);
        $test->assertStringContains("'start_url' => \$base . '/?source=pwa'", $manifestModule);
        $test->assertStringContains("'id' => \$base . '/'", $manifestModule);
        $test->assertStringNotContains('manifest.webmanifest', $htaccess);
        $test->assertStringContains('service-worker\\.js', $htaccess);
    },
    'runtime templates preload self-hosted Montserrat without Google Fonts CDN' => function (TestCase $test): void {
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');
        $css = file_get_contents(__DIR__ . '/../../public/assets/app.css');
        $fontsDir = __DIR__ . '/../../public/assets/fonts';

        $test->assertStringContains('public_page_header', $account);
        $test->assertStringContains('html_font_assets()', $bootstrap);
        $test->assertStringContains('/assets/fonts/montserrat-', $bootstrap);
        $test->assertStringContains('font/woff2', $bootstrap);
        $test->assertStringNotContains('fonts.googleapis.com', $bootstrap);
        $test->assertStringNotContains('fonts.gstatic.com', $bootstrap);
        $test->assertStringNotContains('fonts.googleapis.com', $css);
        $test->assertStringNotContains('fonts.gstatic.com', $css);

        foreach ([400, 500, 600, 700, 800] as $weight) {
            $test->assertFileExists($fontsDir . '/montserrat-' . $weight . '.woff2');
            $test->assertStringContains('montserrat-' . $weight . '.woff2', $css);
        }
        $test->assertFileExists($fontsDir . '/OFL.txt');
        $test->assertStringContains('@font-face', $css);
        $test->assertStringContains('font-display: swap', $css);
    },
    'stylesheet uses wolrus visual tokens' => function (TestCase $test): void {
        $css = file_get_contents(__DIR__ . '/../../public/assets/app.css');

        $test->assertStringContains('--accent: #e95e18', $css);
        $test->assertStringContains('--teal: #00a492', $css);
        $test->assertStringContains('font-family: Montserrat', $css);
    },
];
