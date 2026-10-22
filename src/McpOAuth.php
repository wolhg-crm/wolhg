<?php

declare(strict_types=1);

/**
 * MCP OAuth 2.1 pure helpers.
 *
 * Ported from ferma-business-mcp OAuth/* to procedural PHP matching wolhg style.
 * Implements RFC 9728 (PRM), RFC 8414 (AS metadata), auth code + PKCE S256,
 * RFC 8707 (resource indicator), DCR (RFC 7591), refresh rotation, revoke, userinfo.
 */

// ---------------------------------------------------------------------------
// Feature flags
// ---------------------------------------------------------------------------

function mcp_oauth_is_enabled(): bool
{
    static $result = null;
    if ($result !== null) {
        return $result;
    }
    if (!mcp_is_enabled()) {
        return $result = false;
    }
    $v = app_config('mcp.oauth.enabled');
    return $result = ($v === null ? true : (bool) $v);
}

function mcp_oauth_advertise_cimd(): bool
{
    $v = app_config('mcp.oauth.advertise_cimd');
    return $v !== null && (bool) $v;
}

function mcp_oauth_access_ttl(): int
{
    return (int) (app_config('mcp.oauth.access_ttl') ?: 3600);
}

function mcp_oauth_refresh_ttl(): int
{
    return (int) (app_config('mcp.oauth.refresh_ttl') ?: 2592000);
}

// ---------------------------------------------------------------------------
// URL helpers
// ---------------------------------------------------------------------------

function mcp_oauth_origin_url(): string
{
    $url = rtrim((string) (app_config('url') ?: ''), '/');
    return $url;
}

function mcp_oauth_issuer_url(): string
{
    return mcp_oauth_origin_url();
}

/**
 * RFC 8707 resource indicator — NO trailing slash.
 */
function mcp_oauth_resource_url(): string
{
    return mcp_url_canonicalize(mcp_oauth_origin_url() . '/mcp');
}

/**
 * Path-aware PRM URL — WITH trailing slash (Apache slash gotcha: ChatGPT fetches
 * the exact resource_metadata URL; a 301 HTML response breaks discovery).
 */
function mcp_oauth_protected_resource_metadata_url(): string
{
    return mcp_http_endpoint(mcp_oauth_origin_url() . '/.well-known/oauth-protected-resource/mcp');
}

function mcp_oauth_authorize_url(): string
{
    return mcp_http_endpoint(mcp_oauth_origin_url() . '/mcp/oauth/authorize');
}

function mcp_oauth_token_url(): string
{
    return mcp_http_endpoint(mcp_oauth_origin_url() . '/mcp/oauth/token');
}

function mcp_oauth_register_url(): string
{
    return mcp_http_endpoint(mcp_oauth_origin_url() . '/mcp/oauth/register');
}

function mcp_oauth_revoke_url(): string
{
    return mcp_http_endpoint(mcp_oauth_origin_url() . '/mcp/oauth/revoke');
}

function mcp_oauth_userinfo_url(): string
{
    return mcp_http_endpoint(mcp_oauth_origin_url() . '/mcp/oauth/userinfo');
}

// ---------------------------------------------------------------------------
// URL canonicalization (RFC 8707 audience check)
// ---------------------------------------------------------------------------

/**
 * Canonicalize https/http URI: lowercase scheme+host, drop default port,
 * strip trailing slash from path (unless root), strip fragment.
 */
function mcp_url_canonicalize(string $uri): string
{
    $uri = trim($uri);
    if ($uri === '') {
        return '';
    }
    $parts = parse_url($uri);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'https' && $scheme !== 'http') {
        return '';
    }
    $host = strtolower((string) $parts['host']);
    $port = isset($parts['port']) ? (int) $parts['port'] : null;
    $defaultPort = $scheme === 'https' ? 443 : 80;
    $authority = $host;
    if ($port !== null && $port !== $defaultPort) {
        $authority .= ':' . $port;
    }
    $path = (string) ($parts['path'] ?? '');
    if ($path === '/' || $path === '') {
        $path = '';
    } else {
        $path = rtrim($path, '/');
    }
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    return $scheme . '://' . $authority . $path . $query;
}

