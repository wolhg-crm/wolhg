<?php

declare(strict_types=1);

/**
 * Unit tests for church tenant URL helpers (no DB required for most cases).
 */

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

require_once __DIR__ . '/../../src/ChurchTenant.php';

return [
    'legacy_default_slug uses configured value when set' => function (TestCase $test): void {
        $test->assertSame('pushkino', legacy_default_slug());
    },

    'church slug validation accepts city-style slugs' => function (TestCase $test): void {
        $test->assertTrue(church_slug_is_valid('pushkino'));
        $test->assertTrue(church_slug_is_valid('moscow-north'));
        $test->assertFalse(church_slug_is_valid('Pushkino'));
        $test->assertFalse(church_slug_is_valid('login'));
        $test->assertFalse(church_slug_is_valid('admin'));
        $test->assertFalse(church_slug_is_valid('homegroup'));
        $test->assertFalse(church_slug_is_valid('preza'));
        $test->assertFalse(church_slug_is_valid('demo'));
        $test->assertFalse(church_slug_is_valid('backup'));
        $test->assertFalse(church_slug_is_valid(''));
        $test->assertFalse(church_slug_is_valid('-bad'));
    },

    'normalize_church_slug lowercases and dashes' => function (TestCase $test): void {
        $test->assertSame('pushkino', normalize_church_slug('  Pushkino  '));
        $test->assertSame('moscow-north', normalize_church_slug('Moscow North'));
    },

    'app_path prefixes CRM routes with current church slug' => function (TestCase $test): void {
        set_current_church(['id' => 1, 'slug' => 'pushkino', 'city' => 'Пушкино', 'name' => 'Слово Жизни Пушкино']);
        $test->assertSame('/pushkino', app_path('/'));
        $test->assertSame('/pushkino/groups', app_path('/groups'));
        $test->assertSame('/pushkino/login', app_path('/login'));
        $test->assertSame('/pushkino/group?id=5', app_path('/group?id=5'));
        $test->assertSame('/pushkino/profile#mcp', app_path('/profile#mcp'));
        set_current_church(null);
    },

    'app_path leaves public platform routes unprefixed' => function (TestCase $test): void {
        set_current_church(['id' => 1, 'slug' => 'pushkino', 'city' => 'Пушкино']);
        $test->assertSame('/homegroup', app_path('/homegroup'));
        $test->assertSame('/homegroup/join', app_path('/homegroup/join'));
        $test->assertSame('/admin', app_path('/admin'));
        $test->assertSame('/forgot-password', app_path('/forgot-password'));
        set_current_church(null);
    },

    'app_path prefixes church-scoped public forms with slug' => function (TestCase $test): void {
        set_current_church(['id' => 1, 'slug' => 'korolev', 'city' => 'Королёв', 'name' => 'СЖ Королёв']);
        $test->assertSame('/korolev/prayer-request', app_path('/prayer-request'));
        $test->assertSame('/korolev/miracle-share', app_path('/miracle-share'));
        set_current_church(null);
    },

    'public_prayer_request_path and public_miracle_share_path are church-scoped' => function (TestCase $test): void {
        $church = ['id' => 2, 'slug' => 'korolev', 'city' => 'Королёв', 'name' => 'СЖ Королёв'];
        $test->assertSame('/korolev/prayer-request', public_prayer_request_path($church));
        $test->assertSame('/korolev/miracle-share', public_miracle_share_path($church));
        set_current_church(['id' => 1, 'slug' => 'pushkino', 'city' => 'Пушкино']);
        $test->assertSame('/pushkino/prayer-request', public_prayer_request_path());
        $test->assertSame('/pushkino/miracle-share', public_miracle_share_path());
        set_current_church(null);
    },

    'public_homegroup_catalog_path is platform-wide or church-scoped' => function (TestCase $test): void {
        $test->assertSame('/homegroup', public_homegroup_catalog_path(null));
        $test->assertSame('/homegroup', public_homegroup_catalog_path([]));
        $test->assertSame(
            '/pushkino/homegroup',
            public_homegroup_catalog_path(['id' => 1, 'slug' => 'pushkino', 'city' => 'Пушкино'])
        );
        $test->assertSame(
            '/korolev/homegroup',
            public_homegroup_catalog_path(['slug' => 'korolev', 'name' => 'Слово Жизни Королёв'])
        );
        $test->assertSame('Пушкино', church_public_label(['city' => 'Пушкино', 'name' => 'СЖ Пушкино']));
        $test->assertSame('СЖ Королёв', church_public_label(['name' => 'СЖ Королёв']));
    },

    'app_path uses legacy default slug when tenant is unset' => function (TestCase $test): void {
        set_current_church(null);
        $test->assertSame('/pushkino/groups', app_path('/groups'));
        $test->assertSame('/pushkino/login', app_path('/login'));
    },

    'legacy CRM path detection covers hubs and mutating verbs' => function (TestCase $test): void {
        $test->assertTrue(is_legacy_crm_path('/groups'));
        $test->assertTrue(is_legacy_crm_path('/login'));
        $test->assertTrue(is_legacy_crm_path('/person-create'));
        $test->assertTrue(is_legacy_crm_path('/church-settings'));
        $test->assertFalse(is_legacy_crm_path('/'));
        $test->assertFalse(is_legacy_crm_path('/homegroup'));
        $test->assertFalse(is_legacy_crm_path('/admin'));
        $test->assertFalse(is_legacy_crm_path('/prayer-request'));
        // Unknown church-like slugs must not be treated as legacy CRM verbs.
        $test->assertFalse(is_legacy_crm_path('/church-3'));
        $test->assertFalse(is_legacy_crm_path('/korolev'));
        $test->assertFalse(is_legacy_crm_path('/some-city'));
    },

    'user_can_access_church allows super_admin any church' => function (TestCase $test): void {
        $super = ['role' => 'super_admin', 'church_id' => 1];
        $admin = ['role' => 'admin', 'church_id' => 1];
        $pastor = ['role' => 'pastor', 'church_id' => 2];

        $test->assertTrue(user_can_access_church($super, 9));
        $test->assertTrue(user_can_access_church($admin, 1));
        $test->assertFalse(user_can_access_church($admin, 2));
        $test->assertFalse(user_can_access_church($pastor, 1));
        $test->assertTrue(user_can_access_church($pastor, 2));
    },

    'roles_assignable distinguishes church admin and super_admin' => function (TestCase $test): void {
        require_once __DIR__ . '/../../src/Account.php';
        if (!function_exists('roles')) {
            function roles(): array
            {
                return ['member', 'assistant', 'leader', 'district_leader', 'pastor', 'admin', 'super_admin'];
            }
        }

        $churchAdmin = roles_assignable_by('admin');
        $test->assertTrue(in_array('admin', $churchAdmin, true));
        $test->assertFalse(in_array('super_admin', $churchAdmin, true));

        $super = roles_assignable_by('super_admin');
        $test->assertTrue(in_array('super_admin', $super, true));
        $test->assertTrue(in_array('admin', $super, true));
    },

    'manifest and service worker are church-scoped for PWA start' => function (TestCase $test): void {
        $sw = file_get_contents(__DIR__ . '/../../public/service-worker.js');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');

        $test->assertFileExists(__DIR__ . '/../../src/PwaManifest.php');
        $test->assertFalse(is_file(__DIR__ . '/../../public/manifest.webmanifest'));
        $test->assertStringContains('send_pwa_manifest_response(current_church_slug())', $index);
        $test->assertStringContains("pwa_manifest_href(", $bootstrap);
        $test->assertStringContains('church-care-v24', $sw);
        $test->assertStringNotContains('/manifest.webmanifest"', $sw);
        $test->assertStringNotContains('/manifest.webmanifest', $sw);
    },

    'schema ships slug deleted_at and super_admin' => function (TestCase $test): void {
        $schema = file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');
        $seed = file_get_contents(__DIR__ . '/../../database/init/002_seed.sql');
        $test->assertStringContains('slug VARCHAR(64) NOT NULL', $schema);
        $test->assertStringContains('deleted_at TIMESTAMP NULL', $schema);
        $test->assertStringContains('super_admin', $schema);
        $test->assertStringContains("'example'", $seed);
        $test->assertStringNotContains("'pushkino'", $seed);
        $test->assertStringContains('Примерная церковь', $seed);
        $test->assertStringContains("'super_admin'", $seed);
        $test->assertFileExists(__DIR__ . '/../../database/migrations/022_church_slug.sql');
        $test->assertFileExists(__DIR__ . '/../../database/migrations/023_super_admin_role.sql');
        $test->assertFileExists(__DIR__ . '/../../database/migrations/024_participant_requests_church.sql');
        $test->assertFileExists(__DIR__ . '/../../database/migrations/025_church_deleted_at.sql');
    },

    'church_is_deleted and timezone helpers are strict' => function (TestCase $test): void {
        $test->assertTrue(church_is_deleted(null));
        $test->assertTrue(church_is_deleted(['id' => 1, 'deleted_at' => '2026-09-06 12:00:00']));
        $test->assertFalse(church_is_deleted(['id' => 1, 'deleted_at' => null]));
        $test->assertFalse(church_is_deleted(['id' => 1, 'deleted_at' => '']));
        $test->assertSame('Europe/Moscow', normalize_church_timezone(''));
        $test->assertSame('Europe/Moscow', normalize_church_timezone('Not/AZone'));
        $test->assertSame('Asia/Yekaterinburg', normalize_church_timezone('Asia/Yekaterinburg'));
    },

    'admin church lifecycle endpoints and helpers are wired' => function (TestCase $test): void {
        $features = file_get_contents(__DIR__ . '/../../src/Features.php');
        $tenant = file_get_contents(__DIR__ . '/../../src/ChurchTenant.php');
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        $js = file_get_contents(__DIR__ . '/../../public/assets/app.js');
        $docs = file_get_contents(__DIR__ . '/../../docs/shared-hosting.md');
        $verify = file_get_contents(__DIR__ . '/../../bin/verify-schema.php');

        $test->assertStringContains("'/admin/church-update'", $features);
        $test->assertStringContains("'/admin/church-delete'", $features);
        $test->assertStringContains('function soft_delete_church', $tenant);
        $test->assertStringContains('function save_church_settings', $tenant);
        $test->assertStringContains('function church_delete_block_reason', $tenant);
        $test->assertStringContains('deleted_at IS NULL', $tenant);
        $test->assertStringContains('save_church_settings($churchId, $input, is_super_admin($user))', $account);
        $test->assertStringContains('data-confirm-value', $features);
        $test->assertStringContains('dataset.confirmValue', $js);
        $test->assertStringContains('025_church_deleted_at.sql', $docs);
        $test->assertStringContains("'deleted_at'", $verify);
    },

    'effective_scope_church_id prefers URL tenant over membership' => function (TestCase $test): void {
        set_current_church(['id' => 3, 'slug' => 'church-3', 'name' => 'Church 3']);
        $user = ['role' => 'super_admin', 'church_id' => 1];
        $test->assertSame(3, effective_scope_church_id($user));

        $admin = ['role' => 'admin', 'church_id' => 1];
        $test->assertSame(3, effective_scope_church_id($admin));

        set_current_church(null);
        $test->assertNull(effective_scope_church_id($user));
        $test->assertSame(1, effective_scope_church_id($admin));
    },

    'church_id_scope_sql keeps legacy NULL only on default church' => function (TestCase $test): void {
        [$sqlDefault, $paramsDefault] = church_id_scope_sql('pr.church_id', 1);
        $test->assertStringContains('IS NULL', $sqlDefault);
        $test->assertSame([1], $paramsDefault);

        [$sqlOther, $paramsOther] = church_id_scope_sql('pr.church_id', 3);
        $test->assertSame('pr.church_id = ?', $sqlOther);
        $test->assertSame([3], $paramsOther);
        $test->assertStringNotContains('IS NULL', $sqlOther);
    },

    'ajax client swaps full CRM shell and remounts on tenant change' => function (TestCase $test): void {
        $js = file_get_contents(__DIR__ . '/../../public/assets/app.js');
        $test->assertStringContains('data-ajax-shell', $js);
        $test->assertStringContains('crmTenantSlugFromPath', $js);
        $test->assertStringContains('currentShell.replaceWith', $js);
        $test->assertStringContains('fromSlug !== toSlug', $js);
    },

    'legacy redirect prefers referer church slug' => function (TestCase $test): void {
        $source = file_get_contents(__DIR__ . '/../../src/ChurchTenant.php');
        $test->assertStringContains('function legacy_redirect_target_slug', $source);
        $test->assertStringContains('HTTP_REFERER', $source);
        $test->assertTrue(function_exists('legacy_redirect_target_slug'));
    },
];
