<?php

declare(strict_types=1);

/**
 * Accounts are isolated per church: login, email, social link, password reset.
 *
 * Test cases:
 * 1. Schema/migration: login unique is (login, church_id).
 * 2. Schema/migration: social external id is not globally unique.
 * 3. Login page resolves users via find_user_for_church_login.
 * 4. Forgot-password lives under the church slug (not platform root).
 * 5. Social callback looks up identity inside the OAuth church.
 * 6. Password-reset mail types dispatch immediately (no cron wait).
 * 7. unique_user_login accepts church scope.
 */

require_once __DIR__ . '/../../src/Account.php';
require_once __DIR__ . '/../../src/Migrate.php';
require_once __DIR__ . '/../../src/Mail.php';
require_once __DIR__ . '/../../src/ChurchTenant.php';

return [
    'TC1: login unique key is per church' => function (TestCase $test): void {
        $schema = (string) file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');
        $migration = (string) file_get_contents(__DIR__ . '/../../database/migrations/044_users_login_unique_per_church.sql');
        $test->assertStringContains('UNIQUE KEY uniq_users_login_church (login, church_id)', $schema);
        $test->assertStringContains('uniq_users_login_church', $migration);
        $test->assertTrue(in_array('044_users_login_unique_per_church.sql', migrate_list_files(), true));
        $probe = migrate_effect_probe_map()['044_users_login_unique_per_church.sql'] ?? null;
        $test->assertNotNull($probe);
        $test->assertSame('uniq_users_login_church', $probe['index']);
    },

    'TC2: social identities allow same external profile across churches' => function (TestCase $test): void {
        $schema = (string) file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');
        $migration = (string) file_get_contents(__DIR__ . '/../../database/migrations/045_social_identity_per_church.sql');
        $social = (string) file_get_contents(__DIR__ . '/../../src/Social.php');
        $test->assertStringNotContains('uniq_social_provider_user', $schema);
        $test->assertStringContains('idx_social_provider_external', $schema);
        $test->assertStringContains('DROP INDEX uniq_social_provider_user', $migration);
        $test->assertStringContains('function social_find_identity_for_church', $social);
        $test->assertStringContains('social_oauth_church_id', $social);
    },

    'TC3: church login helper signature and index.php use it' => function (TestCase $test): void {
        $account = (string) file_get_contents(__DIR__ . '/../../src/Account.php');
        $index = (string) file_get_contents(__DIR__ . '/../../public/index.php');
        $test->assertStringContains('function find_user_for_church_login(string $login, int $churchId)', $account);
        $test->assertStringContains('function unique_user_login(string $base, ?int $excludeUserId = null, ?int $churchId = null)', $account);
        $test->assertStringContains('find_user_for_church_login($loginValue, (int) $church[\'id\'])', $index);
        $test->assertStringContains("app_path('/forgot-password')", $index);
    },

    'TC4: forgot-password is a legacy CRM path under the church slug' => function (TestCase $test): void {
        $test->assertTrue(is_legacy_crm_path('/forgot-password'));
        $test->assertFalse(is_platform_root_path('/forgot-password'));
        set_current_church(['id' => 2, 'slug' => 'korolev', 'city' => 'Королёв']);
        $test->assertSame('/korolev/forgot-password', app_path('/forgot-password'));
        set_current_church(null);
    },

    'TC5: immediate mail dispatch covers password_reset' => function (TestCase $test): void {
        $types = email_notification_types_dispatch_immediately();
        $test->assertTrue(in_array('password_reset', $types, true));
        $test->assertTrue(in_array('password_changed', $types, true));
        $mail = (string) file_get_contents(__DIR__ . '/../../src/Mail.php');
        $test->assertStringContains('function email_notification_dispatch_one', $mail);
        $test->assertStringContains('function email_notifications_dispatch_pending', $mail);
    },

    'TC6: migrate helpers register login and social isolation' => function (TestCase $test): void {
        $migrate = (string) file_get_contents(__DIR__ . '/../../src/Migrate.php');
        $test->assertStringContains('migrate_apply_users_login_unique_per_church', $migrate);
        $test->assertStringContains('migrate_apply_social_identity_per_church', $migrate);
        $test->assertTrue(in_array('045_social_identity_per_church.sql', migrate_list_files(), true));
    },
];
