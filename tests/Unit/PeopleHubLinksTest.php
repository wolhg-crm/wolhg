<?php

declare(strict_types=1);

/**
 * People hub buttons/links must stay on the current church tenant.
 */

if (!function_exists('app_config')) {
    function app_config(?string $key = null, mixed $default = null): mixed
    {
        $config = [
            'legacy_default_slug' => 'pushkino',
            'url' => 'https://wolhg.ru',
            'security' => ['encryption_key' => 'people-hub-links-key'],
        ];
        if ($key === null) {
            return $config;
        }
        foreach (explode('.', $key) as $part) {
            if (!is_array($config) || !array_key_exists($part, $config)) {
                return $default;
            }
            $config = $config[$part];
        }

        return $config;
    }
}

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
        return match ($role) {
            'admin' => 'Администратор',
            'pastor' => 'Пастор',
            'district_leader' => 'Окружной лидер',
            'leader' => 'Лидер',
            'assistant' => 'Помощник',
            default => 'Участник',
        };
    }
}

/**
 * Extract href= and action= targets from HTML.
 *
 * @return list<string>
 */
function people_hub_test_extract_targets(string $html): array
{
    preg_match_all('/\b(?:href|action)="([^"]*)"/', $html, $matches);

    return array_values(array_unique($matches[1] ?? []));
}

/**
 * CRM paths that must never appear bare on a tenant people-hub surface.
 *
 * @return list<string>
 */
function people_hub_test_forbidden_bare_prefixes(): array
{
    return [
        '/users',
        '/user',
        '/person',
        '/participant-requests',
        '/role-requests',
        '/profile',
        '/login',
        '/user-access-update',
        '/user-delete',
        '/user-update',
        '/person-delete',
        '/person-set-group',
        '/participant-approve',
        '/participant-reject',
        '/role-invite-approve',
        '/role-invite-reject',
    ];
}

/**
 * @param list<string> $targets
 * @param list<string> $allowedExact platform paths that may stay bare (public forms)
 */
function people_hub_test_assert_tenant_targets(TestCase $test, array $targets, string $slug, array $allowedExact = []): void
{
    $forbidden = people_hub_test_forbidden_bare_prefixes();
    foreach ($targets as $target) {
        if ($target === '' || str_starts_with($target, '#') || str_starts_with($target, 'mailto:')) {
            continue;
        }
        if (preg_match('#^https?://#i', $target) === 1) {
            $path = (string) (parse_url($target, PHP_URL_PATH) ?: '');
            $query = (string) (parse_url($target, PHP_URL_QUERY) ?: '');
            $target = $path . ($query !== '' ? '?' . $query : '');
        }
        if (in_array($target, $allowedExact, true)) {
            continue;
        }
        foreach ($forbidden as $prefix) {
            if ($target === $prefix || str_starts_with($target, $prefix . '?') || str_starts_with($target, $prefix . '/')) {
                $test->assertTrue(
                    false,
                    'Bare CRM target on people hub: ' . $target . ' (expected /' . $slug . '/...)'
                );
            }
        }
        if (str_starts_with($target, '/')) {
            $test->assertTrue(
                str_starts_with($target, '/' . $slug . '/') || $target === '/' . $slug
                    || str_starts_with($target, '/homegroup')
                    || str_starts_with($target, '/l/')
                    || str_starts_with($target, '/forgot-password')
                    || str_starts_with($target, '/reset-password')
                    || str_starts_with($target, '/account-invite')
                    || str_starts_with($target, '/role-invite')
                    || str_starts_with($target, '/assets/')
                    || in_array($target, $allowedExact, true),
                'Unexpected non-tenant path on people hub: ' . $target
            );
        }
    }
}

