<?php

declare(strict_types=1);

/**
 * Role-invite URLs and token binding must stay church-specific.
 */

if (!function_exists('app_config')) {
    function app_config(?string $key = null, mixed $default = null): mixed
    {
        $config = [
            'legacy_default_slug' => 'pushkino',
            'url' => 'https://wolhg.ru',
            'security' => [
                'encryption_key' => 'unit-test-role-invite-key',
            ],
        ];
        if ($key === null) {
            return $config;
        }
        if (str_contains($key, '.')) {
            [$section, $item] = explode('.', $key, 2);
            return $config[$section][$item] ?? $default;
        }

        return $config[$key] ?? $default;
    }
}

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('fetch_one')) {
    /**
     * Unit tests do not boot the DB; church slug resolution must tolerate misses.
     *
     * @param list<mixed> $params
     */
    function fetch_one(string $sql, array $params = []): ?array
    {
        return null;
    }
}

require_once __DIR__ . '/../../src/ChurchTenant.php';
require_once __DIR__ . '/../../src/Account.php';

if (!function_exists('roles')) {
    function roles(): array
    {
        return ['member', 'assistant', 'leader', 'district_leader', 'pastor', 'admin', 'super_admin'];
    }
}

if (!function_exists('role_label')) {
    function role_label(string $role): string
    {
        return $role;
    }
}

