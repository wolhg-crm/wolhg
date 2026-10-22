<?php

declare(strict_types=1);

/**
 * Documents the production cron failure mode from 2026-09-29:
 * a non-atomic FTP put left src/Account.php truncated mid-function while
 * bin/send-*.php loaded bootstrap → PHP Parse error: Unclosed '{'.
 *
 * Line numbers are discovered from the live file so ordinary Account.php
 * edits do not break the regression fixture.
 */
return [
    'truncated Account.php mid-if reproduces the cron parse error' => function (TestCase $test): void {
        $source = file_get_contents(__DIR__ . '/../../src/Account.php');
        $test->assertTrue(is_string($source) && $source !== '');
        $lines = preg_split("/\r\n|\n|\r/", $source) ?: [];
        $test->assertTrue(count($lines) > 50, 'Account.php must be long enough for the fixture');

        $cutAfter = null;
        $openLine = null;
        foreach ($lines as $index => $line) {
            if (!preg_match('/^\s*if\s*\(.*\)\s*\{\s*$/', $line)) {
                continue;
            }
            // Need at least two more lines inside the block to truncate mid-body.
            if (!isset($lines[$index + 2])) {
                continue;
            }
            $openLine = $index + 1; // 1-based, matches php -l reporting
            $cutAfter = $index + 3; // keep opening if + two body lines
            break;
        }
        $test->assertTrue($openLine !== null && $cutAfter !== null, 'Account.php must contain an if-block to truncate');

        $truncated = implode("\n", array_slice($lines, 0, $cutAfter));
        $tmp = tempnam(sys_get_temp_dir(), 'acct');
        $test->assertTrue(is_string($tmp) && $tmp !== '');
        $path = $tmp . '.php';
        @unlink($tmp);
        file_put_contents($path, $truncated);

        $proc = proc_open(['php', '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $test->assertTrue(is_resource($proc));
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        @unlink($path);

        $test->assertTrue($status !== 0);
        $combined = $stdout . $stderr;
        $test->assertStringContains("Unclosed '{'", $combined);
        $test->assertTrue(
            (bool) preg_match('/on line ' . preg_quote((string) $openLine, '/') . '\b/', $combined)
            || (bool) preg_match('/on line ' . preg_quote((string) $cutAfter, '/') . '\b/', $combined),
            'php -l should report the truncated if-block line; got: ' . $combined
        );
    },

    'complete Account.php passes php -l' => function (TestCase $test): void {
        $path = realpath(__DIR__ . '/../../src/Account.php');
        $test->assertTrue(is_string($path) && $path !== '');
        $proc = proc_open(['php', '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $test->assertTrue(is_resource($proc));
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        $test->assertSame(0, $status, $stdout . $stderr);
        $test->assertStringContains('No syntax errors detected', $stdout . $stderr);
    },
];
