<?php

declare(strict_types=1);

/**
 * Ordered SQL migrations under database/migrations/ with schema_migrations tracking.
 *
 * Safety model:
 * - Baseline only records migrations whose effect can be verified present.
 * - Pending migrations always run (CREATE IF NOT EXISTS / idempotent SQL preferred).
 * - If a version is recorded but its effect probe returns false, SQL is re-applied (self-heal).
 * - Effect probes use exact filenames — never a bare numeric prefix shared by two files.
 */

function migrate_migrations_dir(): string
{
    return dirname(__DIR__) . '/database/migrations';
}

/**
 * @return list<string> basenames like 026_districts.sql
 */
function migrate_list_files(): array
{
    $dir = migrate_migrations_dir();
    if (!is_dir($dir)) {
        return [];
    }
    $files = [];
    foreach (scandir($dir) ?: [] as $name) {
        if (preg_match('/^\d{3}_.+\.sql$/', $name) === 1) {
            $files[] = $name;
        }
    }
    sort($files, SORT_STRING);

    return $files;
}

function migrate_ensure_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(190) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * @return list<string>
 */
function migrate_applied_versions(PDO $pdo): array
{
    migrate_ensure_table($pdo);
    $rows = $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);

    return array_map('strval', $rows ?: []);
}

function migrate_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->execute([$table]);

    return (bool) $stmt->fetchColumn();
}

function migrate_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->execute([$table, $column]);

    return (bool) $stmt->fetchColumn();
}

function migrate_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
    );
    $stmt->execute([$table, $index]);

    return (bool) $stmt->fetchColumn();
}

function migrate_users_role_enum(PDO $pdo): string
{
    $stmt = $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role' LIMIT 1"
    );
    $type = $stmt ? (string) $stmt->fetchColumn() : '';

    return $type;
}

/**
 * Map migration basename → effect probe kind for unit tests and docs.
 *
 * @return array<string, array{type: string, table?: string, column?: string, tables?: list<string>}>
 */
function migrate_effect_probe_map(): array
{
    return [
        '020_person_group_memberships.sql' => ['type' => 'table', 'table' => 'person_group_memberships'],
        '021_mcp_api_keys.sql' => ['type' => 'table', 'table' => 'user_api_keys'],
        '022_church_slug.sql' => ['type' => 'column', 'table' => 'churches', 'column' => 'slug'],
        '025_church_deleted_at.sql' => ['type' => 'column', 'table' => 'churches', 'column' => 'deleted_at'],
        '026_districts.sql' => ['type' => 'table', 'table' => 'districts'],
        '027_rename_regional_leader.sql' => ['type' => 'users_role_enum_district_leader'],
        '028_demo_church.sql' => ['type' => 'column', 'table' => 'churches', 'column' => 'is_demo'],
        '029_church_landing.sql' => ['type' => 'table', 'table' => 'church_landing'],
        '030_church_landing_logo.sql' => ['type' => 'column', 'table' => 'church_landing', 'column' => 'logo'],
        // 030_drop_manage_permissions_action.sql — intentionally no probe (idempotent DELETE).
        '038_mcp_oauth.sql' => [
            'type' => 'tables',
            'tables' => ['mcp_oauth_clients', 'mcp_oauth_codes', 'mcp_oauth_tokens', 'mcp_oauth_cimd'],
        ],
        '043_users_email_unique_per_church.sql' => [
            'type' => 'index',
            'table' => 'users',
            'index' => 'uniq_users_email_church',
        ],
    ];
}

/**
 * Detect whether a migration's effect is already present (for baseline / self-heal).
 *
 * null  = unknown (do not treat as applied; run SQL if not recorded)
 * true  = effect present (safe to record / skip)
 * false = effect missing (must apply or repair)
 */
function migrate_effect_present(PDO $pdo, string $version): ?bool
{
    $map = migrate_effect_probe_map();
    $probe = $map[$version] ?? null;
    if ($probe === null) {
        return null;
    }

    return match ($probe['type']) {
        'table' => migrate_table_exists($pdo, (string) $probe['table']),
        'column' => migrate_column_exists($pdo, (string) $probe['table'], (string) $probe['column']),
        'index' => migrate_index_exists($pdo, (string) $probe['table'], (string) $probe['index']),
        'tables' => migrate_all_tables_exist($pdo, $probe['tables'] ?? []),
        'users_role_enum_district_leader' => migrate_district_leader_enum_present($pdo),
        default => null,
    };
}

/**
 * @param list<string> $tables
 */