return [
    'role invite public path embeds the issuer church slug' => function (TestCase $test): void {
        set_current_church(null);
        $korolevPastor = [
            'id' => 10,
            'role' => 'pastor',
            'church_id' => 2,
            'church_slug' => 'korolev',
            'group_id' => null,
        ];
        $pushkinoPastor = [
            'id' => 11,
            'role' => 'pastor',
            'church_id' => 1,
            'church_slug' => 'pushkino',
            'group_id' => null,
        ];

        $korolevToken = role_invite_token($korolevPastor, 'leader');
        $pushkinoToken = role_invite_token($pushkinoPastor, 'leader');
        $test->assertTrue($korolevToken !== '');
        $test->assertTrue($pushkinoToken !== '');
        $test->assertTrue($korolevToken !== $pushkinoToken);

        $korolevPath = role_invite_public_path($korolevPastor, $korolevToken);
        $pushkinoPath = role_invite_public_path($pushkinoPastor, $pushkinoToken);
        $test->assertSame('/korolev/role-invite?token=' . rawurlencode($korolevToken), $korolevPath);
        $test->assertSame('/pushkino/role-invite?token=' . rawurlencode($pushkinoToken), $pushkinoPath);
        $test->assertTrue(!str_starts_with($korolevPath, '/role-invite?'));
        $test->assertTrue(!str_starts_with($pushkinoPath, '/role-invite?'));

        $korolevUrl = role_invite_url($korolevPastor, 'leader');
        // Host comes from app_config('url') / request; assert the church-scoped path.
        $test->assertStringContains('/korolev/role-invite?token=', $korolevUrl);
        $test->assertSame('/korolev/role-invite', (string) parse_url($korolevUrl, PHP_URL_PATH));
    },

    'super_admin on korolev tenant mints korolev invite not home church' => function (TestCase $test): void {
        // Reproduces https://wolhg.ru/korolev/users?view=accounts showing /pushkino/role-invite
        // for a super_admin whose home church_id is Pushkino (1).
        $superAdmin = [
            'id' => 6,
            'role' => 'super_admin',
            'church_id' => 1,
            'church_slug' => 'pushkino',
            'group_id' => null,
        ];
        set_current_church([
            'id' => 2,
            'slug' => 'korolev',
            'city' => 'Королёв',
            'deleted_at' => null,
            'is_demo' => 0,
        ]);

        $token = role_invite_token($superAdmin, 'assistant');
        $test->assertTrue($token !== '');
        $parts = explode('.', $token, 2);
        $data = json_decode((string) role_invite_base64url_decode($parts[0]), true);
        $test->assertSame(2, (int) ($data['church_id'] ?? 0));
        $test->assertSame(6, (int) ($data['by'] ?? 0));

        $path = role_invite_public_path($superAdmin, $token);
        $test->assertSame('/korolev/role-invite?token=' . rawurlencode($token), $path);
        $test->assertTrue(!str_contains($path, '/pushkino/'));

        $url = role_invite_url($superAdmin, 'assistant');
        $test->assertStringContains('/korolev/role-invite?token=', $url);
        $test->assertTrue(!str_contains($url, '/pushkino/role-invite'));

        set_current_church(null);
    },

    'pastor on foreign tenant does not mint role invite for another church' => function (TestCase $test): void {
        $pushkinoPastor = [
            'id' => 11,
            'role' => 'pastor',
            'church_id' => 1,
            'church_slug' => 'pushkino',
            'group_id' => null,
        ];
        set_current_church([
            'id' => 2,
            'slug' => 'korolev',
            'city' => 'Королёв',
            'deleted_at' => null,
            'is_demo' => 0,
        ]);

        $test->assertSame('', role_invite_token($pushkinoPastor, 'leader'));
        $test->assertSame('', role_invite_url($pushkinoPastor, 'leader'));

        set_current_church(null);
    },

    'role invite path falls back to platform route without church slug' => function (TestCase $test): void {
        $issuer = [
            'id' => 12,
            'role' => 'pastor',
            'church_id' => 9,
            'group_id' => null,
        ];
        set_current_church(null);
        $token = role_invite_token($issuer, 'admin');
        $test->assertTrue($token !== '');
        $test->assertSame('/role-invite?token=' . rawurlencode($token), role_invite_public_path($issuer, $token));
    },

    'role invite path uses current tenant slug when it matches issuer church' => function (TestCase $test): void {
        $issuer = [
            'id' => 13,
            'role' => 'pastor',
            'church_id' => 2,
            'group_id' => null,
        ];
        set_current_church(['id' => 2, 'slug' => 'korolev', 'city' => 'Королёв', 'deleted_at' => null]);
        $token = role_invite_token($issuer, 'assistant');
        $test->assertSame('/korolev/role-invite?token=' . rawurlencode($token), role_invite_public_path($issuer, $token));
        set_current_church(null);
    },

    'role invite token v2 embeds church_id and rejects mismatched church' => function (TestCase $test): void {
        $issuer = [
            'id' => 14,
            'role' => 'pastor',
            'church_id' => 2,
            'church_slug' => 'korolev',
            'group_id' => null,
        ];
        $token = role_invite_token($issuer, 'leader');
        $parts = explode('.', $token, 2);
        $json = role_invite_base64url_decode($parts[0]);
        $data = json_decode((string) $json, true);
        $test->assertSame(2, (int) ($data['v'] ?? 0));
        $test->assertSame(2, (int) ($data['church_id'] ?? 0));
        $test->assertSame('leader', (string) ($data['role'] ?? ''));
        $test->assertSame(14, (int) ($data['by'] ?? 0));

        // Broken signature is rejected before any DB lookup.
        $tamperedPayload = rtrim(strtr(base64_encode(json_encode([
            'v' => 2,
            'by' => 14,
            'role' => 'leader',
            'church_id' => 99,
        ], JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
        $test->assertSame(null, role_invite_context_from_token($tamperedPayload . '.deadbeef'));
        $test->assertSame(null, role_invite_context_from_token('not-a-token'));
        $test->assertSame(null, role_invite_context_from_token(''));
    },

    'role invite matches request church only for the token church slug' => function (TestCase $test): void {
        $context = [
            'church_id' => 2,
            'role' => 'leader',
            'group_id' => null,
            'issuer' => ['id' => 1],
        ];

        set_current_church(null);
        $test->assertTrue(role_invite_matches_request_church($context));

        set_current_church(['id' => 2, 'slug' => 'korolev', 'deleted_at' => null]);
        $test->assertTrue(role_invite_matches_request_church($context));

        set_current_church(['id' => 1, 'slug' => 'pushkino', 'deleted_at' => null]);
        $test->assertFalse(role_invite_matches_request_church($context));

        $test->assertFalse(role_invite_matches_request_church(null));
        set_current_church(null);
    },

    'role invite helpers and page keep church-scoped registration links' => function (TestCase $test): void {
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        $test->assertStringContains('function role_invite_public_path', $account);
        $test->assertStringContains('function role_invite_church_slug', $account);
        $test->assertStringContains('function role_invite_target_church', $account);
        $test->assertStringContains('function role_invite_can_issue_for_church', $account);
        $test->assertStringContains('function role_invite_matches_request_church', $account);
        $test->assertStringContains("'v' => 2", $account);
        $test->assertStringContains('role_invite_public_path($issuer, $token)', $account);
        $test->assertStringContains('role_invite_matches_request_church($context)', $account);
        $test->assertStringContains('Ссылки этой церкви для регистрации', $account);
        $test->assertStringContains('Церковь:', $account);
        $test->assertStringContains('app_path(\'/login\')', $account);
        // Must not hardcode platform-only invite URL generation anymore.
        $test->assertStringNotContains("public_url('/role-invite?token=' . urlencode(\$token))", $account);
    },

    'member cannot issue role invite urls' => function (TestCase $test): void {
        $member = [
            'id' => 20,
            'role' => 'member',
            'church_id' => 2,
            'church_slug' => 'korolev',
            'group_id' => 3,
        ];
        $test->assertSame('', role_invite_token($member, 'assistant'));
        $test->assertSame('', role_invite_url($member, 'assistant'));
    },

    'role invite approval loads groups for the request church not only the tenant' => function (TestCase $test): void {
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        $test->assertStringContains('function role_invite_approval_groups(array $approver, ?int $forChurchId = null)', $account);
        $test->assertStringContains('role_invite_approval_groups($user, $requestChurchId > 0 ? $requestChurchId : null)', $account);
        $test->assertStringContains('Нет активных групп в этой церкви', $account);
    },
];
