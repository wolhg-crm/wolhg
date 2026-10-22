<?php

declare(strict_types=1);

/**
 * Personal API keys for MCP / machine access.
 * Pattern mirrors smmpublisher: token shown once, only SHA-256 hash stored.
 */

const MCP_API_KEY_PREFIX = 'wolhg_ak_';
const MCP_API_KEY_SECRET_BYTES = 32;
const MCP_API_KEY_PREFIX_VISIBLE = 8;
const MCP_API_KEY_MAX_PER_USER = 20;

/**
 * @return list<string>
 */
function mcp_api_key_scopes(): array
{
    return ['read', 'attendance', 'people', 'roles', 'church'];
}

/**
 * @return array<string, string>
 */
function mcp_api_key_scope_labels(): array
{
    return [
        'read' => 'Чтение (группы, радар, статистика ДГ, встречи)',
        'attendance' => 'Посещаемость (создание встреч и отметка присутствующих)',
        'people' => 'Люди и профиль (обновление карточек и своей учётной записи)',
        'roles' => 'Роли (повышение / понижение по матрице переходов)',
        'church' => 'Церковная аналитика (только при праве view_church_analytics)',
    ];
}

function mcp_api_keys_table_ready(): bool
{
    static $ready = null;
    static $healAttempted = false;
    if ($ready === true) {
        return true;
    }
    if (function_exists('db_table_exists') && db_table_exists('user_api_keys')) {
        return $ready = true;
    }
    // Self-heal once per request: production may have 021 falsely marked applied without the table.
    if (!$healAttempted && function_exists('migrate_auto_run')) {
        $healAttempted = true;
        try {
            migrate_auto_run();
        } catch (Throwable) {
            // Fall through to readiness check.
        }
        if (function_exists('db_table_exists_clear_cache')) {
            db_table_exists_clear_cache();
        }
    }
    if (function_exists('db_table_exists') && db_table_exists('user_api_keys')) {
        return $ready = true;
    }

    return false;
}

/**
 * @param list<string>|mixed $scopes
 * @return list<string>
 */
function mcp_normalize_api_key_scopes($scopes): array
{
    if (!is_array($scopes)) {
        return [];
    }
    $allowed = array_fill_keys(mcp_api_key_scopes(), true);
    $seen = [];
    foreach ($scopes as $raw) {
        $scope = strtolower(trim((string) $raw));
        if ($scope === '' || !isset($allowed[$scope]) || isset($seen[$scope])) {
            continue;
        }
        $seen[$scope] = true;
    }
    $ordered = [];
    foreach (mcp_api_key_scopes() as $scope) {
        if (isset($seen[$scope])) {
            $ordered[] = $scope;
        }
    }
    return $ordered;
}

/**
 * publish/settings-style implication: write scopes imply read.
 *
 * @param list<string> $scopes
 */
