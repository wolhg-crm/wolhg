<?php

declare(strict_types=1);

if (!function_exists('app_config')) {
    function app_config(?string $key = null, mixed $default = null): mixed
    {
        $config = [
            'legacy_default_slug' => 'pushkino',
            'default_church_id' => 1,
        ];
        if ($key === null) {
            return $config;
        }
        return $config[$key] ?? $default;
    }
}

if (!function_exists('normalize_user_login')) {
    function normalize_user_login(string $login): string
    {
        $login = mb_strtolower(trim($login));
        $login = preg_replace('/[^a-z0-9._-]+/u', '-', $login) ?: '';
        $login = trim($login, '.-_');
        return $login !== '' ? mb_substr($login, 0, 80) : 'user';
    }
}

if (!function_exists('login_from_email')) {
    function login_from_email(string $email): string
    {
        $local = strstr($email, '@', true);
        return normalize_user_login($local !== false ? $local : $email);
    }
}

require_once __DIR__ . '/../../src/ChurchTenant.php';
require_once __DIR__ . '/../../src/ChurchOnboarding.php';

return [
    'church invite signup validation accepts complete payload' => function (TestCase $test): void {
        $result = church_invite_normalize_signup([
            'name' => 'Иван Петров',
            'email' => 'pastor@example.com',
            'login' => '',
            'password' => 'secret123',
            'church_name' => 'Церковь Надежда',
            'city' => 'Химки',
            'slug' => '',
            'timezone' => 'Europe/Moscow',
        ]);

        $test->assertTrue($result['ok']);
        $test->assertSame(null, $result['error']);
        $test->assertSame('Иван Петров', $result['name']);
        $test->assertSame('pastor@example.com', $result['email']);
        $test->assertSame('pastor', $result['login']);
        $test->assertSame('Церковь Надежда', $result['church_name']);
        $test->assertSame('Химки', $result['city']);
        $test->assertSame('Химки', $result['slug_base']);
        $test->assertSame('Europe/Moscow', $result['timezone']);
    },

    'church invite signup validation rejects incomplete payloads' => function (TestCase $test): void {
        $missingName = church_invite_normalize_signup([
            'name' => '',
            'email' => 'a@b.c',
            'password' => 'secret123',
            'church_name' => 'Церковь',
        ]);
        $test->assertFalse($missingName['ok']);
        $test->assertStringContains('имя', mb_strtolower((string) $missingName['error']));

        $badEmail = church_invite_normalize_signup([
            'name' => 'Иван',
            'email' => 'not-an-email',
            'password' => 'secret123',
            'church_name' => 'Церковь',
        ]);
        $test->assertFalse($badEmail['ok']);
        $test->assertStringContains('email', mb_strtolower((string) $badEmail['error']));

        $shortPassword = church_invite_normalize_signup([
            'name' => 'Иван',
            'email' => 'a@b.c',
            'password' => 'short',
            'church_name' => 'Церковь',
        ]);
        $test->assertFalse($shortPassword['ok']);
        $test->assertStringContains('пароль', mb_strtolower((string) $shortPassword['error']));

        $missingChurch = church_invite_normalize_signup([
            'name' => 'Иван',
            'email' => 'a@b.c',
            'password' => 'secret123',
            'church_name' => '  ',
        ]);
        $test->assertFalse($missingChurch['ok']);
        $test->assertStringContains('церкв', mb_strtolower((string) $missingChurch['error']));
    },

    'church invite signup derives slug base from church name when city empty' => function (TestCase $test): void {
        $result = church_invite_normalize_signup([
            'name' => 'Иван',
            'email' => 'ivan@example.com',
            'login' => 'ivan-pastor',
            'password' => 'secret123',
            'church_name' => 'Свет',
            'city' => '',
            'slug' => '',
        ]);
        $test->assertTrue($result['ok']);
        $test->assertSame(null, $result['city']);
        $test->assertSame('Свет', $result['slug_base']);
        $test->assertSame('ivan-pastor', $result['login']);
    },

    'church invite route is public before login and reserved as platform path' => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $invitePos = strpos($index, "if (\$path === '/regchurch')");
        $legacyPos = strpos($index, "if (\$path === '/church-invite')");
        $loginPos = strpos($index, '$user = require_login();');

        $test->assertTrue($invitePos !== false && $loginPos !== false);
        $test->assertTrue($invitePos < $loginPos);
        $test->assertTrue($legacyPos !== false && $legacyPos < $loginPos);
        $test->assertStringContains("redirect('/regchurch'", $index);
        $test->assertTrue(in_array('regchurch', church_slug_reserved_segments(), true));
        $test->assertTrue(in_array('church-invite', church_slug_reserved_segments(), true));
        $test->assertTrue(is_platform_root_path('/regchurch'));
        $test->assertTrue(is_platform_root_path('/church-invite'));
        $test->assertFalse(church_slug_is_valid('regchurch'));
        $test->assertFalse(church_slug_is_valid('church-invite'));
    },

    'church invite links are permanent until revoked' => function (TestCase $test): void {
        $onboarding = file_get_contents(__DIR__ . '/../../src/ChurchOnboarding.php');
        $migration = file_get_contents(__DIR__ . '/../../database/migrations/042_church_invite_permanent.sql');

        $test->assertTrue(church_invite_row_is_active(['revoked_at' => null, 'expires_at' => null]));
        $test->assertTrue(church_invite_row_is_active(['revoked_at' => null, 'expires_at' => '']));
        $test->assertTrue(church_invite_row_is_active([
            'revoked_at' => null,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]));
        $test->assertFalse(church_invite_row_is_active([
            'revoked_at' => null,
            'expires_at' => date('Y-m-d H:i:s', time() - 3600),
        ]));
        $test->assertFalse(church_invite_row_is_active([
            'revoked_at' => date('Y-m-d H:i:s'),
            'expires_at' => null,
        ]));

        $test->assertStringContains('VALUES (?, ?, ?, ?, NULL)', $onboarding);
        $test->assertStringContains('INSERT INTO church_invitation_uses', $onboarding);
        $test->assertStringContains('SET revoked_at = NOW()', $onboarding);
        $test->assertStringNotContains('Ссылка одноразовая', $onboarding);
        $test->assertStringContains('Постоянная ссылка', $onboarding);
        $test->assertStringContains('ADD COLUMN revoked_at', $migration);
        $test->assertStringContains('CREATE TABLE IF NOT EXISTS church_invitation_uses', $migration);
        $test->assertStringContains('church_invite_active_sql', $onboarding);
    },

    'church invite schema and migration ship together' => function (TestCase $test): void {
        $schema = file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');
        $migration = file_get_contents(__DIR__ . '/../../database/migrations/041_church_invitations.sql');
        $permanent = file_get_contents(__DIR__ . '/../../database/migrations/042_church_invite_permanent.sql');
        $modules = file_get_contents(__DIR__ . '/../../src/load_modules.php');
        $features = file_get_contents(__DIR__ . '/../../src/Features.php');
        $verify = file_get_contents(__DIR__ . '/../../bin/verify-schema.php');

        $test->assertFileExists(__DIR__ . '/../../src/ChurchOnboarding.php');
        $test->assertFileExists(__DIR__ . '/../../database/migrations/042_church_invite_permanent.sql');
        $test->assertStringContains('CREATE TABLE church_invitations', $schema);
        $test->assertStringContains('revoked_at TIMESTAMP NULL', $schema);
        $test->assertStringContains('CREATE TABLE church_invitation_uses', $schema);
        $test->assertStringContains('used_by_user_id', $schema);
        $test->assertStringContains('CREATE TABLE IF NOT EXISTS church_invitations', $migration);
        $test->assertStringContains('MODIFY expires_at TIMESTAMP NULL', $permanent);
        $test->assertStringContains("'ChurchOnboarding.php'", $modules);
        $test->assertStringContains('render_admin_church_invites_panel', $features);
        $test->assertStringContains('/admin/church-invite-create', $features);
        $test->assertStringContains('/admin/church-invite-revoke', $features);
        $test->assertStringContains("'church_invitations'", $verify);
        $test->assertStringContains("'church_invitation_uses'", $verify);
        $test->assertStringContains('042_church_invite_permanent.sql', $verify);
    },

    'system guide documents permanent church invite onboarding' => function (TestCase $test): void {
        $guide = file_get_contents(__DIR__ . '/../../src/SystemGuide.php');
        $onboarding = file_get_contents(__DIR__ . '/../../src/ChurchOnboarding.php');
        $test->assertStringContains('/regchurch', $guide);
        $test->assertStringContains("public_url('/regchurch?token=", $onboarding);
        $test->assertStringContains('постоянная ссылка для пастора создать церковь', mb_strtolower($guide));
        $test->assertStringNotContains('(`/church-invite`)', $guide);
    },
];
