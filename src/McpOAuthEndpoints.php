<?php

declare(strict_types=1);

/**
 * MCP OAuth 2.1 HTTP endpoint handler.
 *
 * Called from public/index.php BEFORE require_login().
 * Routes:
 *   /.well-known/oauth-protected-resource  (+ /mcp variant + trailing slash)
 *   /.well-known/oauth-authorization-server
 *   /.well-known/openid-configuration
 *   /mcp/oauth/authorize  GET+POST
 *   /mcp/oauth/token      POST
 *   /mcp/oauth/register   POST
 *   /mcp/oauth/revoke     POST
 *   /mcp/oauth/userinfo   GET+POST
 */

/**
 * Discovery paths Cursor/ChatGPT may probe (RFC 8414 path insertion + resource-as-issuer).
 *
 * @return list<string>
 */
function mcp_oauth_discovery_paths(): array
{
    return [
        '/.well-known/oauth-protected-resource',
        '/.well-known/oauth-protected-resource/mcp',
        '/.well-known/oauth-authorization-server',
        '/.well-known/oauth-authorization-server/mcp',
        '/.well-known/openid-configuration',
        '/.well-known/openid-configuration/mcp',
        '/mcp/.well-known/oauth-authorization-server',
        '/mcp/.well-known/openid-configuration',
        '/mcp/.well-known/oauth-protected-resource',
    ];
}

/**
 * @return list<string>
 */
function mcp_oauth_protocol_paths(): array
{
    return [
        '/mcp/oauth/authorize',
        '/mcp/oauth/token',
        '/mcp/oauth/register',
        '/mcp/oauth/revoke',
        '/mcp/oauth/userinfo',
    ];
}

/**
 * Main entry point.
 * Returns true if the request was handled (caller should exit).
 */
function mcp_oauth_handle_request(string $path, string $method): bool
{
    if (!mcp_oauth_is_enabled()) {
        // Block discovery and protocol URLs from falling through to HTML login pages.
        $normalPath = $path === '/' ? '/' : rtrim($path, '/');
        if (mcp_oauth_is_discovery_like_path($normalPath)
            || in_array($normalPath, mcp_oauth_protocol_paths(), true)) {
            mcp_oauth_json_not_found('OAuth is disabled on this server.');
            return true;
        }

        return false;
    }

    // Normalize path: strip trailing slash for routing (but keep root /)
    $normalPath = $path === '/' ? '/' : rtrim($path, '/');
    $method = strtoupper($method);

    // OPTIONS → 204 CORS, no auth
    if ($method === 'OPTIONS') {
        if (mcp_oauth_is_oauth_path($normalPath) || mcp_oauth_is_discovery_like_path($normalPath)) {
            mcp_oauth_cors_headers();
            http_response_code(204);
            return true;
        }
        return false;
    }

    // Protected resource metadata (RFC 9728)
    if (in_array($normalPath, [
        '/.well-known/oauth-protected-resource',
        '/.well-known/oauth-protected-resource/mcp',
        '/mcp/.well-known/oauth-protected-resource',
    ], true)) {
        mcp_oauth_handle_prm();
        return true;
    }

    // Authorization server / OIDC discovery (root + path-aware + resource-mounted)
    if (in_array($normalPath, [
        '/.well-known/oauth-authorization-server',
        '/.well-known/oauth-authorization-server/mcp',
        '/mcp/.well-known/oauth-authorization-server',
    ], true)) {
        mcp_oauth_handle_as_metadata();
        return true;
    }

    if (in_array($normalPath, [
        '/.well-known/openid-configuration',
        '/.well-known/openid-configuration/mcp',
        '/mcp/.well-known/openid-configuration',
    ], true)) {
        mcp_oauth_handle_oidc_configuration();
        return true;
    }

    // OAuth endpoints (POST must not 301 — URLs advertised with trailing slash, match both)
    if ($normalPath === '/mcp/oauth/authorize') {
        mcp_oauth_handle_authorize($method);
        return true;
    }

    if ($normalPath === '/mcp/oauth/token') {
        mcp_oauth_handle_token($method);
        return true;
    }

    if ($normalPath === '/mcp/oauth/register') {
        mcp_oauth_handle_register($method);
        return true;
    }

    if ($normalPath === '/mcp/oauth/revoke') {
        mcp_oauth_handle_revoke($method);
        return true;
    }

    if ($normalPath === '/mcp/oauth/userinfo') {
        mcp_oauth_handle_userinfo($method);
        return true;
    }

    // Never fall through to CRM HTML login for unknown OAuth discovery URLs —
    // Cursor parses the body as JSON and fails with: Unexpected token '<', "<!doctype "...
    if (mcp_oauth_is_discovery_like_path($normalPath)) {
        mcp_oauth_json_not_found('Unknown OAuth discovery endpoint.');
        return true;
    }

    return false;
}

