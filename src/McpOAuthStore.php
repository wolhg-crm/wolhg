<?php

declare(strict_types=1);

/**
 * MCP OAuth 2.1 — database layer.
 *
 * Procedural functions wrapping PDO via db(), fetch_one(), fetch_all().
 * Tables: mcp_oauth_clients, mcp_oauth_codes, mcp_oauth_tokens, mcp_oauth_cimd.
 */

// ---------------------------------------------------------------------------
// Table readiness guards
// ---------------------------------------------------------------------------

function mcp_oauth_clients_table_ready(): bool
{
    static $ready = null;
    static $healAttempted = false;
    if ($ready === true) {
        return true;
    }
    if (function_exists('db_table_exists') && db_table_exists('mcp_oauth_clients')) {
        return $ready = true;
    }
    if (!$healAttempted && function_exists('migrate_auto_run')) {
        $healAttempted = true;
        try {
            migrate_auto_run();
        } catch (Throwable) {
            // Fall through.
        }
        if (function_exists('db_table_exists_clear_cache')) {
            db_table_exists_clear_cache();
        }
    }
    if (function_exists('db_table_exists') && db_table_exists('mcp_oauth_clients')) {
        return $ready = true;
    }

    return false;
}

function mcp_oauth_tokens_table_ready(): bool
{
    static $ready = null;
    if ($ready === true) {
        return true;
    }
    if (function_exists('db_table_exists') && db_table_exists('mcp_oauth_tokens')) {
        return $ready = true;
    }

    return false;
}

// ---------------------------------------------------------------------------
// Clients
// ---------------------------------------------------------------------------

/**
 * Dynamic Client Registration (RFC 7591).
 *
 * @param array{client_name?: string, redirect_uris: list<string>, grant_types?: list<string>, response_types?: list<string>, token_endpoint_auth_method?: string} $meta
 * @return array{client_id: string, client_secret: ?string, client_name: string, redirect_uris: list<string>}
 */
function mcp_oauth_register_dcr(array $meta): array
{
    if (!mcp_oauth_clients_table_ready()) {
        throw new RuntimeException('OAuth clients table not ready. Apply migration 038_mcp_oauth.sql.');
    }

    $redirectUris = $meta['redirect_uris'] ?? [];
    if (!is_array($redirectUris) || $redirectUris === []) {
        throw new InvalidArgumentException('redirect_uris is required and must be non-empty.');
    }
    foreach ($redirectUris as $uri) {
        if (!mcp_oauth_redirect_uri_is_allowed((string) $uri)) {
            throw new InvalidArgumentException('redirect_uri not allowed: ' . $uri);
        }
    }

    $clientName = trim((string) ($meta['client_name'] ?? 'MCP Client'));
    if ($clientName === '') {
        $clientName = 'MCP Client';
    }
    $grantTypes = mcp_oauth_string_list($meta['grant_types'] ?? ['authorization_code', 'refresh_token']);
    if ($grantTypes === []) {
        $grantTypes = ['authorization_code', 'refresh_token'];
    }
    $responseTypes = mcp_oauth_string_list($meta['response_types'] ?? ['code']);
    if ($responseTypes === []) {
        $responseTypes = ['code'];
    }
    $authMethod = (string) ($meta['token_endpoint_auth_method'] ?? 'none');
    if (!in_array($authMethod, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
        $authMethod = 'none';
    }

    $clientId = mcp_oauth_generate_client_id();
    $clientIdHash = mcp_oauth_hash($clientId);

    $clientSecret = null;
    $clientSecretHash = null;
    if ($authMethod !== 'none') {
        $clientSecret = mcp_oauth_generate_client_secret();
        $clientSecretHash = mcp_oauth_hash($clientSecret);
    }

    db()->prepare(
        'INSERT INTO mcp_oauth_clients
            (client_id, client_id_hash, client_secret_hash, client_name, redirect_uris, grant_types, response_types, token_endpoint_auth_method, registration_type)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $clientId,
        $clientIdHash,
        $clientSecretHash,
        $clientName,
        json_encode(array_values($redirectUris), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($grantTypes, JSON_UNESCAPED_UNICODE),
        json_encode($responseTypes, JSON_UNESCAPED_UNICODE),
        $authMethod,
        'dcr',
    ]);

    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'client_name' => $clientName,
        'redirect_uris' => array_values($redirectUris),
        'grant_types' => $grantTypes,
        'response_types' => $responseTypes,
        'token_endpoint_auth_method' => $authMethod,
    ];
}

/**
 * Resolve client by client_id string. If CIMD is enabled and client_id is a URL,
 * falls back to fetching / caching the CIMD document.
 *
 * @return array<string, mixed>|null
 */
