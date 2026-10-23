<?php

declare(strict_types=1);

/**
 * Non-blocking flock for CLI cron jobs.
 * Prevents overlapping send-* processes from exhausting shared-hosting slots.
 */

function cli_project_root(): string
{
    return dirname(__DIR__);
}

function cli_lock_path(string $name): string
{
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $name) ?: 'job';
    $root = cli_project_root();
    $hash = substr(hash('sha256', $root), 0, 12);

    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'wolhg-' . $hash . '-' . $safe . '.lock';
}

/**
 * Run $fn under an exclusive non-blocking lock.
 *
 * @param resource|null $errorOut Writable stream for diagnostics (default STDERR).
 * @return int process exit code (0 = ok or skipped busy lock, 1 = failure)
 */
function cli_with_lock(string $name, callable $fn, $errorOut = null): int
{
    $errorOut ??= STDERR;
    $path = cli_lock_path($name);
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        fwrite($errorOut, 'cli lock: cannot open ' . $path . PHP_EOL);

        return 1;
    }

    try {
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fwrite($errorOut, 'cli lock: busy (' . $name . '), skipping' . PHP_EOL);

            return 0;
        }

        try {
            $fn();

            return 0;
        } catch (Throwable $e) {
            fwrite($errorOut, 'cli job failed: ' . $e->getMessage() . PHP_EOL);

            return 1;
        } finally {
            flock($handle, LOCK_UN);
        }
    } finally {
        fclose($handle);
    }
}