function mcp_oauth_is_oauth_path(string $normalPath): bool
{
    return in_array($normalPath, array_merge(mcp_oauth_discovery_paths(), mcp_oauth_protocol_paths()), true);
}

function mcp_oauth_is_discovery_like_path(string $normalPath): bool
{
    if (str_starts_with($normalPath, '/.well-known/oauth-')
        || str_starts_with($normalPath, '/.well-known/openid-configuration')) {
        return true;
    }

    return str_contains($normalPath, '/.well-known/oauth-')
        || str_contains($normalPath, '/.well-known/openid-configuration');
}

function mcp_oauth_json_not_found(string $description): void
{
    mcp_oauth_cors_headers();
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(mcp_oauth_error_body('not_found', $description), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ---------------------------------------------------------------------------
// Discovery documents
// ---------------------------------------------------------------------------

function mcp_oauth_handle_prm(): void
{
    mcp_oauth_cors_headers();
    header('Content-Type: application/json; charset=utf-8');
    $scopes = mcp_api_key_scopes();
    echo json_encode(mcp_oauth_prm_metadata($scopes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function mcp_oauth_handle_as_metadata(): void
{
    mcp_oauth_cors_headers();
    header('Content-Type: application/json; charset=utf-8');
    $scopes = mcp_api_key_scopes();
    echo json_encode(mcp_oauth_as_metadata($scopes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function mcp_oauth_handle_oidc_configuration(): void
{
    mcp_oauth_cors_headers();
    header('Content-Type: application/json; charset=utf-8');
    $scopes = mcp_api_key_scopes();
    $as = mcp_oauth_as_metadata($scopes);
    echo json_encode(mcp_oauth_oidc_metadata($as), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

// ---------------------------------------------------------------------------
// /mcp/oauth/authorize
// ---------------------------------------------------------------------------

function mcp_oauth_handle_authorize(string $method): void
{
    mcp_oauth_cors_headers();

    if ($method !== 'GET' && $method !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST, OPTIONS');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $params = array_map('strval', $method === 'GET' ? $_GET : $_POST);
    if ($method === 'POST' && empty($_POST)) {
        // POST might carry query params from GET redirect
        $params = array_map('strval', $_GET);
    }

    $redirectUri = trim($params['redirect_uri'] ?? '');
    $clientId = trim($params['client_id'] ?? '');
    $state = (string) ($params['state'] ?? '');

    // Validate request before showing any redirect
    $validation = mcp_oauth_validate_authorize_request($params, mcp_oauth_resource_url());
    if (!$validation['ok']) {
        // If we can safely redirect, do so; otherwise show error page
        if ($redirectUri !== '' && mcp_oauth_redirect_uri_is_allowed($redirectUri)) {
            mcp_oauth_redirect($redirectUri, [
                'error' => $validation['error'],
                'error_description' => $validation['error_description'],
                'state' => $state,
            ]);
        }
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(mcp_oauth_error_body($validation['error'], $validation['error_description']), JSON_UNESCAPED_UNICODE);
        return;
    }

    // Resolve client
    $client = mcp_oauth_resolve_client($clientId);
    if ($client === null) {
        mcp_oauth_redirect($redirectUri, [
            'error' => 'invalid_client',
            'error_description' => 'Unknown client_id.',
            'state' => $state,
        ]);
    }

    // Verify redirect_uri is registered
    if (!mcp_oauth_redirect_uri_matches_registered($redirectUri, (array) $client['redirect_uris'])) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(mcp_oauth_error_body('invalid_request', 'redirect_uri not registered for this client.'), JSON_UNESCAPED_UNICODE);
        return;
    }

    // Require login
    $user = current_user();
    if (!$user) {
        // Store authorize URL in session, redirect to login
        $returnUrl = '/mcp/oauth/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $_SESSION['mcp_oauth_return'] = $returnUrl;
        $slug = legacy_default_slug();
        header('Location: /' . $slug . '/login');
        exit;
    }

    $allowedScopes = mcp_scopes_allowed_for_role((string) $user['role']);
    $requestedScopes = mcp_oauth_parse_scopes((string) ($params['scope'] ?? ''));
    $grantableScopes = $requestedScopes !== []
        ? array_values(array_intersect($requestedScopes, $allowedScopes))
        : $allowedScopes;

    if ($method === 'POST' && isset($_POST['consent'])) {
        // Consent form submitted
        if (!csrf_is_valid()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'CSRF validation failed';
            return;
        }

        if ($_POST['consent'] === 'deny') {
            mcp_oauth_redirect($redirectUri, [
                'error' => 'access_denied',
                'error_description' => 'User denied access.',
                'state' => $state,
            ]);
        }

        // Scopes approved (use intersection of what user checked vs allowed)
        $approvedScopes = isset($_POST['scopes']) && is_array($_POST['scopes'])
            ? array_values(array_intersect((array) $_POST['scopes'], $grantableScopes))
            : $grantableScopes;

        if ($approvedScopes === []) {
            $approvedScopes = ['read'];
        }

        $code = mcp_oauth_issue_code(
            mcp_oauth_hash($clientId),
            (int) $user['id'],
            $redirectUri,
            (string) ($params['code_challenge'] ?? ''),
            (string) ($params['code_challenge_method'] ?? 'S256'),
            $approvedScopes,
            mcp_oauth_resource_url(),
        );

        mcp_oauth_redirect($redirectUri, array_filter([
            'code' => $code,
            'state' => $state !== '' ? $state : null,
            'iss' => mcp_url_canonicalize(mcp_oauth_issuer_url()),
        ], fn ($v) => $v !== null));
    }

    // Show consent page (GET or POST without consent field)
    mcp_oauth_render_consent_page($client, $user, $params, $grantableScopes);
}

/**
 * @param array<string, mixed> $client
 * @param array<string, mixed> $user
 * @param array<string, string> $params
 * @param list<string> $grantableScopes
 */
function mcp_oauth_render_consent_page(array $client, array $user, array $params, array $grantableScopes): void
{
    $labels = mcp_api_key_scope_labels();
    $clientName = e((string) ($client['client_name'] ?? 'MCP Client'));
    $userName = e((string) ($user['name'] ?? $user['login'] ?? ''));
    $mcpUrl = e(mcp_url_canonicalize(mcp_oauth_resource_url()));

    // Preserve authorize params in hidden fields
    $hiddenParams = '';
    $preserveKeys = ['response_type', 'client_id', 'redirect_uri', 'state', 'scope',
                     'code_challenge', 'code_challenge_method', 'resource'];
    foreach ($preserveKeys as $key) {
        if (isset($params[$key]) && $params[$key] !== '') {
            $hiddenParams .= '<input type="hidden" name="' . e($key) . '" value="' . e($params[$key]) . '">';
        }
    }

    $csrfField = csrf_field();
    $scopeCheckboxes = '';
    foreach ($grantableScopes as $scope) {
        $label = e($labels[$scope] ?? $scope);
        $scopeCheckboxes .= '<label class="checkbox-row"><input type="checkbox" name="scopes[]" value="' . e($scope) . '" checked> ' . $label . '</label>';
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Разрешить доступ — wolhg</title>'
        . '<link rel="stylesheet" href="/assets/app.css"></head><body>'
        . '<section class="login-card stack" style="max-width:480px;margin:2rem auto">'
        . '<h1 style="font-size:1.2rem">Запрос доступа</h1>'
        . '<p>Приложение <strong>' . $clientName . '</strong> запрашивает доступ к вашему аккаунту <strong>' . $userName . '</strong>.</p>'
        . '<p class="muted" style="font-size:.85rem">MCP-сервер: <code>' . $mcpUrl . '</code></p>'
        . '<form method="post" class="stack">'
        . $csrfField
        . $hiddenParams
        . '<fieldset class="stack"><legend>Разрешить доступ к:</legend>'
        . $scopeCheckboxes
        . '</fieldset>'
        . '<div style="display:flex;gap:.5rem">'
        . '<button class="primary" name="consent" value="allow" type="submit">Разрешить</button>'
        . '<button class="ghost" name="consent" value="deny" type="submit">Отклонить</button>'
        . '</div>'
        . '</form>'
        . '</section></body></html>';
}

// ---------------------------------------------------------------------------
// /mcp/oauth/token
// ---------------------------------------------------------------------------

function mcp_oauth_handle_token(string $method): void
{
    mcp_oauth_cors_headers();
    header('Content-Type: application/json; charset=utf-8');

    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: POST, OPTIONS');
        echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $params = mcp_oauth_read_form_or_json();

    $grantType = (string) ($params['grant_type'] ?? '');

    // Resolve client credentials (Basic Auth or POST body)
    [$clientId, $clientSecret] = mcp_oauth_extract_client_credentials($params);

    if ($clientId === '') {
        http_response_code(401);
        echo json_encode(mcp_oauth_error_body('invalid_client', 'client_id is required.'), JSON_UNESCAPED_UNICODE);
        return;
    }

    $client = mcp_oauth_resolve_client($clientId);
    if ($client === null) {
        http_response_code(401);
        echo json_encode(mcp_oauth_error_body('invalid_client', 'Unknown client.'), JSON_UNESCAPED_UNICODE);
        return;
    }

    // Verify secret if required
    if ($client['token_endpoint_auth_method'] !== 'none') {
        if ($clientSecret === '' || !mcp_oauth_verify_client_secret($client, $clientSecret)) {
            http_response_code(401);
            echo json_encode(mcp_oauth_error_body('invalid_client', 'Invalid client credentials.'), JSON_UNESCAPED_UNICODE);
            return;
        }
    }

    if ($grantType === 'authorization_code') {
        mcp_oauth_handle_token_auth_code($client, $params);
    } elseif ($grantType === 'refresh_token') {
        mcp_oauth_handle_token_refresh($client, $params);
    } else {
        http_response_code(400);
        echo json_encode(mcp_oauth_error_body('unsupported_grant_type', 'Unsupported grant_type.'), JSON_UNESCAPED_UNICODE);
    }
}

/**
 * @param array<string, mixed> $client
 * @param array<string, string> $params
 */
function mcp_oauth_handle_token_auth_code(array $client, array $params): void
{
    $validation = mcp_oauth_validate_token_request($params, mcp_oauth_resource_url());
    if (!$validation['ok']) {
        http_response_code(400);
        echo json_encode(mcp_oauth_error_body($validation['error'], $validation['error_description']), JSON_UNESCAPED_UNICODE);
        return;
    }

    $code = mcp_oauth_consume_code((string) $params['code']);
    if ($code === null) {
        http_response_code(400);
        echo json_encode(mcp_oauth_error_body('invalid_grant', 'Authorization code is invalid or expired.'), JSON_UNESCAPED_UNICODE);
        return;
    }

    // Verify client matches code
    if (!hash_equals((string) $code['client_id_hash'], mcp_oauth_hash((string) $client['client_id']))) {
        http_response_code(400);
        echo json_encode(mcp_oauth_error_body('invalid_grant', 'Code was not issued to this client.'), JSON_UNESCAPED_UNICODE);
        return;
    }

    // Verify redirect_uri
    if (!hash_equals((string) $code['redirect_uri'], trim((string) ($params['redirect_uri'] ?? '')))) {
        http_response_code(400);
        echo json_encode(mcp_oauth_error_body('invalid_grant', 'redirect_uri mismatch.'), JSON_UNESCAPED_UNICODE);
        return;
    }

    // Verify PKCE
    $verifier = (string) ($params['code_verifier'] ?? '');
    if (!mcp_pkce_verify($verifier, (string) $code['code_challenge'], (string) $code['code_challenge_method'])) {
        http_response_code(400);
        echo json_encode(mcp_oauth_error_body('invalid_grant', 'PKCE verification failed.'), JSON_UNESCAPED_UNICODE);
        return;
    }

    $scopes = json_decode((string) $code['scopes'], true) ?: [];
    $tokens = mcp_oauth_issue_tokens(
        (string) $code['client_id_hash'],
        (int) $code['user_id'],
        $scopes,
        (string) $code['resource'],
    );

    http_response_code(200);
    header('Cache-Control: no-store');
    echo json_encode($tokens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * @param array<string, mixed> $client
 * @param array<string, string> $params
 */
function mcp_oauth_handle_token_refresh(array $client, array $params): void
{
    $refreshToken = trim((string) ($params['refresh_token'] ?? ''));
    if ($refreshToken === '') {
        http_response_code(400);
        echo json_encode(mcp_oauth_error_body('invalid_request', 'refresh_token is required.'), JSON_UNESCAPED_UNICODE);
        return;
    }

    $clientIdHash = mcp_oauth_hash((string) $client['client_id']);
    $tokens = mcp_oauth_rotate_refresh_token($refreshToken, $clientIdHash);
    if ($tokens === null) {
        http_response_code(400);
        echo json_encode(mcp_oauth_error_body('invalid_grant', 'Refresh token is invalid, expired or revoked.'), JSON_UNESCAPED_UNICODE);
        return;
    }

    http_response_code(200);
    header('Cache-Control: no-store');
    echo json_encode($tokens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * @return array{0: string, 1: string} [client_id, client_secret]
 * @param array<string, string> $params
 */
function mcp_oauth_extract_client_credentials(array $params): array
{
    // Basic Auth
    $authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Basic\s+(.+)$/i', $authHeader, $m)) {
        $decoded = base64_decode($m[1], true);
        if ($decoded !== false && str_contains($decoded, ':')) {
            [$id, $secret] = explode(':', $decoded, 2);
            return [urldecode($id), urldecode($secret)];
        }
    }
    // POST body
    return [
        trim((string) ($params['client_id'] ?? '')),
        trim((string) ($params['client_secret'] ?? '')),
    ];
}

// ---------------------------------------------------------------------------
// /mcp/oauth/register (DCR — RFC 7591)
// ---------------------------------------------------------------------------

function mcp_oauth_handle_register(string $method): void
{
    mcp_oauth_cors_headers();
    header('Content-Type: application/json; charset=utf-8');

    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: POST, OPTIONS');
        echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(mcp_oauth_error_body('invalid_request', 'Request body must be JSON.'), JSON_UNESCAPED_UNICODE);
        return;
    }

    // redirect_uris is required
    if (empty($body['redirect_uris']) || !is_array($body['redirect_uris'])) {
        http_response_code(400);
        echo json_encode(mcp_oauth_error_body('invalid_redirect_uri', 'redirect_uris is required.'), JSON_UNESCAPED_UNICODE);
        return;
    }

    try {
        $result = mcp_oauth_register_dcr([
            'client_name' => (string) ($body['client_name'] ?? 'MCP Client'),
            'redirect_uris' => array_map('strval', (array) $body['redirect_uris']),
            'grant_types' => isset($body['grant_types']) ? array_map('strval', (array) $body['grant_types']) : ['authorization_code', 'refresh_token'],
            'response_types' => isset($body['response_types']) ? array_map('strval', (array) $body['response_types']) : ['code'],
            'token_endpoint_auth_method' => (string) ($body['token_endpoint_auth_method'] ?? 'none'),
        ]);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(mcp_oauth_error_body('invalid_client_metadata', $e->getMessage()), JSON_UNESCAPED_UNICODE);
        return;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(mcp_oauth_error_body('server_error', 'Registration failed.'), JSON_UNESCAPED_UNICODE);
        return;
    }

    $response = [
        'client_id' => $result['client_id'],
        'client_name' => $result['client_name'],
        'redirect_uris' => $result['redirect_uris'],
        'grant_types' => $result['grant_types'] ?? ['authorization_code', 'refresh_token'],
        'response_types' => $result['response_types'] ?? ['code'],
        'token_endpoint_auth_method' => $result['token_endpoint_auth_method'] ?? 'none',
        'client_id_issued_at' => time(),
    ];
    if (!empty($result['client_secret'])) {
        $response['client_secret'] = $result['client_secret'];
    }

    http_response_code(201);
    header('Cache-Control: no-store');
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Read OAuth token/revoke body as form-urlencoded or JSON (ChatGPT may send either).
 *
 * @return array<string, string>
 */
function mcp_oauth_read_form_or_json(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $out[(string) $k] = (string) $v;
            }
        }

        return $out;
    }

    $out = [];
    foreach ($_POST as $k => $v) {
        if (is_string($v)) {
            $out[(string) $k] = $v;
        }
    }
    if ($out !== []) {
        return $out;
    }

    // Some CGI setups leave php://input readable for form bodies without populating $_POST.
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    parse_str($raw, $parsed);
    if (!is_array($parsed)) {
        return [];
    }
    $out = [];
    foreach ($parsed as $k => $v) {
        if (is_string($v)) {
            $out[(string) $k] = $v;
        }
    }

    return $out;
}

// ---------------------------------------------------------------------------
// /mcp/oauth/revoke (RFC 7009)
// ---------------------------------------------------------------------------

function mcp_oauth_handle_revoke(string $method): void
{
    mcp_oauth_cors_headers();
    header('Content-Type: application/json; charset=utf-8');

    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: POST, OPTIONS');
        echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $params = mcp_oauth_read_form_or_json();
    $token = trim((string) ($params['token'] ?? ''));

    if ($token !== '') {
        mcp_oauth_revoke_token($token);
    }

    // RFC 7009: always 200 (even if token unknown)
    http_response_code(200);
    echo json_encode(new stdClass(), JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
// /mcp/oauth/userinfo (OIDC subset)
// ---------------------------------------------------------------------------

function mcp_oauth_handle_userinfo(string $method): void
{
    mcp_oauth_cors_headers();
    header('Content-Type: application/json; charset=utf-8');

    if ($method !== 'GET' && $method !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST, OPTIONS');
        echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $token = mcp_bearer_token_from_request();
    if ($token === '') {
        http_response_code(401);
        header('WWW-Authenticate: ' . mcp_oauth_www_authenticate(mcp_oauth_protected_resource_metadata_url()));
        echo json_encode(mcp_oauth_error_body('invalid_token', 'Bearer token required.'), JSON_UNESCAPED_UNICODE);
        return;
    }

    $principal = mcp_oauth_authenticate_access_token($token, mcp_oauth_resource_url());
    if ($principal === null) {
        // Try API key too
        $principal = mcp_resolve_bearer_token($token);
        if ($principal === null) {
            http_response_code(401);
            header('WWW-Authenticate: ' . mcp_oauth_www_authenticate(mcp_oauth_protected_resource_metadata_url(), [], 'invalid_token', 'Token invalid or expired.'));
            echo json_encode(mcp_oauth_error_body('invalid_token', 'Token invalid or expired.'), JSON_UNESCAPED_UNICODE);
            return;
        }
    }

    $user = $principal['user'];
    $info = [
        'sub' => (string) $user['id'],
        'name' => (string) ($user['name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'login' => (string) ($user['login'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
    ];

    http_response_code(200);
    echo json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Note: csrf_is_valid() is provided by src/bootstrap.php (checks $_POST['_csrf'] vs $_SESSION['csrf']).
