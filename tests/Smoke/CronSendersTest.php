<?php

declare(strict_types=1);

return [
    'cron senders use cli_with_lock and keep demo skips' => function (TestCase $test): void {
        $email = file_get_contents(__DIR__ . '/../../bin/send-emails.php');
        $push = file_get_contents(__DIR__ . '/../../bin/send-notifications.php');
        $bots = file_get_contents(__DIR__ . '/../../bin/send-bot-notifications.php');

        foreach ([$email, $push, $bots] as $script) {
            $test->assertStringContains('cli_with_lock(', $script);
            $test->assertStringContains('exit(cli_with_lock(', $script);
        }

        $test->assertFalse(str_contains($email, 'cli_cron_stagger('));
        $test->assertStringContains('cli_cron_stagger(15)', $push);
        $test->assertStringContains('cli_cron_stagger(30)', $bots);
        $test->assertTrue(strpos($push, 'cli_cron_stagger(15)') < strpos($push, 'bootstrap.php'));
        $test->assertTrue(strpos($bots, 'cli_cron_stagger(30)') < strpos($bots, 'bootstrap.php'));

        $test->assertStringContains('demo_queue_email_allowed', $email);
        $test->assertStringContains('church_is_demo_by_id', $push);
        $test->assertStringContains('church_is_demo_by_id', $bots);
        $test->assertStringContains('queue_daily_pastor_digest', $bots);
        $test->assertStringContains('Skipped: demo church notification', $email);

        // Config errors stay outside the lock with dedicated exit codes.
        $test->assertTrue(strpos($push, 'exit(2)') < strpos($push, 'cli_with_lock('));
        $test->assertTrue(strpos($push, 'exit(3)') < strpos($push, 'cli_with_lock('));
    },

    'CliLock is registered in application modules' => function (TestCase $test): void {
        require_once __DIR__ . '/../../src/load_modules.php';
        $modules = application_modules();
        $test->assertTrue(isset($modules['CliLock.php']));
        $test->assertTrue(in_array('cli_with_lock', $modules['CliLock.php'], true));
        $test->assertFileExists(__DIR__ . '/../../src/CliLock.php');
        $test->assertFileExists(__DIR__ . '/../../config/db_connect.php');
    },
];