function mcp_oauth_resolve_client(string $clientId): ?array
{
    if (!mcp_oauth_clients_table_ready()) {
        return null;
    }
    $clientId = trim($clientId);
    if ($clientId === '') {
        return null;
    }
    $hash = mcp_oauth_hash($clientId);
    $row = fetch_one(
        'SELECT * FROM mcp_oauth_clients WHERE client_id_hash = ? AND revoked_at IS NULL LIMIT 1',
        [$hash]
    );
    if ($row) {
        $row['redirect_uris'] = json_decode((string) $row['redirect_uris'], true) ?: [];
        $row['grant_types'] = json_decode((string) $row['grant_types'], true) ?: ['authorization_code'];
        $row['response_types'] = json_decode((string) $row['response_types'], true) ?: ['code'];
        return $row;
    }

    // CIMD fallback for URL-based client_ids (if enabled)
    if (mcp_oauth_advertise_cimd() && mcp_oauth_is_url_client_id($clientId)) {
        return mcp_oauth_resolve_cimd_client($clientId);
    }

    return null;
}

/**
 * Verify client_secret against stored hash.
 */
function mcp_oauth_verify_client_secret(array $client, string $secret): bool
{
    if (empty($client['client_secret_hash']) || $secret === '') {
        return false;
    }
    return hash_equals((string) $client['client_secret_hash'], mcp_oauth_hash($secret));
}

function mcp_oauth_revoke_client(string $clientId): bool
{
    if (!mcp_oauth_clients_table_ready()) {
        return false;
    }
    $hash = mcp_oauth_hash($clientId);
    $stmt = db()->prepare('UPDATE mcp_oauth_clients SET revoked_at = NOW() WHERE client_id_hash = ? AND revoked_at IS NULL');
    $stmt->execute([$hash]);
    return $stmt->rowCount() > 0;
}

// ---------------------------------------------------------------------------
// CIMD fetch + cache
// ---------------------------------------------------------------------------

/**
 * Fetch CIMD document and register client, returning client row.
 *
 * @return array<string, mixed>|null
 */