function mcp_url_equals(string $a, string $b): bool
{
    $ca = mcp_url_canonicalize($a);
    $cb = mcp_url_canonicalize($b);
    return $ca !== '' && $ca === $cb;
}

/**
 * Add trailing slash to HTTP endpoint URL (POST+301 drops the body → broken DCR/token).
 */
function mcp_http_endpoint(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return rtrim($url, '/') . '/';
    }
    $path = (string) ($parts['path'] ?? '');
    if ($path === '' || $path === '/') {
        $path = '/';
    } else {
        $path = rtrim($path, '/') . '/';
    }
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    return strtolower((string) $parts['scheme']) . '://'
        . strtolower((string) $parts['host'])
        . $port
        . $path
        . $query;
}

// ---------------------------------------------------------------------------
// PKCE (S256 only per MCP spec)
// ---------------------------------------------------------------------------

function mcp_pkce_challenge_s256(string $verifier): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
}

function mcp_pkce_verify(string $verifier, string $challenge, string $method): bool
{
    if ($verifier === '' || $challenge === '') {
        return false;
    }
    if (strtoupper($method) === 'S256') {
        return hash_equals(mcp_pkce_challenge_s256($verifier), $challenge);
    }
    return false;
}

function mcp_pkce_is_valid_verifier(string $verifier): bool
{
    $len = strlen($verifier);
    return $len >= 43 && $len <= 128 && preg_match('/^[A-Za-z0-9\-._~]+$/', $verifier) === 1;
}

function mcp_pkce_is_supported_method(string $method): bool
{
    return strtoupper($method) === 'S256';
}

// ---------------------------------------------------------------------------
// Redirect URI validation (RFC 8252 / OAuth 2.1)
// ---------------------------------------------------------------------------

/** @var list<string> */
const MCP_OAUTH_BLOCKED_SCHEMES = ['javascript', 'data', 'vbscript', 'file', 'about', 'blob', 'ws', 'wss', 'ftp', 'ftps'];

function mcp_oauth_redirect_uri_is_allowed(string $uri): bool
{
    $uri = trim($uri);
    if ($uri === '' || str_contains($uri, '#') || str_contains($uri, "\0")) {
        return false;
    }
    $parts = parse_url($uri);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower((string) $parts['host']);

    if ($scheme === 'https') {
        return mcp_oauth_is_plausible_host($host);
    }
    if ($scheme === 'http') {
        return mcp_oauth_is_loopback_host($host);
    }
    // Private-use / native-app scheme (e.g. cursor://)
    return mcp_oauth_is_allowed_native_app_uri($scheme, $host);
}

function mcp_oauth_redirect_uri_matches_registered(string $requested, array $registered): bool
{
    $requested = trim($requested);
    foreach ($registered as $uri) {
        if (hash_equals(trim((string) $uri), $requested)) {
            return true;
        }
    }
    return false;
}

function mcp_oauth_is_loopback_host(string $host): bool
{
    return in_array($host, ['127.0.0.1', 'localhost', '[::1]', '::1'], true);
}

function mcp_oauth_is_plausible_host(string $host): bool
{
    if ($host === 'localhost' || mcp_oauth_is_loopback_host($host)) {
        return true;
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return true;
    }
    $ipv6 = $host;
    if (str_starts_with($ipv6, '[') && str_ends_with($ipv6, ']')) {
        $ipv6 = substr($ipv6, 1, -1);
    }
    if (filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        return true;
    }
    return (bool) preg_match(
        '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/i',
        $host
    );
}

function mcp_oauth_is_allowed_native_app_uri(string $scheme, string $host): bool
{
    if (in_array($scheme, MCP_OAUTH_BLOCKED_SCHEMES, true)) {
        return false;
    }
    if (!preg_match('/^[a-z][a-z0-9+.-]{0,63}$/', $scheme)) {
        return false;
    }
    return mcp_oauth_is_plausible_host($host);
}

/**
 * Perform redirect to the OAuth callback URI (supports cursor:// and other native schemes).
 *
 * @param array<string, scalar|null> $query
 * @return never
 */
