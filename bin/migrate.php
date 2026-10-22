<?php

declare(strict_types=1);

/**
 * Apply pending database/migrations/*.sql files.
 *
 *   php bin/migrate.php
 *   php bin/migrate.php --dry-run
 */

require __DIR__ . '/../src/bootstrap.php';

$dryRun = in_array('--dry-run', $argv, true);

try {
    $pending = migrate_pending_versions();
    if ($pending === []) {
        echo "OK: no pending migrations.\n";
        exit(0);
    }

    echo 'Pending: ' . implode(', ', $pending) . "\n";
    if ($dryRun) {
        echo "Dry-run only; nothing applied.\n";
        exit(0);
    }

    $result = migrate_run();
    if ($result['applied'] === []) {
        echo "OK: nothing new applied (effects already present).\n";
    } else {
        echo 'Applied: ' . implode(', ', $result['applied']) . "\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'migrate failed: ' . $e->getMessage() . "\n");
    exit(1);
}