function mcp_has_api_key_scope(array $scopes, string $required): bool
{
    $required = strtolower(trim($required));
    if ($required === '') {
        return true;
    }
    if (in_array($required, $scopes, true)) {
        return true;
    }
    if ($required === 'read') {
        foreach (['attendance', 'people', 'roles', 'church'] as $write) {
            if (in_array($write, $scopes, true)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Scopes the current user role is allowed to grant on a new key.
 *
 * @return list<string>
 */
function mcp_scopes_allowed_for_role(string $role): array
{
    $all = mcp_api_key_scopes();
    return match ($role) {
        'member' => ['read', 'people'],
        'assistant', 'leader' => ['read', 'attendance', 'people'],
        'district_leader', 'pastor' => $all,
        'admin' => $all,
        default => ['read'],
    };
}

/**
 * @param list<string> $requested
 * @return list<string>
 */
function mcp_filter_scopes_for_user(array $user, array $requested): array
{
    $allowed = array_fill_keys(mcp_scopes_allowed_for_role((string) $user['role']), true);
    $out = [];
    foreach (mcp_normalize_api_key_scopes($requested) as $scope) {
        if (isset($allowed[$scope])) {
            $out[] = $scope;
        }
    }
    return $out;
}

function mcp_hash_api_key_token(string $token): string
{
    return hash('sha256', $token);
}

function mcp_is_api_key_token(string $token): bool
{
    return str_starts_with(trim($token), MCP_API_KEY_PREFIX);
}

/**
 * @return array{id: int, name: string, token: string, token_prefix: string, scopes: list<string>, expires_at: ?string}
 */
function mcp_create_api_key(array $user, string $name, array $scopes, ?string $expiresAt = null): array
{
    if (!mcp_api_keys_table_ready()) {
        throw new RuntimeException('Таблица API-ключей недоступна: примените миграцию 021_mcp_api_keys.sql');
    }
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Укажите название ключа');
    }
    if (mb_strlen($name) > 120) {
        throw new InvalidArgumentException('Название ключа слишком длинное');
    }

    $normalized = mcp_filter_scopes_for_user($user, $scopes);
    if ($normalized === []) {
        throw new InvalidArgumentException('Выберите хотя бы одну доступную область (scope)');
    }

    $active = (int) (fetch_one(
        'SELECT COUNT(*) AS c FROM user_api_keys WHERE user_id = ? AND revoked_at IS NULL',
        [(int) $user['id']]
    )['c'] ?? 0);
    if ($active >= MCP_API_KEY_MAX_PER_USER) {
        throw new RuntimeException('Достигнут лимит активных API-ключей (' . MCP_API_KEY_MAX_PER_USER . ')');
    }

    if ($expiresAt !== null && $expiresAt !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $expiresAt)) {
            throw new InvalidArgumentException('Некорректная дата истечения');
        }
    } else {
        $expiresAt = null;
    }

    $secret = bin2hex(random_bytes(MCP_API_KEY_SECRET_BYTES));
    $token = MCP_API_KEY_PREFIX . $secret;
    $prefix = MCP_API_KEY_PREFIX . substr($secret, 0, MCP_API_KEY_PREFIX_VISIBLE);
    $hash = mcp_hash_api_key_token($token);

    db()->prepare(
        'INSERT INTO user_api_keys (user_id, name, token_prefix, token_hash, scopes, expires_at) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        (int) $user['id'],
        $name,
        $prefix,
        $hash,
        json_encode($normalized, JSON_UNESCAPED_UNICODE),
        $expiresAt,
    ]);
    $id = (int) db()->lastInsertId();
    audit('mcp_api_key_create', 'user_api_key', $id, ['scopes' => $normalized, 'name' => $name]);

    return [
        'id' => $id,
        'name' => $name,
        'token' => $token,
        'token_prefix' => $prefix,
        'scopes' => $normalized,
        'expires_at' => $expiresAt,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function mcp_list_api_keys(array $user): array
{
    if (!mcp_api_keys_table_ready()) {
        return [];
    }
    $rows = fetch_all(
        'SELECT id, name, token_prefix, scopes, last_used_at, expires_at, revoked_at, created_at
         FROM user_api_keys
         WHERE user_id = ?
         ORDER BY created_at DESC',
        [(int) $user['id']]
    );
    foreach ($rows as &$row) {
        $decoded = json_decode((string) $row['scopes'], true);
        $row['scopes'] = is_array($decoded) ? mcp_normalize_api_key_scopes($decoded) : [];
        $row['active'] = $row['revoked_at'] === null
            && ($row['expires_at'] === null || strtotime((string) $row['expires_at']) > time());
    }
    unset($row);
    return $rows;
}

function mcp_revoke_api_key(array $user, int $keyId): bool
{
    if (!mcp_api_keys_table_ready() || $keyId <= 0) {
        return false;
    }
    $row = fetch_one(
        'SELECT id FROM user_api_keys WHERE id = ? AND user_id = ? AND revoked_at IS NULL LIMIT 1',
        [$keyId, (int) $user['id']]
    );
    if (!$row) {
        return false;
    }
    db()->prepare('UPDATE user_api_keys SET revoked_at = NOW() WHERE id = ? AND user_id = ?')
        ->execute([$keyId, (int) $user['id']]);
    audit('mcp_api_key_revoke', 'user_api_key', $keyId);
    return true;
}

/**
 * @return array{user: array<string, mixed>, scopes: list<string>, key_id: int}|null
 */
function mcp_resolve_bearer_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }

    // Try OAuth access token first (wolhg_at_…)
    if (function_exists('mcp_oauth_looks_like_access_token') && mcp_oauth_looks_like_access_token($token)) {
        if (function_exists('mcp_oauth_authenticate_access_token') && function_exists('mcp_oauth_resource_url')) {
            $principal = mcp_oauth_authenticate_access_token($token, mcp_oauth_resource_url());
            if ($principal !== null) {
                return [
                    'user' => $principal['user'],
                    'scopes' => $principal['scopes'],
                    'key_id' => $principal['oauth_token_id'],
                ];
            }
        }
        return null;
    }

    if (!mcp_is_api_key_token($token) || !mcp_api_keys_table_ready()) {
        return null;
    }
    $hash = mcp_hash_api_key_token($token);
    $row = fetch_one(
        'SELECT k.*, u.id AS uid, u.name AS uname, u.login, u.email, u.role, u.church_id, u.group_id,
                u.two_factor_enabled, u.two_factor_secret
         FROM user_api_keys k
         JOIN users u ON u.id = k.user_id
         WHERE k.token_hash = ? LIMIT 1',
        [$hash]
    );
    if (!$row) {
        return null;
    }
    if ($row['revoked_at'] !== null) {
        return null;
    }
    if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) <= time()) {
        return null;
    }

    $scopes = json_decode((string) $row['scopes'], true);
    $scopes = is_array($scopes) ? mcp_normalize_api_key_scopes($scopes) : [];
    if ($scopes === []) {
        return null;
    }

    // Touch last_used_at sparsely (at most once per minute) to limit write load.
    $lastUsed = $row['last_used_at'] !== null ? strtotime((string) $row['last_used_at']) : 0;
    if ($lastUsed === false || $lastUsed < time() - 60) {
        db()->prepare('UPDATE user_api_keys SET last_used_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);
    }

    $user = [
        'id' => (int) $row['uid'],
        'name' => (string) $row['uname'],
        'login' => (string) $row['login'],
        'email' => (string) $row['email'],
        'role' => (string) $row['role'],
        'church_id' => $row['church_id'] !== null ? (int) $row['church_id'] : null,
        'group_id' => $row['group_id'] !== null ? (int) $row['group_id'] : null,
        'two_factor_enabled' => (int) ($row['two_factor_enabled'] ?? 0),
        'two_factor_secret' => $row['two_factor_secret'] ?? null,
    ];

    return [
        'user' => $user,
        'scopes' => $scopes,
        'key_id' => (int) $row['id'],
    ];
}