function mcp_oauth_redirect(string $uri, array $query = []): never
{
    $uri = trim($uri);
    if (!mcp_oauth_redirect_uri_is_allowed($uri)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'invalid_redirect_uri';
        exit;
    }
    if ($query !== []) {
        $sep = str_contains($uri, '?') ? '&' : '?';
        $uri .= $sep . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    if (!headers_sent()) {
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('Location: ' . $uri, true, 302);
    }
    exit;
}

/**
 * Is this URL safe to use as mcp_oauth_return redirect after login?
 */
function mcp_oauth_is_safe_return_url(string $url): bool
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    // Must be an absolute path starting with /mcp/oauth/authorize
    if (str_starts_with($url, '/mcp/oauth/authorize')) {
        return true;
    }
    // Or absolute URL on same origin
    $origin = mcp_oauth_origin_url();
    if ($origin !== '' && str_starts_with($url, $origin . '/mcp/oauth/authorize')) {
        return true;
    }
    return false;
}

// ---------------------------------------------------------------------------
// Token ID generators / hash / prefix detection
// ---------------------------------------------------------------------------

const MCP_OAUTH_AT_PREFIX = 'wolhg_at_';
const MCP_OAUTH_RT_PREFIX = 'wolhg_rt_';
const MCP_OAUTH_AC_PREFIX = 'wolhg_ac_';
const MCP_OAUTH_OC_PREFIX = 'wolhg_oc_';

function mcp_oauth_generate_access_token(): string
{
    return MCP_OAUTH_AT_PREFIX . bin2hex(random_bytes(32));
}

function mcp_oauth_generate_refresh_token(): string
{
    return MCP_OAUTH_RT_PREFIX . bin2hex(random_bytes(32));
}

function mcp_oauth_generate_auth_code(): string
{
    return MCP_OAUTH_AC_PREFIX . bin2hex(random_bytes(24));
}

function mcp_oauth_generate_client_id(): string
{
    return MCP_OAUTH_OC_PREFIX . bin2hex(random_bytes(16));
}

function mcp_oauth_generate_client_secret(): string
{
    return 'wolhg_cs_' . bin2hex(random_bytes(24));
}

function mcp_oauth_hash(string $plaintext): string
{
    return hash('sha256', $plaintext);
}

function mcp_oauth_looks_like_access_token(string $token): bool
{
    return str_starts_with($token, MCP_OAUTH_AT_PREFIX);
}

function mcp_oauth_looks_like_refresh_token(string $token): bool
{
    return str_starts_with($token, MCP_OAUTH_RT_PREFIX);
}

// ---------------------------------------------------------------------------
// Auth error helpers
// ---------------------------------------------------------------------------

/**
 * @return array{error: string, error_description: string}
 */
function mcp_oauth_error_body(string $error, string $description): array
{
    return ['error' => $error, 'error_description' => $description];
}

/**
 * @param list<string> $scopes
 */
function mcp_oauth_www_authenticate(
    string $resourceMetadataUrl,
    array $scopes = [],
    ?string $error = null,
    ?string $errorDescription = null,
): string {
    $parts = [];
    $parts[] = 'resource_metadata="' . mcp_oauth_escape_param($resourceMetadataUrl) . '"';
    if ($scopes !== []) {
        $parts[] = 'scope="' . mcp_oauth_escape_param(implode(' ', $scopes)) . '"';
    }
    if ($error !== null && $error !== '') {
        $parts[] = 'error="' . mcp_oauth_escape_param($error) . '"';
    }
    if ($errorDescription !== null && $errorDescription !== '') {
        $parts[] = 'error_description="' . mcp_oauth_escape_param($errorDescription) . '"';
    }
    return 'Bearer ' . implode(', ', $parts);
}

function mcp_oauth_escape_param(string $value): string
{
    return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
}

// ---------------------------------------------------------------------------
// Request validators
// ---------------------------------------------------------------------------

/**
 * @param array<string, string> $params
 * @return array{ok: true}|array{ok: false, error: string, error_description: string}
 */
