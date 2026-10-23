<?php

declare(strict_types=1);

/**
 * Pure PDO DSN / connect helpers (no bootstrap side effects).
 * Shared hosting (Timeweb) can return SQLSTATE 2002 "Resource temporarily
 * unavailable" under process or MySQL load — treat as transient and retry.
 */

/**
 * @param array{
 *   host?: mixed,
 *   port?: mixed,
 *   database?: mixed,
 *   unix_socket?: mixed,
 *   charset?: mixed
 * } $db
 */
function build_pdo_dsn(array $db): string
{
    $charset = trim((string) ($db['charset'] ?? 'utf8mb4'));
    if ($charset === '') {
        $charset = 'utf8mb4';
    }

    $socket = trim((string) ($db['unix_socket'] ?? ''));
    if ($socket !== '') {
        return sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, (string) ($db['database'] ?? ''), $charset);
    }

    $host = (string) ($db['host'] ?? 'localhost');
    $port = (int) ($db['port'] ?? 3306);
    if ($port <= 0) {
        $port = 3306;
    }

    return sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $host,
        $port,
        (string) ($db['database'] ?? ''),
        $charset
    );
}

function pdo_connect_timeout_seconds(array $db): int
{
    $timeout = (int) ($db['connect_timeout'] ?? 5);

    return max(1, min(60, $timeout));
}

function pdo_connect_retry_count(array $db): int
{
    $retries = (int) ($db['connect_retries'] ?? 4);

    return max(1, min(10, $retries));
}

/**
 * Backoff delays in microseconds between attempts (index 0 = after 1st failure).
 *
 * @return list<int>
 */
function pdo_connect_backoff_us(int $maxAttempts): array
{
    $delays = [50_000, 150_000, 350_000, 700_000];
    $needed = max(0, $maxAttempts - 1);

    return array_slice($delays, 0, $needed);
}

function pdo_driver_error_code(Throwable $e): string
{
    if ($e instanceof PDOException && is_array($e->errorInfo ?? null) && isset($e->errorInfo[1]) && $e->errorInfo[1] !== null && $e->errorInfo[1] !== '') {
        return (string) $e->errorInfo[1];
    }

    if (preg_match('/\[(\d{3,5})\]/', $e->getMessage(), $matches) === 1) {
        return $matches[1];
    }

    return '';
}

function pdo_is_transient_connect_error(Throwable $e): bool
{
    $message = $e->getMessage();
    $driverCode = pdo_driver_error_code($e);

    // MySQL client / server codes commonly seen under shared-hosting pressure.
    if (in_array($driverCode, ['1040', '1203', '2002', '2003', '2006', '2013'], true)) {
        return true;
    }

    if (str_contains($message, 'Too many connections')) {
        return true;
    }

    $transientSnippets = [
        'Resource temporarily unavailable',
        'Connection refused',
        'Connection timed out',
        "Can't connect to local MySQL server",
        "Can't connect to MySQL server",
        'server has gone away',
        'Lost connection to MySQL server',
    ];

    foreach ($transientSnippets as $snippet) {
        if (stripos($message, $snippet) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * @param array{
 *   host?: mixed,
 *   port?: mixed,
 *   database?: mixed,
 *   username?: mixed,
 *   password?: mixed,
 *   unix_socket?: mixed,
 *   charset?: mixed,
 *   connect_timeout?: mixed,
 *   connect_retries?: mixed
 * } $db
 * @param null|callable(string, string, string, array): PDO $connector
 * @param null|callable(int): void $sleepUs
 */
function pdo_connect_with_retry(array $db, ?callable $connector = null, ?callable $sleepUs = null): PDO
{
    $dsn = build_pdo_dsn($db);
    $username = (string) ($db['username'] ?? '');
    $password = (string) ($db['password'] ?? '');
    $timeout = pdo_connect_timeout_seconds($db);
    $attempts = pdo_connect_retry_count($db);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => $timeout,
    ];

    $connector ??= static function (string $dsn, string $username, string $password, array $options): PDO {
        return new PDO($dsn, $username, $password, $options);
    };
    $sleepUs ??= static function (int $microseconds): void {
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    };

    $backoffs = pdo_connect_backoff_us($attempts);
    $last = null;

    for ($i = 0; $i < $attempts; $i++) {
        try {
            return $connector($dsn, $username, $password, $options);
        } catch (Throwable $e) {
            $last = $e;
            $isLast = $i >= $attempts - 1;
            if ($isLast || !pdo_is_transient_connect_error($e)) {
                throw $e;
            }
            $sleepUs($backoffs[$i] ?? 0);
        }
    }

    throw $last ?? new RuntimeException('PDO connect failed without an exception.');
}
