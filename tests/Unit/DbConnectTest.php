<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';

return [
    'build_pdo_dsn uses host port database and charset' => function (TestCase $test): void {
        $dsn = build_pdo_dsn([
            'host' => 'db.example',
            'port' => 3307,
            'database' => 'church_care',
        ]);
        $test->assertSame('mysql:host=db.example;port=3307;dbname=church_care;charset=utf8mb4', $dsn);
    },

    'build_pdo_dsn prefers unix_socket over host' => function (TestCase $test): void {
        $dsn = build_pdo_dsn([
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'app',
            'unix_socket' => '/var/run/mysqld/mysqld.sock',
        ]);
        $test->assertSame('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=app;charset=utf8mb4', $dsn);
    },

    'build_pdo_dsn ignores blank unix_socket' => function (TestCase $test): void {
        $dsn = build_pdo_dsn([
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'app',
            'unix_socket' => '  ',
        ]);
        $test->assertSame('mysql:host=localhost;port=3306;dbname=app;charset=utf8mb4', $dsn);
    },

    'pdo_connect_timeout and retries are clamped' => function (TestCase $test): void {
        $test->assertSame(1, pdo_connect_timeout_seconds(['connect_timeout' => 0]));
        $test->assertSame(60, pdo_connect_timeout_seconds(['connect_timeout' => 999]));
        $test->assertSame(5, pdo_connect_timeout_seconds([]));
        $test->assertSame(1, pdo_connect_retry_count(['connect_retries' => 0]));
        $test->assertSame(10, pdo_connect_retry_count(['connect_retries' => 99]));
        $test->assertSame(4, pdo_connect_retry_count([]));
    },

    'pdo_connect_backoff_us length matches attempts minus one' => function (TestCase $test): void {
        $test->assertSame([], pdo_connect_backoff_us(1));
        $test->assertSame([50_000, 150_000, 350_000], pdo_connect_backoff_us(4));
    },

    'pdo_is_transient_connect_error detects resource temporarily unavailable' => function (TestCase $test): void {
        $e = new PDOException('SQLSTATE[HY000] [2002] Resource temporarily unavailable');
        $test->assertTrue(pdo_is_transient_connect_error($e));
        $test->assertSame('2002', pdo_driver_error_code($e));
    },

    'pdo_is_transient_connect_error detects too many connections' => function (TestCase $test): void {
        $e = new PDOException('SQLSTATE[08004] [1040] Too many connections');
        $test->assertTrue(pdo_is_transient_connect_error($e));
    },

    'pdo_is_transient_connect_error rejects auth failures' => function (TestCase $test): void {
        $e = new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'x'@'localhost'");
        $test->assertFalse(pdo_is_transient_connect_error($e));
        $test->assertSame('1045', pdo_driver_error_code($e));
    },

    'pdo_connect_with_retry succeeds on second attempt after transient failure' => function (TestCase $test): void {
        $calls = 0;
        $slept = [];
        $seenTimeout = null;
        $pdo = new class extends PDO {
            public function __construct()
            {
            }
        };

        $result = pdo_connect_with_retry(
            [
                'host' => 'localhost',
                'database' => 't',
                'username' => 'u',
                'password' => 'p',
                'connect_retries' => 3,
                'connect_timeout' => 2,
            ],
            static function (string $dsn, string $user, string $pass, array $options) use (&$calls, &$seenTimeout, $pdo): PDO {
                $calls++;
                $seenTimeout = $options[PDO::ATTR_TIMEOUT] ?? null;
                if ($calls === 1) {
                    throw new PDOException('SQLSTATE[HY000] [2002] Resource temporarily unavailable');
                }
                return $pdo;
            },
            static function (int $us) use (&$slept): void {
                $slept[] = $us;
            }
        );

        $test->assertSame($pdo, $result);
        $test->assertSame(2, $calls);
        $test->assertSame(2, $seenTimeout);
        $test->assertSame([50_000], $slept);
    },

    'pdo_connect_with_retry does not retry non-transient errors' => function (TestCase $test): void {
        $calls = 0;
        $slept = 0;
        try {
            pdo_connect_with_retry(
                ['host' => 'localhost', 'database' => 't', 'username' => 'u', 'password' => 'p', 'connect_retries' => 4],
                static function () use (&$calls): PDO {
                    $calls++;
                    throw new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'u'@'localhost'");
                },
                static function () use (&$slept): void {
                    $slept++;
                }
            );
            $test->assertTrue(false, 'expected exception');
        } catch (PDOException $e) {
            $test->assertStringContains('1045', $e->getMessage());
        }
        $test->assertSame(1, $calls);
        $test->assertSame(0, $slept);
    },

    'pdo_connect_with_retry exhausts retries then rethrows' => function (TestCase $test): void {
        $calls = 0;
        $slept = [];
        try {
            pdo_connect_with_retry(
                ['host' => 'localhost', 'database' => 't', 'username' => 'u', 'password' => 'p', 'connect_retries' => 3],
                static function () use (&$calls): PDO {
                    $calls++;
                    throw new PDOException('SQLSTATE[HY000] [2002] Resource temporarily unavailable');
                },
                static function (int $us) use (&$slept): void {
                    $slept[] = $us;
                }
            );
            $test->assertTrue(false, 'expected exception');
        } catch (PDOException $e) {
            $test->assertStringContains('2002', $e->getMessage());
        }
        $test->assertSame(3, $calls);
        $test->assertSame([50_000, 150_000], $slept);
    },
];