function migrate_all_tables_exist(PDO $pdo, array $tables): bool
{
    if ($tables === []) {
        return false;
    }
    foreach ($tables as $table) {
        if (!migrate_table_exists($pdo, (string) $table)) {
            return false;
        }
    }

    return true;
}

function migrate_district_leader_enum_present(PDO $pdo): bool
{
    $enum = migrate_users_role_enum($pdo);

    return str_contains($enum, 'district_leader') && !str_contains($enum, 'regional_leader');
}

/**
 * On databases that predate schema_migrations, record only verified-applied history.
 *
 * NEVER mark a migration as applied when its effect is unknown or missing —
 * that caused production to skip 021_mcp_api_keys.sql while showing the profile error.
 */
function migrate_baseline_legacy(PDO $pdo): void
{
    $applied = migrate_applied_versions($pdo);
    if ($applied !== []) {
        return;
    }
    if (!migrate_table_exists($pdo, 'churches')) {
        return;
    }

    foreach (migrate_list_files() as $file) {
        if (migrate_effect_present($pdo, $file) === true) {
            migrate_record($pdo, $file);
        }
    }
}

function migrate_record(PDO $pdo, string $version): void
{
    $stmt = $pdo->prepare('INSERT IGNORE INTO schema_migrations (version) VALUES (?)');
    $stmt->execute([$version]);
}

/**
 * Split a migration SQL file into executable statements.
 *
 * @return list<string>
 */
function migrate_split_sql(string $sql): array
{
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    // Strip /* ... */ comments.
    $sql = preg_replace('#/\*.*?\*/#s', '', $sql) ?? $sql;
    $lines = explode("\n", $sql);
    $buf = '';
    $statements = [];
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if ($trim === '' || str_starts_with($trim, '--')) {
            continue;
        }
        $buf .= $line . "\n";
        if (str_ends_with(rtrim($line), ';')) {
            $stmt = trim($buf);
            $buf = '';
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
        }
    }
    $tail = trim($buf);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

/**
 * Drop legacy single-column UNIQUE on users.email (index name is usually `email`).
 */
function migrate_drop_single_column_unique_email(PDO $pdo): void
{
    $stmt = $pdo->query(
        "SELECT INDEX_NAME,
                COUNT(*) AS col_count,
                SUM(COLUMN_NAME = 'email') AS email_cols
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND NON_UNIQUE = 0
           AND INDEX_NAME <> 'PRIMARY'
         GROUP BY INDEX_NAME"
    );
    if (!$stmt) {
        return;
    }
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((int) ($row['col_count'] ?? 0) === 1 && (int) ($row['email_cols'] ?? 0) === 1) {
            $name = str_replace('`', '``', (string) $row['INDEX_NAME']);
            $pdo->exec('ALTER TABLE users DROP INDEX `' . $name . '`');
        }
    }
}

/**
 * Idempotent: email uniqueness becomes (email, church_id) for multi-tenant signup.
 */
function migrate_apply_users_email_unique_per_church(PDO $pdo): void
{
    migrate_drop_single_column_unique_email($pdo);
    if (!migrate_index_exists($pdo, 'users', 'uniq_users_email_church')) {
        $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uniq_users_email_church (email, church_id)');
    }
}

/**
 * Execute migration SQL statements without requiring a fresh schema_migrations row.
 * Used for self-heal when a version was recorded but the effect is missing.
 */
function migrate_exec_file(PDO $pdo, string $version): void
{
    $path = migrate_migrations_dir() . '/' . $version;
    if (!is_file($path)) {
        throw new RuntimeException('Migration file missing: ' . $version);
    }

    // DDL index swaps are not safely re-run as raw SQL after a partial apply.
    if ($version === '043_users_email_unique_per_church.sql') {
        try {
            migrate_apply_users_email_unique_per_church($pdo);
            migrate_record($pdo, $version);
        } catch (Throwable $e) {
            throw new RuntimeException('Migration failed: ' . $version . ' — ' . $e->getMessage(), 0, $e);
        }
        if (function_exists('db_table_exists_clear_cache')) {
            db_table_exists_clear_cache();
        }
        return;
    }

    $sql = (string) file_get_contents($path);
    $statements = migrate_split_sql($sql);
    if ($statements === []) {
        throw new RuntimeException('Migration file is empty: ' . $version);
    }

    $pdo->beginTransaction();
    try {
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        migrate_record($pdo, $version);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException('Migration failed: ' . $version . ' — ' . $e->getMessage(), 0, $e);
    }

    if (function_exists('db_table_exists_clear_cache')) {
        db_table_exists_clear_cache();
    }
}

function migrate_apply_file(PDO $pdo, string $version): void
{
    migrate_exec_file($pdo, $version);
}

