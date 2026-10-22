<?php

declare(strict_types=1);

/**
 * Prints live homepage metric tiles from source tables.
 *
 * Counters are not cached: the dashboard always aggregates people / prayer_requests.
 * This script recomputes the same numbers with the fan-out-safe logic so you can
 * compare them against what you see on "/" after deploy.
 *
 * Usage: php bin/verify-dashboard-stats.php [--church-id=N]
 */

require __DIR__ . '/../src/bootstrap.php';

$churchId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--church-id=(\d+)$/', $arg, $m)) {
        $churchId = (int) $m[1];
    }
}

$groupWhere = 'g.is_active = 1';
$params = [];
if ($churchId !== null) {
    $groupWhere .= ' AND g.church_id = ?';
    $params[] = $churchId;
}

$groups = fetch_all("SELECT g.id FROM home_groups g WHERE $groupWhere", $params);
$people = fetch_all(
    "SELECT p.id, p.group_id, p.first_visit_date
     FROM people p
     JOIN home_groups g ON g.id = p.group_id
     WHERE $groupWhere AND p.deleted_at IS NULL",
    $params
);
$prayers = fetch_all(
    "SELECT pr.id, pr.group_id, pr.status
     FROM prayer_requests pr
     JOIN home_groups g ON g.id = pr.group_id
     WHERE $groupWhere",
    $params
);

$metrics = ChurchCare\dashboard_metrics($groups, $people, $prayers);

$sqlStats = fetch_one(
    "SELECT
        COUNT(*) AS groups_count,
        COALESCE(SUM((
            SELECT COUNT(*) FROM people p
            WHERE p.group_id = g.id AND p.deleted_at IS NULL
        )), 0) AS people_count,
        COALESCE(SUM((
            SELECT COUNT(*) FROM people p
            WHERE p.group_id = g.id AND p.deleted_at IS NULL
              AND p.first_visit_date >= CURDATE() - INTERVAL 30 DAY
        )), 0) AS newcomers,
        COALESCE(SUM((
            SELECT COUNT(*) FROM prayer_requests pr
            WHERE pr.group_id = g.id AND pr.status IN ('new','praying')
        )), 0) AS open_prayers
     FROM home_groups g
     WHERE $groupWhere",
    $params
) ?: [];

$statusRows = fetch_all(
    "SELECT pr.status, COUNT(*) AS cnt
     FROM prayer_requests pr
     JOIN home_groups g ON g.id = pr.group_id
     WHERE $groupWhere
     GROUP BY pr.status
     ORDER BY FIELD(pr.status, 'new','praying','testimony','closed')",
    $params
);

$scope = $churchId !== null ? "church_id={$churchId}" : 'all active groups';
echo "Dashboard metrics ({$scope})" . PHP_EOL;
echo str_repeat('-', 40) . PHP_EOL;
echo sprintf("Домашних групп : %d\n", (int) $metrics['groups_count']);
echo sprintf("Людей          : %d\n", (int) $metrics['people_count']);
echo sprintf("Новых за месяц : %d\n", (int) $metrics['newcomers']);
echo sprintf("Открытых нужд  : %d\n", (int) $metrics['open_prayers']);
echo PHP_EOL;
echo "SQL dashboard query (must match):" . PHP_EOL;
echo sprintf(
    "  groups=%d people=%d newcomers=%d open_prayers=%d\n",
    (int) ($sqlStats['groups_count'] ?? 0),
    (int) ($sqlStats['people_count'] ?? 0),
    (int) ($sqlStats['newcomers'] ?? 0),
    (int) ($sqlStats['open_prayers'] ?? 0)
);
echo PHP_EOL;
echo "Prayer statuses:" . PHP_EOL;
if (!$statusRows) {
    echo "  (none)\n";
}
foreach ($statusRows as $row) {
    $status = (string) $row['status'];
    echo sprintf(
        "  %-14s %d\n",
        ChurchCare\prayer_status_label($status) . " ({$status})",
        (int) $row['cnt']
    );
}

$mismatch =
    (int) $metrics['groups_count'] !== (int) ($sqlStats['groups_count'] ?? -1)
    || (int) $metrics['people_count'] !== (int) ($sqlStats['people_count'] ?? -1)
    || (int) $metrics['newcomers'] !== (int) ($sqlStats['newcomers'] ?? -1)
    || (int) $metrics['open_prayers'] !== (int) ($sqlStats['open_prayers'] ?? -1);

if ($mismatch) {
    fwrite(STDERR, "ERROR: PHP aggregator and SQL dashboard query disagree.\n");
    exit(1);
}

echo PHP_EOL . "OK: PHP aggregator matches SQL dashboard query.\n";
