<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/CliLock.php';

return [
    'cli_cron_stagger is a no-op for non-positive delay' => function (TestCase $test): void {
        $started = microtime(true);
        cli_cron_stagger(0);
        cli_cron_stagger(-1);
        $test->assertTrue((microtime(true) - $started) < 0.5);
    },

    'cli_lock_path is stable and namespaced by project root' => function (TestCase $test): void {
        $a = cli_lock_path('send-bot-notifications');
        $b = cli_lock_path('send-bot-notifications');
        $test->assertSame($a, $b);
        $test->assertStringContains('wolhg-', $a);
        $test->assertStringContains('send-bot-notifications.lock', $a);
        $test->assertTrue(str_starts_with($a, sys_get_temp_dir()));
    },

    'cli_lock_path sanitizes unsafe characters' => function (TestCase $test): void {
        $path = cli_lock_path('../evil lock!');
        $test->assertTrue(str_ends_with($path, '.lock'));
        $test->assertFalse(str_contains(basename($path), '/'));
        $test->assertFalse(str_contains(basename($path), ' '));
    },

    'cli_with_lock runs callback and returns zero' => function (TestCase $test): void {
        $ran = false;
        $err = fopen('php://memory', 'r+');
        $code = cli_with_lock('unit-lock-ok-' . getmypid(), static function () use (&$ran): void {
            $ran = true;
        }, $err);
        $test->assertSame(0, $code);
        $test->assertTrue($ran);
        fclose($err);
    },

    'cli_with_lock skips when lock is held and returns zero' => function (TestCase $test): void {
        $name = 'unit-lock-busy-' . getmypid();
        $path = cli_lock_path($name);
        $holder = fopen($path, 'c+');
        $test->assertTrue(is_resource($holder));
        $test->assertTrue(flock($holder, LOCK_EX | LOCK_NB));

        $ran = false;
        $err = fopen('php://memory', 'r+');
        $code = cli_with_lock($name, static function () use (&$ran): void {
            $ran = true;
        }, $err);

        rewind($err);
        $msg = stream_get_contents($err) ?: '';
        fclose($err);
        flock($holder, LOCK_UN);
        fclose($holder);

        $test->assertSame(0, $code);
        $test->assertFalse($ran);
        $test->assertStringContains('busy', $msg);
    },

    'cli_with_lock returns one when callback throws' => function (TestCase $test): void {
        $err = fopen('php://memory', 'r+');
        $code = cli_with_lock('unit-lock-fail-' . getmypid(), static function (): void {
            throw new RuntimeException('boom');
        }, $err);
        rewind($err);
        $msg = stream_get_contents($err) ?: '';
        fclose($err);
        $test->assertSame(1, $code);
        $test->assertStringContains('boom', $msg);
    },
];
