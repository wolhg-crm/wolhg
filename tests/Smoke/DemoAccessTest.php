<?php

declare(strict_types=1);

return [
    'demo access module and migration are wired' => function (TestCase $test): void {
        $root = __DIR__ . '/../..';
        $test->assertFileExists($root . '/src/DemoAccess.php');
        $test->assertFileExists($root . '/database/migrations/028_demo_church.sql');

        $migration = file_get_contents($root . '/database/migrations/028_demo_church.sql');
        $test->assertStringContains('is_demo', $migration);
        $test->assertStringContains('demo_token', $migration);
        $test->assertStringContains('demo_seen_at', $migration);

        $schema = file_get_contents($root . '/database/init/001_schema.sql');
        $test->assertStringContains('is_demo TINYINT(1) NOT NULL DEFAULT 0', $schema);
        $test->assertStringContains('demo_token CHAR(64) NULL', $schema);

        $modules = file_get_contents($root . '/src/load_modules.php');
        $test->assertStringContains("'DemoAccess.php'", $modules);
        $test->assertStringContains('resolve_demo_tenant_from_request_path', $modules);

        $index = file_get_contents($root . '/public/index.php');
        $test->assertStringContains('resolve_demo_tenant_from_request_path', $index);
        $test->assertStringContains("'/demo/enter'", $index);
        $test->assertStringContains('demo_enter_and_redirect', $index);
        $test->assertStringContains('demo_reset_and_redirect', $index);
        $test->assertStringContains('demo_guard_request', $index);

        $tenant = file_get_contents($root . '/src/ChurchTenant.php');
        $test->assertStringContains("'demo'", $tenant);
        $test->assertStringContains('is_demo = 0', $tenant);
        $test->assertStringContains('demo_public_slug', $tenant);

        $makefile = file_get_contents($root . '/Makefile');
        $test->assertStringContains('src/DemoAccess.php', $makefile);
    },

    'preza opens demo instead of production service button' => function (TestCase $test): void {
        $html = file_get_contents(__DIR__ . '/../../preza/index.html');
        $test->assertStringContains('href="/demo/enter"', $html);
        $test->assertStringContains('Открыть демо', $html);
        $test->assertStringNotContains('Открыть сервис', $html);
        // Remaining production links stay on the CTA slide / brand.
        $test->assertStringContains('https://wolhg.ru/', $html);
        $test->assertStringContains('https://wolhg.ru/homegroup', $html);
    },

    'demo logout returns to presentation without ajax shell rewrite' => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $test->assertStringContains('demo_logout_redirect_target', $index);
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');
        $test->assertStringContains('data-no-ajax', $bootstrap);
        $demo = file_get_contents(__DIR__ . '/../../src/DemoAccess.php');
        $test->assertStringContains("return '/preza';", $demo);
    },

    'senders skip demo church notifications' => function (TestCase $test): void {
        $email = file_get_contents(__DIR__ . '/../../bin/send-emails.php');
        $push = file_get_contents(__DIR__ . '/../../bin/send-notifications.php');
        $bots = file_get_contents(__DIR__ . '/../../bin/send-bot-notifications.php');
        $test->assertStringContains('demo_queue_email_allowed', $email);
        $test->assertStringContains('church_is_demo_by_id', $push);
        $test->assertStringContains('church_is_demo_by_id', $bots);
        $test->assertStringContains('Skipped: demo church notification', $email);
    },

    'public homegroup catalog excludes demo churches' => function (TestCase $test): void {
        $catalog = file_get_contents(__DIR__ . '/../../src/HomeGroupCatalog.php');
        $demo = file_get_contents(__DIR__ . '/../../src/DemoAccess.php');
        $test->assertStringContains('function public_sql_exclude_demo_churches', $demo);
        $test->assertStringContains('function church_visible_on_public_catalog', $demo);
        $test->assertStringContains('public_home_groups_visibility_sql', $catalog);
        $test->assertStringContains('public_sql_exclude_demo_churches', $catalog);
        $test->assertStringContains('church_visible_on_public_catalog', $catalog);
    },
];