return [
    'people hub chrome links stay on korolev slug' => function (TestCase $test): void {
        set_current_church(['id' => 2, 'slug' => 'korolev', 'city' => 'Королёв', 'name' => 'СЖ Королёв', 'deleted_at' => null]);
        $user = [
            'id' => 1,
            'role' => 'pastor',
            'church_id' => 2,
            'church_slug' => 'korolev',
            'group_id' => null,
            'name' => 'Пастор',
        ];
        $filters = array_merge(people_list_default_filters(), [
            'q' => 'Анна',
            'group_id' => 7,
        ]);

        ob_start();
        render_people_hub_view_switch($filters, 'people');
        $switch = (string) ob_get_clean();

        ob_start();
        render_people_hub_inbox($user, 2, 1, 'people');
        $inbox = (string) ob_get_clean();

        ob_start();
        render_people_list_filters(
            [['id' => 7, 'name' => 'Группа']],
            [['id' => 1, 'name' => 'Новичок']],
            $filters,
            3,
            'people'
        );
        $filtersPeople = (string) ob_get_clean();

        ob_start();
        render_people_list_filters(
            [['id' => 7, 'name' => 'Группа']],
            [['id' => 1, 'name' => 'Новичок']],
            $filters,
            2,
            'accounts'
        );
        $filtersAccounts = (string) ob_get_clean();

        ob_start();
        render_role_invite_links_panel($user);
        $invites = (string) ob_get_clean();

        foreach ([$switch, $inbox, $filtersPeople, $filtersAccounts] as $html) {
            people_hub_test_assert_tenant_targets($test, people_hub_test_extract_targets($html), 'korolev');
        }

        $test->assertStringContains('href="/korolev/users?', $switch);
        $test->assertStringContains('view=accounts', $switch);
        $test->assertStringContains('href="/korolev/participant-requests"', $inbox);
        $test->assertStringContains('href="/korolev/role-requests"', $inbox);
        $test->assertStringContains('action="/korolev/users"', $filtersPeople);
        $test->assertStringContains('action="/korolev/users"', $filtersAccounts);
        $test->assertStringContains('href="/korolev/users"', $filtersPeople); // reset
        $test->assertStringContains('/korolev/role-invite?token=', $invites);
        $test->assertStringNotContains('href="/users"', $switch);
        $test->assertStringNotContains('href="/users?', $switch);
        $test->assertStringNotContains('value="https://wolhg.ru/role-invite?', $invites);

        set_current_church(null);
    },

    'people hub related helpers keep tenant prefix for pushkino and korolev' => function (TestCase $test): void {
        foreach (['pushkino' => 1, 'korolev' => 2] as $slug => $churchId) {
            set_current_church([
                'id' => $churchId,
                'slug' => $slug,
                'city' => $slug,
                'deleted_at' => null,
            ]);
            $test->assertSame('/' . $slug . '/users', people_hub_url([], 'people'));
            $test->assertSame('/' . $slug . '/users?view=accounts', people_accounts_hub_path());
            $test->assertSame('/' . $slug . '/participant-requests', app_path('/participant-requests'));
            $test->assertSame('/' . $slug . '/role-requests', app_path('/role-requests'));
            $test->assertSame('/' . $slug . '/users', app_path('/users'));
            $test->assertSame('/' . $slug . '/person?id=9', app_path('/person?id=9'));
            $test->assertSame('/' . $slug . '/user?id=4', user_card_href(4));
            $test->assertSame('/' . $slug . '/profile', user_card_href(4, ['id' => 4]));

            $issuer = [
                'id' => 10 + $churchId,
                'role' => 'pastor',
                'church_id' => $churchId,
                'church_slug' => $slug,
                'group_id' => null,
            ];
            $inviteUrl = role_invite_url($issuer, 'leader');
            $test->assertStringContains('/' . $slug . '/role-invite?token=', $inviteUrl);
        }
        set_current_church(null);
    },

    'people hub source does not emit bare CRM hrefs for hub chrome' => function (TestCase $test): void {
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');

        // View switch and filter reset must go through people_hub_url (tenant-aware).
        $test->assertStringContains('people_hub_url($filters, \'people\')', $account);
        $test->assertStringContains('people_hub_url($filters, \'accounts\')', $account);
        $test->assertStringContains('people_hub_url([], $view)', $account);
        $test->assertStringContains("app_path('/users')", $account);
        $test->assertStringContains("app_path('/participant-requests')", $account);
        $test->assertStringContains("app_path('/role-requests')", $account);
        $test->assertStringContains("app_path('/person?id='", $account);
        $test->assertStringContains("app_path('/user-access-update')", $account);
        $test->assertStringContains("app_path('/person-delete')", $account);
        $test->assertStringContains("app_path('/user-delete')", $account);
        $test->assertStringContains("app_path('/participant-approve')", $account);
        $test->assertStringContains("app_path('/role-invite-approve')", $account);

        // Bare href="/users...") must not appear in Account markup helpers.
        $test->assertTrue(preg_match('/href="\/users(?:\?|")/', $account) !== 1);
        $test->assertTrue(preg_match('/href=\'\/users(?:\?|\')/', $account) !== 1);
        $test->assertStringNotContains("['href' => '/users'", $account);
        $test->assertStringContains("people_hub_url([], 'people')", $account);
    },

    'participant and role request back-links use app_path users' => function (TestCase $test): void {
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        $test->assertStringContains("← Люди</a>", $account);
        // Both request pages must return via tenant users hub.
        $matches = preg_match_all("/page-actions\"><a class=\"ghost\" href=\"' \\. e\\(app_path\\('\\/users'\\)\\)/", $account);
        $test->assertTrue($matches >= 2, 'Expected >=2 back-links to app_path(/users)');
        $test->assertStringContains('people_accounts_hub_path()', $account);
        $test->assertStringContains('crm_public_group_join_url(0', $account);
    },

    'users page action forms post to tenant CRM paths' => function (TestCase $test): void {
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        foreach ([
            "/user-access-update",
            "/person-set-group",
            "/person-delete",
            "/user-delete",
            "/participant-approve",
            "/participant-reject",
            "/role-invite-approve",
            "/role-invite-reject",
        ] as $path) {
            $test->assertStringContains("app_path('" . $path . "')", $account);
            $test->assertTrue(
                preg_match('/action="' . preg_quote($path, '/') . '"/', $account) !== 1,
                'Bare form action must not appear: ' . $path
            );
        }
    },
];
