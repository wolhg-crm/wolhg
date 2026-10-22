<?php

declare(strict_types=1);

/**
 * Demo access helpers that do not require a live database.
 */

require_once __DIR__ . '/../../src/DemoAccess.php';

return [
    'demo public slug and workspace prefix are stable' => function (TestCase $test): void {
        $test->assertSame('demo', demo_public_slug());
        $test->assertTrue(demo_slug_is_reserved_public('demo'));
        $test->assertTrue(demo_slug_is_reserved_public('DEMO'));
        $test->assertFalse(demo_slug_is_reserved_public('pushkino'));
        $test->assertTrue(demo_slug_is_workspace_internal('demo-ws-abcdef123456'));
        $test->assertFalse(demo_slug_is_workspace_internal('demo'));
        $test->assertFalse(demo_slug_is_workspace_internal('pushkino'));
    },

    'public catalog visibility helpers hide demo churches outside demo session' => function (TestCase $test): void {
        $test->assertTrue(function_exists('public_sql_exclude_demo_churches'));
        $test->assertTrue(function_exists('church_visible_on_public_catalog'));
        // Without DB, demo_schema_ready() is false → fragment is empty (safe no-op).
        $test->assertSame('', public_sql_exclude_demo_churches('c'));
        $test->assertTrue(church_visible_on_public_catalog([
            'id' => 1,
            'is_demo' => 0,
            'deleted_at' => null,
        ]));
        $test->assertFalse(church_visible_on_public_catalog([
            'id' => 9,
            'is_demo' => 1,
            'demo_token' => 'abc',
            'deleted_at' => null,
        ]));
    },

    'demo blocked paths cover shared mutations and public forms' => function (TestCase $test): void {
        foreach ([
            '/security',
            '/permissions',
            '/vapid-generate',
            '/permissions-save',
            '/knowledge-save',
            '/mcp-api-key-create',
            '/bot-link-token',
            '/push/subscribe',
            '/homegroup/join',
            '/prayer-request',
            '/miracle-share',
            '/password-change',
            '/person-account-invite',
        ] as $path) {
            $test->assertTrue(demo_path_is_blocked($path), $path . ' must be blocked in demo');
        }
        $test->assertFalse(demo_path_is_blocked('/backup'));
        $test->assertFalse(demo_path_is_blocked('/backup-export'));
        $test->assertFalse(demo_path_is_blocked('/groups'));
        $test->assertFalse(demo_path_is_blocked('/person-create'));
        $test->assertFalse(demo_path_is_blocked('/care'));
    },

    'demo account emails stay on demo.invalid domain' => function (TestCase $test): void {
        $sanitized = demo_sanitize_account_email('real@example.com', 42);
        $test->assertTrue(str_ends_with($sanitized, '@demo.invalid'));
        $test->assertSame('already@demo.invalid', demo_sanitize_account_email('already@demo.invalid', 42));
        $test->assertSame('user@demo.invalid', demo_sanitize_account_email('User@Demo.Invalid', 1));
    },

    'demo district polygon is valid geojson with bbox' => function (TestCase $test): void {
        [$geo, $minLat, $minLon, $maxLat, $maxLon] = demo_district_polygon(56.0, 37.8, 56.1, 37.9);
        $decoded = json_decode($geo, true);
        $test->assertSame('Polygon', $decoded['type'] ?? null);
        $test->assertTrue(isset($decoded['coordinates'][0][0]));
        $test->assertSame(56.0, $minLat);
        $test->assertSame(37.8, $minLon);
        $test->assertSame(56.1, $maxLat);
        $test->assertSame(37.9, $maxLon);
    },
];
