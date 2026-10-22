<?php

declare(strict_types=1);

return [
    'app naming helpers read canonical values from config' => function (TestCase $test): void {
        $config = require __DIR__ . '/../../config/app.php';

        $test->assertSame('CRM Домашних групп', $config['name']);
        $test->assertSame('CRM ДГ', $config['short_name']);
        $test->assertSame('CRM для домашних групп церкви.', $config['description']);
    },

    'runtime templates use app naming helpers instead of legacy branding' => function (TestCase $test): void {
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');
        $brand = file_get_contents(__DIR__ . '/../../src/Brand.php');
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        $catalog = file_get_contents(__DIR__ . '/../../src/HomeGroupCatalog.php');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $nav = file_get_contents(__DIR__ . '/../../src/AppNav.php');

        $test->assertStringContains('function app_name()', $bootstrap);
        $test->assertStringContains('function app_short_name()', $bootstrap);
        $test->assertStringContains('function html_pwa_meta()', $bootstrap);
        $test->assertStringContains('function render_brand_markup', $brand);
        $test->assertStringContains("render_brand_markup", $bootstrap);
        $test->assertStringContains("render_brand_markup", $index);
        $test->assertStringContains("app_name()", $account);
        $test->assertStringContains("'path' => '/care'", $nav);
        $test->assertStringContains("page_header('Сопровождение'", $account);

        foreach ([$bootstrap, $account, $catalog, $index] as $source) {
            $test->assertStringNotContains('CRM пастырской заботы', $source);
            $test->assertStringNotContains('CRM заботы', $source);
            $test->assertStringNotContains('content="Забота"', $source);
            $test->assertStringNotContains('alt="Забота"', $source);
        }
    },

    'pwa manifest and static assets match canonical app naming' => function (TestCase $test): void {
        $config = require __DIR__ . '/../../config/app.php';
        $manifestModule = file_get_contents(__DIR__ . '/../../src/PwaManifest.php');
        $worker = file_get_contents(__DIR__ . '/../../public/service-worker.js');
        $logo = file_get_contents(__DIR__ . '/../../public/assets/logo.svg');
        $icon = file_get_contents(__DIR__ . '/../../public/assets/icon.svg');

        $test->assertStringContains('app_name()', $manifestModule);
        $test->assertStringContains('app_short_name()', $manifestModule);
        $test->assertStringContains('app_description()', $manifestModule);
        $test->assertStringContains($config['name'], $worker);
        $test->assertStringContains($config['name'], $icon);
        $test->assertStringNotContains('<text', $logo);
        $test->assertFalse(is_file(__DIR__ . '/../../public/manifest.webmanifest'));
    },

    'documentation and package metadata use canonical app naming' => function (TestCase $test): void {
        $readme = file_get_contents(__DIR__ . '/../../README.md');
        $status = file_get_contents(__DIR__ . '/../../docs/implementation-status.md');
        $webpush = file_get_contents(__DIR__ . '/../../docs/webpush.md');
        $composer = file_get_contents(__DIR__ . '/../../composer.json');

        $test->assertStringContains('# CRM Домашних групп', $readme);
        $test->assertStringContains('CRM Домашних групп', $status);
        $test->assertStringContains('CRM Домашних групп', $webpush);
        $test->assertStringContains('CRM for home groups', $composer);

        foreach ([$readme, $status, $webpush] as $doc) {
            $test->assertStringNotContains('CRM пастырской заботы', $doc);
            $test->assertStringNotContains('CRM Заботы', $doc);
        }
    },

    'legacy product branding is not present in user facing sources' => function (TestCase $test): void {
        $paths = [
            'config/app.php',
            'public/index.php',
            'src/PwaManifest.php',
            'public/service-worker.js',
        'public/assets/logo.svg',
        'public/assets/icon.svg',
        'src/Brand.php',
        'src/bootstrap.php',
            'src/Account.php',
            'src/HomeGroupCatalog.php',
            'README.md',
            'docs/implementation-status.md',
            'docs/webpush.md',
        ];

        foreach ($paths as $path) {
            $contents = file_get_contents(__DIR__ . '/../../' . $path);
            $test->assertStringNotContains('CRM пастырской заботы', $contents, $path);
            $test->assertStringNotContains('CRM заботы', $contents, $path);
            $test->assertStringNotContains('>Забота<', $contents, $path);
            $test->assertStringNotContains('"short_name": "Забота"', $contents, $path);
        }
    },
];
