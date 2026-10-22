<?php

declare(strict_types=1);

return [
    'security hardening keeps a full database dump out of the web app' => function (TestCase $test): void {
        $features = file_get_contents(__DIR__ . '/../../src/Features.php');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');

        $test->assertStringNotContains('db()->exec((string) $_POST[\'sql\'])', $features);
        foreach (['function export_backup_sql', 'function backup_page', 'backup_restore_blocked', 'SHOW TABLES', "path === '/backup-restore'", "path === '/backup-export'"] as $needle) {
            $test->assertStringNotContains($needle, $features);
        }
        $test->assertStringNotContains("'/backup' =>", $index);
        $test->assertStringNotContains('/backup-export', $index);
    },
    'platform-wide vapid and role matrix require super admin' => function (TestCase $test): void {
        $features = file_get_contents(__DIR__ . '/../../src/Features.php');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $tenant = file_get_contents(__DIR__ . '/../../src/ChurchTenant.php');

        $test->assertStringContains('function require_super_admin', $tenant);
        $test->assertStringContains('require_super_admin($user);', $features);
        $test->assertStringContains("if (\$path === '/permissions-save') { verify_csrf(); require_super_admin(\$user);", $features);
        $test->assertStringContains("if (\$path === '/settings-security')", $features);
        $test->assertStringContains("if (\$path === '/vapid-generate')", $features);
        $test->assertStringNotContains("require_action(\$user, 'manage_permissions')", $features);
        $test->assertStringNotContains("require_action(\$user, 'manage_security')", $features);
        $test->assertStringContains('if (!is_super_admin($user))', $index);
        $test->assertStringContains("redirect('/profile')", $index);
        $test->assertStringContains('VAPID-ключи WebPush', $index);
        $test->assertStringNotContains('Включить 2FA для моего пользователя', $index);
        $test->assertStringNotContains("'manage_permissions'", $features);
    },
    'mutating routes verify object scope before writing' => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $features = file_get_contents(__DIR__ . '/../../src/Features.php');
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');
        $care = file_get_contents(__DIR__ . '/../../src/Care.php');

        foreach (['function scoped_group', 'function scoped_person', 'function scoped_meeting', 'function scoped_prayer', 'function scoped_deletion_request'] as $helper) {
            $test->assertStringContains($helper, $bootstrap);
        }

        foreach (['scoped_group($user, (int) $_POST[\'group_id\'])', 'scoped_person($user, (int) $_POST[\'person_id\'])'] as $guard) {
            $test->assertStringContains($guard, $index);
        }
        $test->assertStringContains('scoped_meeting($user, $meetingId)', $care);
        $test->assertStringContains('meeting_save_attendance_for_user', $index);

        foreach (['scoped_prayer($user, (int) $_POST[\'id\'])', 'scoped_deletion_request($user, (int) $_POST[\'id\'])', 'scoped_group($user, (int) $row[4])'] as $guard) {
            $test->assertStringContains($guard, $features);
        }
    },
    'csrf and 2fa secrets are not weakened in browser flows' => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        $features = file_get_contents(__DIR__ . '/../../src/Features.php');
        $js = file_get_contents(__DIR__ . '/../../public/assets/app.js');

        $test->assertStringContains('function save_webpush_subscription', $index);
        $test->assertStringContains('verify_csrf();', $index);
        $test->assertStringContains('csrf_is_valid()', $index);
        $test->assertStringContains('refresh_csrf_token()', $index);
        $test->assertStringContains('login_csrf_is_valid()', $index);
        $test->assertStringContains('login_csrf_field()', $index);
        $test->assertStringContains("header('Content-Type: application/json", $index);
        $test->assertStringContains('"X-CSRF-Token": csrfToken()', $js);
        $test->assertStringContains('function two_factor_secret', $features);
        $test->assertStringContains('function two_factor_verify', $features);
        $test->assertStringContains('hash_hmac(\'sha1\'', $features);
        $test->assertStringNotContains('Текущий одноразовый код', $account);
        $test->assertStringNotContains('Текущий одноразовый код', $index);
        $test->assertStringContains('name="setup_secret"', $account);
        $test->assertStringContains('function generate_vapid_keys', $features);
        $test->assertStringContains('function normalize_vapid_subject', $features);
        $test->assertStringContains("if (\$path === '/vapid-generate')", $features);
        $test->assertStringContains('/vapid-generate', $index);
        $test->assertStringContains('webpush_config()', $index);
        $test->assertStringContains('Контакт отправителя', $index);
        $test->assertStringContains('Публичный ключ', $index);
        $test->assertStringContains('Закрытый ключ', $index);
    },
    'session security headers are set before app output' => function (TestCase $test): void {
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');

        foreach (['function request_is_https', 'function request_should_force_https', "'secure' => request_is_https()", "'httponly' => true", "'samesite' => 'Lax'", 'X-Frame-Options: SAMEORIGIN', 'X-Content-Type-Options: nosniff', 'Referrer-Policy: same-origin'] as $needle) {
            $test->assertStringContains($needle, $bootstrap);
        }
    },
    'http to https redirect is configured for session cookies' => function (TestCase $test): void {
        $rootHtaccess = file_get_contents(__DIR__ . '/../../.htaccess');
        $publicHtaccess = file_get_contents(__DIR__ . '/../../public/.htaccess');

        foreach ([$rootHtaccess, $publicHtaccess] as $htaccess) {
            $test->assertStringContains('RewriteCond %{HTTPS} !=on', $htaccess);
            $test->assertStringContains('RewriteCond %{HTTP:X-Forwarded-Proto} !https [NC]', $htaccess);
            $test->assertStringContains('https://%{HTTP_HOST}%{REQUEST_URI}', $htaccess);
        }
    },
    'uploaded knowledge images cannot execute scripts' => function (TestCase $test): void {
        $uploadsHtaccess = file_get_contents(__DIR__ . '/../../public/uploads/knowledge/.htaccess');
        $knowledge = file_get_contents(__DIR__ . '/../../src/Knowledge.php');

        $test->assertStringContains('Options -Indexes -ExecCGI', $uploadsHtaccess);
        $test->assertStringContains('Require all denied', $uploadsHtaccess);
        $test->assertStringContains('knowledge_is_safe_image_path', $knowledge);
        $test->assertStringContains('getimagesize', $knowledge);
        $test->assertStringContains('new finfo(FILEINFO_MIME_TYPE)', $knowledge);
        $test->assertStringNotContains('finfo_close', $knowledge);
        $test->assertStringNotContains('image/svg+xml', $knowledge);
    },
    'role preview blocks data mutations while allowing preview controls' => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $rolePreview = file_get_contents(__DIR__ . '/../../src/RolePreview.php');
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');

        foreach (['function role_preview_blocks_mutation', 'function deny_role_preview_mutation', 'function handle_role_preview_post', 'function user_with_preview_role'] as $needle) {
            $test->assertStringContains($needle, $rolePreview);
        }
        $loadModules = file_get_contents(__DIR__ . '/../../src/load_modules.php');
        $test->assertStringContains("'RolePreview.php' =>", $loadModules);
        $test->assertStringContains("'handle_role_preview_post'", $loadModules);
        $test->assertStringContains('function real_user', $bootstrap);

        $test->assertStringContains('role_preview_blocks_mutation($method, $path)', $index);
        $test->assertStringContains('deny_role_preview_mutation()', $index);
        $test->assertStringContains('/role-preview', $index);
        $test->assertStringContains('role_preview_banner_markup', $bootstrap);
        $test->assertStringContains('is_role_preview_active()', $bootstrap);
    },
];
