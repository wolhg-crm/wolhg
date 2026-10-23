<?php

declare(strict_types=1);

return [
    'bootstrap db uses pdo_connect_with_retry and loads db_connect' => function (TestCase $test): void {
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');
        $test->assertStringContains("require_once __DIR__ . '/../config/db_connect.php';", $bootstrap);
        $test->assertStringContains('pdo_connect_with_retry($db)', $bootstrap);
        $test->assertFalse(str_contains($bootstrap, "sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4'"));
    },

    'bootstrap skips session and security headers on CLI' => function (TestCase $test): void {
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');
        $test->assertStringContains('$isCli = PHP_SAPI === \'cli\';', $bootstrap);
        $test->assertStringContains('if (!$isCli) {', $bootstrap);
        $test->assertStringContains('session_start();', $bootstrap);
        $test->assertStringContains('if (!$isCli && !request_is_https() && request_should_force_https())', $bootstrap);
    },

    'app and example config expose connect resilience knobs' => function (TestCase $test): void {
        $app = file_get_contents(__DIR__ . '/../../config/app.php');
        $example = file_get_contents(__DIR__ . '/../../config/local.example.php');
        foreach (['unix_socket', 'connect_timeout', 'connect_retries'] as $key) {
            $test->assertStringContains("'" . $key . "'", $app);
            $test->assertStringContains("'" . $key . "'", $example);
        }
    },
];
