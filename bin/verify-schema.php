<?php

declare(strict_types=1);

/**
 * Checks that the live DB has tables/columns required by the current app code.
 *
 * Use after deploy on shared hosting to catch missing migrations before pages 500:
 *   php bin/verify-schema.php
 *
 * Exit code 0 = ok, 1 = missing objects.
 */

require __DIR__ . '/../src/bootstrap.php';

/**
 * @return list<array{table: string, columns: list<string>, migration: string}>
 */
function schema_required_objects(): array
{
    return [
        [
            'table' => 'bot_accounts',
            'columns' => ['provider', 'chat_id', 'display_name'],
            'migration' => 'database/migrations/007_social_login_and_bots.sql',
        ],
        [
            'table' => 'role_invitation_requests',
            'columns' => ['user_id', 'requested_role', 'status'],
            'migration' => 'database/migrations/016_role_invitation_requests.sql',
        ],
        [
            'table' => 'miracles',
            'columns' => ['prayer_request_id', 'title', 'body', 'submitter_name', 'status', 'created_by'],
            'migration' => 'database/migrations/017_miracles.sql then 019_miracle_public_submit.sql',
        ],
        [
            'table' => 'prayer_requests',
            'columns' => ['submitter_name', 'is_anonymous', 'church_id'],
            'migration' => 'database/migrations/018_prayer_public_submit.sql',
        ],
        [
            'table' => 'person_group_memberships',
            'columns' => ['person_id', 'group_id', 'is_primary'],
            'migration' => 'database/migrations/020_person_group_memberships.sql',
        ],
        [
            'table' => 'user_api_keys',
            'columns' => ['user_id', 'token_hash', 'scopes', 'token_prefix'],
            'migration' => 'database/migrations/021_mcp_api_keys.sql',
        ],
        [
            'table' => 'mcp_oauth_clients',
            'columns' => ['client_id', 'client_id_hash', 'redirect_uris'],
            'migration' => 'database/migrations/038_mcp_oauth.sql',
        ],
        [
            'table' => 'mcp_oauth_codes',
            'columns' => ['code_hash', 'client_id_hash', 'user_id', 'code_challenge'],
            'migration' => 'database/migrations/038_mcp_oauth.sql',
        ],
        [
            'table' => 'mcp_oauth_tokens',
            'columns' => ['access_token_hash', 'refresh_token_hash', 'user_id', 'scopes'],
            'migration' => 'database/migrations/038_mcp_oauth.sql',
        ],
        [
            'table' => 'mcp_oauth_cimd',
            'columns' => ['client_id_hash', 'document_json', 'expires_at'],
            'migration' => 'database/migrations/038_mcp_oauth.sql',
        ],
        [
            'table' => 'churches',
            'columns' => ['slug', 'deleted_at', 'is_demo', 'demo_token', 'demo_seen_at'],
            'migration' => 'database/migrations/022_church_slug.sql, 025_church_deleted_at.sql, 028_demo_church.sql',
        ],
        [
            'table' => 'participant_requests',
            'columns' => ['church_id'],
            'migration' => 'database/migrations/024_participant_requests_church.sql',
        ],
        [
            'table' => 'districts',
            'columns' => ['church_id', 'name', 'polygon_geojson', 'bbox_min_lat', 'bbox_max_lat'],
            'migration' => 'database/migrations/026_districts.sql',
        ],
        [
            'table' => 'district_leaders',
            'columns' => ['district_id', 'user_id'],
            'migration' => 'database/migrations/026_districts.sql',
        ],
        [
            'table' => 'district_catalog_cache',
            'columns' => ['cache_key', 'payload', 'expires_at'],
            'migration' => 'database/migrations/026_districts.sql',
        ],
        [
            'table' => 'home_groups',
            'columns' => ['district_id'],
            'migration' => 'database/migrations/026_districts.sql',
        ],
        [
            'table' => 'schema_migrations',
            'columns' => ['version', 'applied_at'],
            'migration' => 'created by bin/migrate.php / migrate_auto_run()',
        ],
        [
            'table' => 'church_invitations',
            'columns' => ['token_hash', 'token_cipher', 'note', 'created_by', 'expires_at', 'used_at', 'used_by_user_id', 'used_church_id', 'revoked_at'],
            'migration' => 'database/migrations/041_church_invitations.sql, 042_church_invite_permanent.sql',
        ],
        [
            'table' => 'church_invitation_uses',
            'columns' => ['invite_id', 'user_id', 'church_id', 'created_at'],
            'migration' => 'database/migrations/042_church_invite_permanent.sql',
        ],
    ];
}

function schema_table_exists(string $table): bool
{
    $row = fetch_one(
        'SELECT 1 AS ok FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
        [$table]
    );

    return (bool) $row;
}

/**
 * @param list<string> $columns
 * @return list<string>
 */
function schema_missing_columns(string $table, array $columns): array
{
    $missing = [];
    foreach ($columns as $column) {
        $row = fetch_one(
            'SELECT 1 AS ok FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        );
        if (!$row) {
            $missing[] = $column;
        }
    }

    return $missing;
}

$failures = [];

foreach (schema_required_objects() as $requirement) {
    $table = $requirement['table'];

    if (!schema_table_exists($table)) {
        $failures[] = "missing table `{$table}` (apply {$requirement['migration']})";
        continue;
    }

    $missing = schema_missing_columns($table, $requirement['columns']);
    if ($missing !== []) {
        $failures[] = "table `{$table}` missing column(s): "
            . implode(', ', $missing)
            . " (apply {$requirement['migration']})";
    }
}

$dbName = (string) (fetch_one('SELECT DATABASE() AS db_name')['db_name'] ?? '');
echo "Schema check for database: {$dbName}\n";

if ($failures === []) {
    echo "OK: required tables and columns are present.\n";
    exit(0);
}

echo "FAILED:\n";
foreach ($failures as $failure) {
    echo ' - ' . $failure . "\n";
}
echo "\nSee docs/shared-hosting.md for the migration list.\n";
exit(1);