function mcp_oauth_resolve_cimd_client(string $clientIdUrl): ?array
{
    try {
        db()->query('SELECT 1 FROM mcp_oauth_cimd LIMIT 1');
    } catch (Throwable) {
        return null;
    }

    $hash = mcp_oauth_hash($clientIdUrl);

    // Check cache first
    $cached = fetch_one(
        'SELECT * FROM mcp_oauth_cimd WHERE client_id_hash = ? AND expires_at > NOW() LIMIT 1',
        [$hash]
    );
    if ($cached) {
        $doc = json_decode((string) $cached['document_json'], true);
        if (!is_array($doc)) {
            return null;
        }
        return mcp_oauth_cimd_doc_to_client($doc, $clientIdUrl);
    }

    // Fetch from URL
    $ssrf = mcp_oauth_ssrf_validate_url($clientIdUrl);
    if (!$ssrf['ok']) {
        return null;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\nUser-Agent: wolhg-mcp-oauth/1.0\r\n",
            'timeout' => 5,
            'follow_location' => 0,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $body = @file_get_contents($clientIdUrl, false, $ctx);
    if ($body === false || $body === '') {
        return null;
    }
    $doc = json_decode($body, true);
    if (!is_array($doc)) {
        return null;
    }

    $parsed = mcp_oauth_parse_cimd($doc, $clientIdUrl);
    if ($parsed === null) {
        return null;
    }

    // Cache in DB and also register DCR-style
    $docJson = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $expires = date('Y-m-d H:i:s', time() + 3600);

    try {
        db()->prepare(
            'INSERT INTO mcp_oauth_cimd (client_id_hash, client_id, document_json, fetched_at, expires_at)
             VALUES (?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE document_json = VALUES(document_json), fetched_at = NOW(), expires_at = VALUES(expires_at)'
        )->execute([$hash, $clientIdUrl, $docJson, $expires]);
    } catch (Throwable) {
        // Non-fatal: proceed without caching
    }

    // Register or update in mcp_oauth_clients
    if (mcp_oauth_clients_table_ready()) {
        $existing = fetch_one(
            'SELECT id FROM mcp_oauth_clients WHERE client_id_hash = ? LIMIT 1',
            [$hash]
        );
        if (!$existing) {
            try {
                db()->prepare(
                    'INSERT INTO mcp_oauth_clients
                        (client_id, client_id_hash, client_secret_hash, client_name, redirect_uris, grant_types, response_types, token_endpoint_auth_method, registration_type)
                     VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $clientIdUrl,
                    $hash,
                    $parsed['client_name'],
                    json_encode($parsed['redirect_uris'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($parsed['grant_types'], JSON_UNESCAPED_UNICODE),
                    json_encode($parsed['response_types'], JSON_UNESCAPED_UNICODE),
                    $parsed['token_endpoint_auth_method'],
                    'cimd',
                ]);
            } catch (Throwable) {
                // Ignore duplicate — may already exist from a previous fetch
            }
        }
    }

    return mcp_oauth_cimd_doc_to_client($doc, $clientIdUrl);
}

/**
 * @param array<string, mixed> $doc
 * @return array<string, mixed>|null
 */
function mcp_oauth_cimd_doc_to_client(array $doc, string $clientIdUrl): ?array
{
    $parsed = mcp_oauth_parse_cimd($doc, $clientIdUrl);
    if ($parsed === null) {
        return null;
    }
    return [
        'client_id' => $parsed['client_id'],
        'client_id_hash' => mcp_oauth_hash($parsed['client_id']),
        'client_secret_hash' => null,
        'client_name' => $parsed['client_name'],
        'redirect_uris' => $parsed['redirect_uris'],
        'grant_types' => $parsed['grant_types'],
        'response_types' => $parsed['response_types'],
        'token_endpoint_auth_method' => $parsed['token_endpoint_auth_method'],
        'registration_type' => 'cimd',
    ];
}

// ---------------------------------------------------------------------------
// Authorization codes
// ---------------------------------------------------------------------------

/**
 * Issue an authorization code.
 *
 * @param list<string> $scopes
 * @return string The plaintext code
 */
function mcp_oauth_issue_code(
    string $clientIdHash,
    int $userId,
    string $redirectUri,
    string $codeChallenge,
    string $codeChallengeMethod,
    array $scopes,
    string $resource,
): string {
    $code = mcp_oauth_generate_auth_code();
    $codeHash = mcp_oauth_hash($code);
    $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

    db()->prepare(
        'INSERT INTO mcp_oauth_codes
            (code_hash, client_id_hash, user_id, redirect_uri, code_challenge, code_challenge_method, scopes, resource, code_expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $codeHash,
        $clientIdHash,
        $userId,
        $redirectUri,
        $codeChallenge,
        $codeChallengeMethod,
        json_encode(array_values($scopes), JSON_UNESCAPED_UNICODE),
        $resource,
        $expiresAt,
    ]);

    return $code;
}

/**
 * Consume a code atomically (mark consumed). Returns code row or null if invalid/consumed.
 *
 * @return array<string, mixed>|null
 */
function mcp_oauth_consume_code(string $code): ?array
{
    $codeHash = mcp_oauth_hash(trim($code));

    $row = fetch_one(
        'SELECT * FROM mcp_oauth_codes
         WHERE code_hash = ?
           AND consumed_at IS NULL
           AND code_expires_at > NOW()
         LIMIT 1',
        [$codeHash]
    );
    if (!$row) {
        return null;
    }

    // Mark consumed
    $affected = db()->prepare('UPDATE mcp_oauth_codes SET consumed_at = NOW() WHERE code_hash = ? AND consumed_at IS NULL');
    $affected->execute([$codeHash]);
    if ($affected->rowCount() === 0) {
        return null; // Race: already consumed
    }

    $row['scopes'] = json_decode((string) $row['scopes'], true) ?: [];
    return $row;
}

// ---------------------------------------------------------------------------
// Tokens
// ---------------------------------------------------------------------------

/**
 * Issue access + refresh token pair.
 *
 * @param list<string> $scopes
 * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string, resource: string}
 */
function mcp_oauth_issue_tokens(
    string $clientIdHash,
    int $userId,
    array $scopes,
    string $resource,
): array {
    $accessToken = mcp_oauth_generate_access_token();
    $refreshToken = mcp_oauth_generate_refresh_token();
    $accessHash = mcp_oauth_hash($accessToken);
    $refreshHash = mcp_oauth_hash($refreshToken);
    $accessTtl = mcp_oauth_access_ttl();
    $refreshTtl = mcp_oauth_refresh_ttl();
    $accessExpires = date('Y-m-d H:i:s', time() + $accessTtl);
    $refreshExpires = date('Y-m-d H:i:s', time() + $refreshTtl);

    db()->prepare(
        'INSERT INTO mcp_oauth_tokens
            (access_token_hash, refresh_token_hash, client_id_hash, user_id, scopes, resource, access_expires_at, refresh_expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $accessHash,
        $refreshHash,
        $clientIdHash,
        $userId,
        json_encode(array_values($scopes), JSON_UNESCAPED_UNICODE),
        $resource,
        $accessExpires,
        $refreshExpires,
    ]);

    return [
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'token_type' => 'Bearer',
        'expires_in' => $accessTtl,
        'scope' => mcp_oauth_format_scopes($scopes),
        'resource' => mcp_url_canonicalize($resource),
    ];
}

/**
 * Refresh token rotation: revoke old access+refresh, issue new pair.
 *
 * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string, resource: string}|null
 */
function mcp_oauth_rotate_refresh_token(string $refreshToken, string $clientIdHash): ?array
{
    $refreshHash = mcp_oauth_hash(trim($refreshToken));

    $row = fetch_one(
        'SELECT * FROM mcp_oauth_tokens
         WHERE refresh_token_hash = ?
           AND client_id_hash = ?
           AND revoked_at IS NULL
           AND refresh_expires_at > NOW()
         LIMIT 1',
        [$refreshHash, $clientIdHash]
    );
    if (!$row) {
        return null;
    }

    // Revoke old token row
    db()->prepare('UPDATE mcp_oauth_tokens SET revoked_at = NOW() WHERE id = ?')
        ->execute([(int) $row['id']]);

    $scopes = json_decode((string) $row['scopes'], true) ?: [];
    return mcp_oauth_issue_tokens(
        $clientIdHash,
        (int) $row['user_id'],
        $scopes,
        (string) $row['resource'],
    );
}

/**
 * Authenticate an access token, verifying audience.
 *
 * @return array{user: array<string, mixed>, scopes: list<string>, key_id: int, oauth_token_id: int}|null
 */
function mcp_oauth_authenticate_access_token(string $token, string $expectedResource): ?array
{
    if (!mcp_oauth_tokens_table_ready()) {
        return null;
    }
    $token = trim($token);
    if (!mcp_oauth_looks_like_access_token($token)) {
        return null;
    }
    $hash = mcp_oauth_hash($token);

    $row = fetch_one(
        'SELECT t.*, u.id AS uid, u.name AS uname, u.login, u.email, u.role, u.church_id, u.group_id,
                u.two_factor_enabled, u.two_factor_secret
         FROM mcp_oauth_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.access_token_hash = ?
           AND t.revoked_at IS NULL
           AND t.access_expires_at > NOW()
         LIMIT 1',
        [$hash]
    );
    if (!$row) {
        return null;
    }

    // Audience check (RFC 8707)
    if (!mcp_url_equals((string) $row['resource'], $expectedResource)) {
        return null;
    }

    $scopes = json_decode((string) $row['scopes'], true);
    $scopes = is_array($scopes) ? mcp_normalize_api_key_scopes($scopes) : [];
    if ($scopes === []) {
        return null;
    }

    // Touch last_used_at sparsely
    $lastUsed = $row['last_used_at'] !== null ? strtotime((string) $row['last_used_at']) : 0;
    if ($lastUsed === false || $lastUsed < time() - 60) {
        db()->prepare('UPDATE mcp_oauth_tokens SET last_used_at = NOW() WHERE id = ?')
            ->execute([(int) $row['id']]);
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
        'oauth_token_id' => (int) $row['id'],
    ];
}

/**
 * Revoke an access or refresh token.
 */
function mcp_oauth_revoke_token(string $token): void
{
    if (!mcp_oauth_tokens_table_ready()) {
        return;
    }
    $hash = mcp_oauth_hash(trim($token));
    if (mcp_oauth_looks_like_access_token($token)) {
        db()->prepare('UPDATE mcp_oauth_tokens SET revoked_at = NOW() WHERE access_token_hash = ? AND revoked_at IS NULL')
            ->execute([$hash]);
    } elseif (mcp_oauth_looks_like_refresh_token($token)) {
        db()->prepare('UPDATE mcp_oauth_tokens SET revoked_at = NOW() WHERE refresh_token_hash = ? AND revoked_at IS NULL')
            ->execute([$hash]);
    }
}

/**
 * Purge expired codes and tokens (optional maintenance).
 */
function mcp_oauth_purge_expired(): void
{
    if (!mcp_oauth_clients_table_ready()) {
        return;
    }
    try {
        db()->exec("DELETE FROM mcp_oauth_codes WHERE code_expires_at < NOW() - INTERVAL 1 DAY");
        db()->exec("DELETE FROM mcp_oauth_tokens WHERE access_expires_at < NOW() - INTERVAL 7 DAY AND revoked_at IS NOT NULL");
    } catch (Throwable) {
        // Non-fatal
    }
}
