<?php

declare(strict_types=1);

/**
 * Account-invite public URLs must be church-scoped like role invites.
 */

if (!function_exists('app_config')) {
    function app_config(?string $key = null, mixed $default = null): mixed
    {
        $config = [
            'legacy_default_slug' => 'pushkino',
            'url' => 'https://wolhg.ru',
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

require_once __DIR__ . '/../../src/ChurchTenant.php';
require_once __DIR__ . '/../../src/Account.php';

return [
    'account invite public path embeds church slug when available' => function (TestCase $test): void {
        $token = str_repeat('ab', 24);
        $test->assertSame(
            '/korolev/account-invite?token=' . rawurlencode($token),
            account_invite_public_path($token, ['id' => 2, 'slug' => 'korolev', 'is_demo' => 0])
        );
        $test->assertSame(
            '/pushkino/account-invite?token=' . rawurlencode($token),
            account_invite_public_path($token, ['id' => 1, 'slug' => 'pushkino', 'is_demo' => 0])
        );
        $test->assertSame(
            '/account-invite?token=' . rawurlencode($token),
            account_invite_public_path($token, null)
        );
        $test->assertSame(
            '/account-invite?token=' . rawurlencode($token),
            account_invite_public_path($token, ['id' => 9, 'slug' => 'demo-ws-abcdef123456', 'is_demo' => 1])
        );
        $test->assertSame('', account_invite_public_path(''));
    },

    'account invite helpers and page stay tenant-aware' => function (TestCase $test): void {
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');

        $test->assertStringContains('function account_invite_public_path', $account);
        $test->assertStringContains('function account_invite_church_for_person', $account);
        $test->assertStringContains('account_invite_public_path($token, account_invite_church_for_person($personId))', $account);
        $test->assertStringContains('app_path(\'/login\')', $account);
        $test->assertStringContains('person_church_id', $account);
        $test->assertStringNotContains("public_url('/account-invite?token=' . urlencode(\$token))", $account);
        $test->assertStringContains("str_contains(\$invitePath, '/account-invite?token=')", $index);
    },
];