/**
 * @return array{
 *   applied: list<string>,
 *   repaired: list<string>,
 *   skipped: list<string>,
 *   pending_before: list<string>
 * }
 */
function migrate_run(?PDO $pdo = null): array
{
    $pdo ??= db();
    migrate_ensure_table($pdo);
    migrate_baseline_legacy($pdo);

    $appliedNow = [];
    $repaired = [];
    $files = migrate_list_files();
    $done = array_fill_keys(migrate_applied_versions($pdo), true);
    $pendingBefore = [];
    foreach ($files as $file) {
        if (!isset($done[$file])) {
            $pendingBefore[] = $file;
        }
    }

    foreach ($files as $file) {
        if (isset($done[$file])) {
            // Self-heal: recorded in schema_migrations but effect probe says missing.
            if (migrate_effect_present($pdo, $file) === false) {
                migrate_exec_file($pdo, $file);
                $repaired[] = $file;
            }
            continue;
        }
        // Skip apply only when we can prove the effect already exists.
        if (migrate_effect_present($pdo, $file) === true) {
            migrate_record($pdo, $file);
            $done[$file] = true;
            continue;
        }
        migrate_apply_file($pdo, $file);
        $appliedNow[] = $file;
        $done[$file] = true;
    }

    return [
        'applied' => $appliedNow,
        'repaired' => $repaired,
        'skipped' => array_values(array_diff($files, $pendingBefore, $appliedNow, $repaired)),
        'pending_before' => $pendingBefore,
    ];
}

/**
 * @return list<string>
 */
function migrate_pending_versions(?PDO $pdo = null): array
{
    $pdo ??= db();
    migrate_ensure_table($pdo);
    migrate_baseline_legacy($pdo);
    $done = array_fill_keys(migrate_applied_versions($pdo), true);
    $pending = [];
    foreach (migrate_list_files() as $file) {
        if (isset($done[$file])) {
            // Still pending work if recorded but broken.
            if (migrate_effect_present($pdo, $file) === false) {
                $pending[] = $file;
            }
            continue;
        }
        if (migrate_effect_present($pdo, $file) === true) {
            migrate_record($pdo, $file);
            continue;
        }
        $pending[] = $file;
    }

    return $pending;
}

function migrate_try_lock(PDO $pdo): bool
{
    $stmt = $pdo->query("SELECT GET_LOCK('wolhg_schema_migrate', 0)");
    $got = $stmt ? (int) $stmt->fetchColumn() : 0;

    return $got === 1;
}

function migrate_release_lock(PDO $pdo): void
{
    $pdo->query("SELECT RELEASE_LOCK('wolhg_schema_migrate')");
}

/**
 * Auto-run pending migrations once (web/CLI). Safe under concurrent requests via GET_LOCK.
 *
 * @return array{ran: bool, result?: array<string, mixed>, reason?: string}
 */
function migrate_auto_run(?PDO $pdo = null): array
{
    $enabled = app_config('migrate.auto');
    if ($enabled === false || $enabled === 0 || $enabled === '0') {
        return ['ran' => false, 'reason' => 'disabled'];
    }

    $pdo ??= db();
    try {
        $pending = migrate_pending_versions($pdo);
    } catch (Throwable $e) {
        return ['ran' => false, 'reason' => 'pending-check-failed: ' . $e->getMessage()];
    }
    if ($pending === []) {
        return ['ran' => false, 'reason' => 'up-to-date'];
    }
    if (!migrate_try_lock($pdo)) {
        return ['ran' => false, 'reason' => 'lock-busy'];
    }
    try {
        $result = migrate_run($pdo);

        return ['ran' => true, 'result' => $result];
    } finally {
        migrate_release_lock($pdo);
    }
}

function migrate_token_configured(): string
{
    $token = trim((string) (app_config('migrate.token') ?: ''));
    if ($token !== '') {
        return $token;
    }
    $key = (string) (app_config('security.encryption_key') ?: '');
    if ($key === '' || $key === 'local-dev-key') {
        return '';
    }

    return hash_hmac('sha256', 'wolhg-migrate', $key);
}

function migrate_request_token(): string
{
    $header = (string) ($_SERVER['HTTP_X_MIGRATE_TOKEN'] ?? '');
    if ($header !== '') {
        return trim($header);
    }

    return trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
}

/**
 * HTTP entrypoint: POST /system/migrate with X-Migrate-Token.
 */
function migrate_handle_http_request(): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $expected = migrate_token_configured();
    $provided = migrate_request_token();
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $result = migrate_run();
        echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