function mcp_oauth_validate_authorize_request(array $params, string $expectedResource): array
{
    if (($params['response_type'] ?? '') !== 'code') {
        return mcp_oauth_fail('unsupported_response_type', 'Only response_type=code is supported.');
    }
    if (trim($params['client_id'] ?? '') === '') {
        return mcp_oauth_fail('invalid_request', 'client_id is required.');
    }
    $redirectUri = trim($params['redirect_uri'] ?? '');
    if ($redirectUri === '' || !mcp_oauth_redirect_uri_is_allowed($redirectUri)) {
        return mcp_oauth_fail('invalid_request', 'redirect_uri is missing or not allowed.');
    }
    $challenge = trim($params['code_challenge'] ?? '');
    $method = trim($params['code_challenge_method'] ?? '');
    if ($challenge === '' || !mcp_pkce_is_supported_method($method)) {
        return mcp_oauth_fail('invalid_request', 'PKCE S256 code_challenge is required.');
    }
    $resource = trim($params['resource'] ?? '');
    if ($resource === '') {
        return mcp_oauth_fail('invalid_target', 'resource parameter is required.');
    }
    if (!mcp_url_equals($resource, $expectedResource)) {
        return mcp_oauth_fail('invalid_target', 'resource does not match this MCP server.');
    }
    return ['ok' => true];
}

/**
 * @param array<string, string> $params
 * @return array{ok: true}|array{ok: false, error: string, error_description: string}
 */
function mcp_oauth_validate_token_request(array $params, string $expectedResource): array
{
    if (($params['grant_type'] ?? '') !== 'authorization_code') {
        return mcp_oauth_fail('unsupported_grant_type', 'Unsupported grant_type.');
    }
    if (trim($params['code'] ?? '') === '') {
        return mcp_oauth_fail('invalid_request', 'code is required.');
    }
    if (trim($params['redirect_uri'] ?? '') === '') {
        return mcp_oauth_fail('invalid_request', 'redirect_uri is required.');
    }
    if (trim($params['client_id'] ?? '') === '') {
        return mcp_oauth_fail('invalid_request', 'client_id is required.');
    }
    $verifier = (string) ($params['code_verifier'] ?? '');
    if (!mcp_pkce_is_valid_verifier($verifier)) {
        return mcp_oauth_fail('invalid_request', 'code_verifier is missing or invalid.');
    }
    $resource = trim($params['resource'] ?? '');
    if ($resource !== '' && !mcp_url_equals($resource, $expectedResource)) {
        return mcp_oauth_fail('invalid_target', 'resource does not match this MCP server.');
    }
    return ['ok' => true];
}

/**
 * @return array{ok: false, error: string, error_description: string}
 */
function mcp_oauth_fail(string $error, string $description): array
{
    return ['ok' => false, 'error' => $error, 'error_description' => $description];
}

// ---------------------------------------------------------------------------
// Scope helpers
// ---------------------------------------------------------------------------

/**
 * Parse space-separated scope string into list.
 *
 * @return list<string>
 */
function mcp_oauth_parse_scopes(string $scope): array
{
    if (trim($scope) === '') {
        return [];
    }
    $parts = preg_split('/\s+/', trim($scope)) ?: [];
    return mcp_normalize_api_key_scopes(array_values(array_filter($parts, static fn ($p) => $p !== '')));
}

/**
 * @param list<string> $scopes
 */
function mcp_oauth_format_scopes(array $scopes): string
{
    return implode(' ', $scopes);
}

// ---------------------------------------------------------------------------
// Metadata builders
// ---------------------------------------------------------------------------

/**
 * RFC 9728 Protected Resource Metadata.
 *
 * @param list<string> $scopesSupported
 * @return array<string, mixed>
 */
function mcp_oauth_prm_metadata(array $scopesSupported): array
{
    return [
        'resource' => mcp_url_canonicalize(mcp_oauth_resource_url()),
        'authorization_servers' => [mcp_url_canonicalize(mcp_oauth_issuer_url())],
        'scopes_supported' => array_values($scopesSupported),
        'bearer_methods_supported' => ['header'],
        'resource_signing_alg_values_supported' => [],
        // docs/ is not published on shared hosting FTP; point at the live MCP resource instead.
        'resource_documentation' => mcp_url_canonicalize(mcp_oauth_resource_url()),
    ];
}

