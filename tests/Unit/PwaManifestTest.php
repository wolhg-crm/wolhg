<?php

declare(strict_types=1);

if (!function_exists('app_config')) {
    function app_config(?string $key = null, mixed $default = null): mixed
    {
        $config = [
            'name' => 'CRM Домашних групп',
            'short_name' => 'CRM ДГ',
            'description' => 'CRM для домашних групп церкви.',
            'legacy_default_slug' => 'pushkino',
            'default_church_id' => 1,
        ];
        if ($key === null) {
            return $config;
        }
        return $config[$key] ?? $default;
    }
}

if (!function_exists('app_name')) {
    function app_name(): string
    {
        return (string) app_config('name');
    }
}

if (!function_exists('app_short_name')) {
    function app_short_name(): string
    {
        return (string) app_config('short_name');
    }
}

if (!function_exists('app_description')) {
    function app_description(): string
    {
        return (string) app_config('description');
    }
}

require_once __DIR__ . '/../../src/ChurchTenant.php';
require_once __DIR__ . '/../../src/DemoAccess.php';
require_once __DIR__ . '/../../src/PwaManifest.php';

return [
    'normalize falls back to default and current church' => function (TestCase $test): void {
        set_current_church(null);
        $test->assertSame('pushkino', pwa_normalize_manifest_slug(null));
        $test->assertSame('pushkino', pwa_normalize_manifest_slug(''));
        $test->assertSame('pushkino', pwa_normalize_manifest_slug('Login'));
        $test->assertSame('pushkino', pwa_normalize_manifest_slug('!!!'));

        set_current_church(['id' => 2, 'slug' => 'moscow-north']);
        $test->assertSame('moscow-north', pwa_normalize_manifest_slug(null));
        $test->assertSame('acme', pwa_normalize_manifest_slug('Acme'));
        set_current_church(null);
    },

    'manifest href is always church scoped' => function (TestCase $test): void {
        set_current_church(null);
        $test->assertSame('/pushkino/manifest.webmanifest', pwa_manifest_href(null));
        $test->assertSame('/other-church/manifest.webmanifest', pwa_manifest_href('other-church'));

        set_current_church(['id' => 3, 'slug' => 'spb-center']);
        $test->assertSame('/spb-center/manifest.webmanifest', pwa_manifest_href(null));
        set_current_church(null);
    },

    'manifest payload opens the install church not only pushkino' => function (TestCase $test): void {
        $pushkino = pwa_manifest_for_church('pushkino');
        $other = pwa_manifest_for_church('north-campus');

        $test->assertSame(app_name(), $pushkino['name']);
        $test->assertSame(app_short_name(), $pushkino['short_name']);
        $test->assertSame(app_description(), $pushkino['description']);
        $test->assertSame('#1d1d1b', $pushkino['theme_color']);
        $test->assertSame('standalone', $pushkino['display']);
        $test->assertTrue(in_array('standalone', $pushkino['display_override'], true));
        $test->assertSame('/', $pushkino['scope']);

        $test->assertSame('/pushkino/?source=pwa', $pushkino['start_url']);
        $test->assertSame('/pushkino/', $pushkino['id']);
        $test->assertSame('/pushkino/groups', $pushkino['shortcuts'][0]['url']);
        $test->assertSame('/pushkino/calendar', $pushkino['shortcuts'][1]['url']);
        $test->assertSame('/pushkino/profile', $pushkino['shortcuts'][2]['url']);

        $test->assertSame('/north-campus/?source=pwa', $other['start_url']);
        $test->assertSame('/north-campus/', $other['id']);
        $test->assertSame('/north-campus/groups', $other['shortcuts'][0]['url']);
        $test->assertSame('/north-campus/calendar', $other['shortcuts'][1]['url']);
        $test->assertSame('/north-campus/profile', $other['shortcuts'][2]['url']);
        $test->assertTrue($pushkino['id'] !== $other['id']);
        $test->assertSame($pushkino['name'], $other['name']);
    },

    'manifest icons match install assets' => function (TestCase $test): void {
        $icons = pwa_manifest_icons();
        $test->assertSame('/assets/icon.svg', $icons[0]['src']);
        $test->assertSame('/assets/icon-192.png', $icons[1]['src']);
        $test->assertSame('/assets/icon-512.png', $icons[2]['src']);
        $test->assertSame('any maskable', $icons[0]['purpose']);
    },

    'manifest json is valid and church specific' => function (TestCase $test): void {
        $json = pwa_manifest_json('west-side');
        $decoded = json_decode($json, true);
        $test->assertTrue(is_array($decoded));
        $test->assertSame('/west-side/?source=pwa', $decoded['start_url']);
        $test->assertStringContains('"start_url": "/west-side/?source=pwa"', $json);
    },

    'demo public slug is allowed for PWA install scope' => function (TestCase $test): void {
        $test->assertTrue(function_exists('demo_public_slug'));
        $test->assertTrue(pwa_manifest_slug_is_allowed('demo'));
        $test->assertFalse(pwa_manifest_slug_is_allowed('login'));
        $test->assertFalse(pwa_manifest_slug_is_allowed('admin'));
        $test->assertSame('/demo/?source=pwa', pwa_manifest_for_church('demo')['start_url']);
        $test->assertSame('/demo/', pwa_manifest_for_church('demo')['id']);
        $test->assertSame('/demo/manifest.webmanifest', pwa_manifest_href('demo'));
    },
];
