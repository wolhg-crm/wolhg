<?php

declare(strict_types=1);

/**
 * Email uniqueness is per church: the same address may register in Pushkino and Korolev.
 *
 * Test cases:
 * 1. Scope query for a church uses email + church_id.
 * 2. Scope query for platform accounts uses email + church_id IS NULL.
 * 3. Exclude-user id is appended for profile/admin edits.
 * 4. Email is normalized to lowercase in the scope query.
 * 5. Schema / migration enforce uniq_users_email_church (email, church_id).
 * 6. Registration paths no longer block on a global email lookup.
 * 7. Password reset iterates all accounts sharing an email.
 * 8. Church onboarding allows an email that already exists elsewhere.
 * 9. Migrate applies 043 idempotently via PHP helpers.
 */

require_once __DIR__ . '/../../src/Domain.php';
require_once __DIR__ . '/../../src/Account.php';
require_once __DIR__ . '/../../src/Migrate.php';

return [
    'TC1: church-scoped email conflict uses email and church_id' => function (TestCase $test): void {
        $query = user_email_scope_query('msadakov@ya.ru', 2);
        $test->assertSame('email = ? AND church_id = ?', $query['sql']);
        $test->assertSame(['msadakov@ya.ru', 2], $query['params']);
    },

    'TC2: platform-scoped email conflict uses church_id IS NULL' => function (TestCase $test): void {
        $query = user_email_scope_query('admin@example.com', null);
        $test->assertSame('email = ? AND church_id IS NULL', $query['sql']);
        $test->assertSame(['admin@example.com'], $query['params']);

        $zero = user_email_scope_query('admin@example.com', 0);
        $test->assertSame('email = ? AND church_id IS NULL', $zero['sql']);
    },

    'TC3: exclude user id is appended for ownership checks' => function (TestCase $test): void {
        $query = user_email_scope_query('msadakov@ya.ru', 1, 42);
        $test->assertSame('email = ? AND church_id = ? AND id <> ?', $query['sql']);
        $test->assertSame(['msadakov@ya.ru', 1, 42], $query['params']);
    },

    'TC4: scope query and normalize_account_email lowercase email' => function (TestCase $test): void {
        $test->assertSame('msadakov@ya.ru', normalize_account_email('MsAdakov@Ya.RU'));
        $query = user_email_scope_query('MsAdakov@Ya.RU', 7);
        $test->assertSame(['msadakov@ya.ru', 7], $query['params']);
    },

    'TC5: schema and migration define per-church email unique key' => function (TestCase $test): void {
        $schema = (string) file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');
        $migration = (string) file_get_contents(__DIR__ . '/../../database/migrations/043_users_email_unique_per_church.sql');

        $test->assertStringContains('UNIQUE KEY uniq_users_email_church (email, church_id)', $schema);
        $test->assertStringNotContains('email VARCHAR(190) NOT NULL UNIQUE', $schema);
        $test->assertStringContains('uniq_users_email_church', $migration);
        $test->assertStringContains('DROP INDEX email', $migration);
        $test->assertTrue(in_array('043_users_email_unique_per_church.sql', migrate_list_files(), true));

        $probe = migrate_effect_probe_map()['043_users_email_unique_per_church.sql'] ?? null;
        $test->assertNotNull($probe);
        $test->assertSame('index', $probe['type']);
        $test->assertSame('users', $probe['table']);
        $test->assertSame('uniq_users_email_church', $probe['index']);
    },

    'TC6: role-invite registration checks email only inside the invite church' => function (TestCase $test): void {
        $account = (string) file_get_contents(__DIR__ . '/../../src/Account.php');
        $test->assertStringContains('user_email_taken_in_church($email, (int) $context[\'church_id\'])', $account);
        $test->assertStringContains('В этой церкви уже есть пользователь с такой почтой', $account);
        $test->assertStringNotContains(
            "fetch_one('SELECT id FROM users WHERE email = ? LIMIT 1', [\$email])",
            $account
        );
        $test->assertStringContains('user_email_taken_in_church($email, $churchId > 0 ? $churchId : null)', $account);
    },

    'TC7: forgot-password is church-scoped and dispatches reset mail immediately' => function (TestCase $test): void {
        $account = (string) file_get_contents(__DIR__ . '/../../src/Account.php');
        $mail = (string) file_get_contents(__DIR__ . '/../../src/Mail.php');
        $test->assertStringContains('function find_users_by_email(string $email, ?int $churchId = null)', $account);
        $test->assertStringContains('foreach (find_users_by_email($email, $churchId) as $user)', $account);
        $test->assertStringContains('Если почта есть в этой церкви', $account);
        $test->assertStringContains("'password_reset'", $mail);
        $test->assertStringContains('email_notification_dispatch_one', $account);
        $test->assertStringContains('email_notification_types_dispatch_immediately', $mail);
        $test->assertStringNotContains(
            "fetch_one('SELECT * FROM users WHERE email = ? LIMIT 1', [\$email])",
            $account
        );
    },

    'TC8: church onboarding no longer rejects an email used in another church' => function (TestCase $test): void {
        $onboarding = (string) file_get_contents(__DIR__ . '/../../src/ChurchOnboarding.php');
        $test->assertStringContains('Same email/login may already exist in another church', $onboarding);
        $test->assertStringNotContains(
            "fetch_one('SELECT id FROM users WHERE email = ? LIMIT 1', [\$normalized['email']])",
            $onboarding
        );
        $test->assertStringNotContains(
            'Пользователь с такой почтой уже есть. Войдите или восстановите пароль.',
            $onboarding
        );
    },

    'TC9: migrate helpers for per-church email unique are registered' => function (TestCase $test): void {
        $migrate = (string) file_get_contents(__DIR__ . '/../../src/Migrate.php');
        $test->assertStringContains('function migrate_index_exists', $migrate);
        $test->assertStringContains('function migrate_drop_single_column_unique_email', $migrate);
        $test->assertStringContains('function migrate_apply_users_email_unique_per_church', $migrate);
        $test->assertStringContains("043_users_email_unique_per_church.sql", $migrate);
        $test->assertStringContains("'043_users_email_unique_per_church.sql' => 'migrate_apply_users_email_unique_per_church'", $migrate);
    },

    'TC10: admin user-create and MCP profile use church-scoped email ownership' => function (TestCase $test): void {
        $features = (string) file_get_contents(__DIR__ . '/../../src/Features.php');
        $mcp = (string) file_get_contents(__DIR__ . '/../../src/McpTools.php');
        $test->assertStringContains('user_email_taken_in_church($email, $churchScope)', $features);
        $test->assertStringContains('Почта уже используется другим пользователем в этой церкви', $features);
        $test->assertStringContains('Почта уже используется другим пользователем в этой церкви', $mcp);
    },

    'TC11: reset-password redirects to the account church login' => function (TestCase $test): void {
        $account = (string) file_get_contents(__DIR__ . '/../../src/Account.php');
        $test->assertStringContains('c.slug AS church_slug', $account);
        $test->assertStringContains("redirect(\$slug !== '' ? '/' . rawurlencode(\$slug) . '/login' : '/login')", $account);
    },
];