/**
 * RFC 8414 Authorization Server Metadata.
 *
 * @param list<string> $scopesSupported
 * @return array<string, mixed>
 */
function mcp_oauth_as_metadata(array $scopesSupported): array
{
    $doc = [
        'issuer' => mcp_url_canonicalize(mcp_oauth_issuer_url()),
        'authorization_endpoint' => mcp_oauth_authorize_url(),
        'token_endpoint' => mcp_oauth_token_url(),
        'registration_endpoint' => mcp_oauth_register_url(),
        'revocation_endpoint' => mcp_oauth_revoke_url(),
        'userinfo_endpoint' => mcp_oauth_userinfo_url(),
        'scopes_supported' => array_values($scopesSupported),
        'response_types_supported' => ['code'],
        'response_modes_supported' => ['query'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
        'code_challenge_methods_supported' => ['S256'],
        'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
        'authorization_response_iss_parameter_supported' => true,
        'service_documentation' => 'https://modelcontextprotocol.io',
    ];
    if (mcp_oauth_advertise_cimd()) {
        $doc['client_id_metadata_document_supported'] = true;
    }
    return $doc;
}

/**
 * OIDC Discovery subset for clients that probe OIDC first.
 *
 * @param array<string, mixed> $asMeta
 * @return array<string, mixed>
 */
function mcp_oauth_oidc_metadata(array $asMeta): array
{
    $doc = $asMeta;
    $doc['subject_types_supported'] = ['public'];
    $doc['id_token_signing_alg_values_supported'] = ['none'];
    $doc['code_challenge_methods_supported'] = ['S256'];
    return $doc;
}

// ---------------------------------------------------------------------------
// CIMD (Client ID Metadata Document) — RFC 9396 / MCP extension
// ---------------------------------------------------------------------------

function mcp_oauth_is_url_client_id(string $clientId): bool
{
    return str_starts_with(strtolower(trim($clientId)), 'https://');
}

/**
 * Parse a CIMD document fetched from a client_id URL.
 *
 * @param array<string, mixed> $document
 * @return array{client_id: string, client_name: string, redirect_uris: list<string>, token_endpoint_auth_method: string, grant_types: list<string>, response_types: list<string>}|null
 */
function mcp_oauth_parse_cimd(array $document, string $expectedClientIdUrl): ?array
{
    $clientId = isset($document['client_id']) ? trim((string) $document['client_id']) : '';
    if ($clientId === '' || !hash_equals($expectedClientIdUrl, $clientId)) {
        return null;
    }
    if (!str_starts_with(strtolower($clientId), 'https://')) {
        return null;
    }
    $path = (string) (parse_url($clientId, PHP_URL_PATH) ?? '');
    if ($path === '' || $path === '/') {
        return null;
    }

    $redirectUris = $document['redirect_uris'] ?? null;
    if (!is_array($redirectUris) || $redirectUris === []) {
        return null;
    }
    $normalizedRedirects = [];
    foreach ($redirectUris as $uri) {
        $uri = trim((string) $uri);
        if (!mcp_oauth_redirect_uri_is_allowed($uri)) {
            return null;
        }
        $normalizedRedirects[] = $uri;
    }

    $clientName = trim((string) ($document['client_name'] ?? 'MCP Client'));
    if ($clientName === '') {
        $clientName = 'MCP Client';
    }

    $authMethods = [];
    if (isset($document['token_endpoint_auth_methods_supported']) && is_array($document['token_endpoint_auth_methods_supported'])) {
        foreach ($document['token_endpoint_auth_methods_supported'] as $method) {
            $authMethods[] = (string) $method;
        }
    }
    $preferred = isset($document['token_endpoint_auth_method']) ? (string) $document['token_endpoint_auth_method'] : '';
    $selectedAuth = mcp_oauth_select_auth_method($authMethods, $preferred);

    $grantTypes = mcp_oauth_string_list($document['grant_types'] ?? ['authorization_code']);
    if ($grantTypes === []) {
        $grantTypes = ['authorization_code'];
    }
    $responseTypes = mcp_oauth_string_list($document['response_types'] ?? ['code']);
    if ($responseTypes === []) {
        $responseTypes = ['code'];
    }

    return [
        'client_id' => $clientId,
        'client_name' => $clientName,
        'redirect_uris' => array_values(array_unique($normalizedRedirects)),
        'token_endpoint_auth_method' => $selectedAuth,
        'grant_types' => $grantTypes,
        'response_types' => $responseTypes,
    ];
}

/**
 * @param list<string> $supported
 */
function mcp_oauth_select_auth_method(array $supported, string $preferred): string
{
    $serverAccepted = ['none', 'client_secret_post', 'client_secret_basic'];
    if ($supported === []) {
        if ($preferred !== '' && in_array($preferred, $serverAccepted, true)) {
            return $preferred;
        }
        return 'none';
    }
    $intersection = array_values(array_intersect($supported, $serverAccepted));
    if ($intersection === []) {
        return 'none';
    }
    if ($preferred !== '' && in_array($preferred, $intersection, true)) {
        return $preferred;
    }
    return $intersection[0];
}

/**
 * @param mixed $value
 * @return list<string>
 */
function mcp_oauth_string_list(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $out = [];
    foreach ($value as $item) {
        $item = trim((string) $item);
        if ($item !== '') {
            $out[] = $item;
        }
    }
    return array_values(array_unique($out));
}

// ---------------------------------------------------------------------------
// SSRF policy for CIMD fetches
// ---------------------------------------------------------------------------

/**
 * @return array{ok: bool, reason?: string, url?: string}
 */
function mcp_oauth_ssrf_validate_url(string $url): array
{
    $url = trim($url);
    if ($url === '') {
        return ['ok' => false, 'reason' => 'empty_url'];
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return ['ok' => false, 'reason' => 'invalid_url'];
    }
    if (strtolower((string) $parts['scheme']) !== 'https') {
        return ['ok' => false, 'reason' => 'https_required'];
    }
    $host = strtolower((string) $parts['host']);
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === 'metadata.google.internal') {
        return ['ok' => false, 'reason' => 'blocked_host'];
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!mcp_oauth_is_public_ip($host)) {
            return ['ok' => false, 'reason' => 'private_ip'];
        }
    } else {
        $ips = mcp_oauth_resolve_ips($host);
        if ($ips === []) {
            return ['ok' => false, 'reason' => 'unresolvable_host'];
        }
        foreach ($ips as $ip) {
            if (!mcp_oauth_is_public_ip($ip)) {
                return ['ok' => false, 'reason' => 'private_ip_resolved'];
            }
        }
    }
    $port = isset($parts['port']) ? (int) $parts['port'] : 443;
    if ($port !== 443 && $port !== 8443) {
        return ['ok' => false, 'reason' => 'blocked_port'];
    }
    return ['ok' => true, 'url' => $url];
}

function mcp_oauth_is_public_ip(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
    return false;
}

/**
 * @return list<string>
 */
function mcp_oauth_resolve_ips(string $host): array
{
    $ips = [];
    $a = @gethostbynamel($host);
    if (is_array($a)) {
        foreach ($a as $ip) {
            $ips[] = $ip;
        }
    }
    if (function_exists('dns_get_record')) {
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $row) {
                if (!empty($row['ipv6'])) {
                    $ips[] = (string) $row['ipv6'];
                }
            }
        }
    }
    return array_values(array_unique($ips));
}

// ---------------------------------------------------------------------------
// CORS headers helper
// ---------------------------------------------------------------------------

function mcp_oauth_cors_headers(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Authorization, Content-Type, Last-Event-ID, Mcp-Protocol-Version, Mcp-Session-Id');
    header('Access-Control-Expose-Headers: Mcp-Session-Id');
    header('Access-Control-Max-Age: 86400');
    header('Cache-Control: no-store');
}
