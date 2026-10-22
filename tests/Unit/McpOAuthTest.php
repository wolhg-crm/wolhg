<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/McpApiKeys.php';
require_once __DIR__ . '/../../src/McpOAuth.php';

if (!function_exists('app_config')) {
    function app_config(?string $key = null): mixed
    {
        static $cfg = [
            'url' => 'https://wolhg.test',
            'mcp.enabled' => true,
            'mcp.oauth.enabled' => true,
            'mcp.oauth.advertise_cimd' => false,
            'mcp.oauth.access_ttl' => 3600,
            'mcp.oauth.refresh_ttl' => 2592000,
        ];
        if ($key === null) {
            return [];
        }
        return $cfg[$key] ?? null;
    }
}

if (!function_exists('mcp_is_enabled')) {
    function mcp_is_enabled(): bool { return true; }
}

return [
    'redirect URI: https allowed' => function (TestCase $test): void {
        $test->assertTrue(mcp_oauth_redirect_uri_is_allowed('https://example.com/callback'));
        $test->assertTrue(mcp_oauth_redirect_uri_is_allowed('https://cursor.sh/oauth/callback'));
    },

    'redirect URI: http loopback allowed' => function (TestCase $test): void {
        $test->assertTrue(mcp_oauth_redirect_uri_is_allowed('http://localhost:8787/callback'));
        $test->assertTrue(mcp_oauth_redirect_uri_is_allowed('http://127.0.0.1:3000/callback'));
    },

    'redirect URI: cursor:// native scheme allowed' => function (TestCase $test): void {
        $test->assertTrue(mcp_oauth_redirect_uri_is_allowed('cursor://anysphere.cursor-mcp/oauth/callback'));
    },

    'redirect URI: javascript: rejected' => function (TestCase $test): void {
        $test->assertFalse(mcp_oauth_redirect_uri_is_allowed('javascript:alert(1)'));
    },

    'redirect URI: fragment rejected' => function (TestCase $test): void {
        $test->assertFalse(mcp_oauth_redirect_uri_is_allowed('https://example.com/callback#evil'));
    },

    'redirect URI: http non-loopback rejected' => function (TestCase $test): void {
        $test->assertFalse(mcp_oauth_redirect_uri_is_allowed('http://evil.com/callback'));
    },

    'redirect URI: data: rejected' => function (TestCase $test): void {
        $test->assertFalse(mcp_oauth_redirect_uri_is_allowed('data:text/html,xss'));
    },

    'PKCE S256 verify correct' => function (TestCase $test): void {
        $verifier = str_repeat('a', 43);
        $challenge = mcp_pkce_challenge_s256($verifier);
        $test->assertTrue(mcp_pkce_verify($verifier, $challenge, 'S256'));
    },

    'PKCE S256 verify wrong verifier' => function (TestCase $test): void {
        $verifier = str_repeat('a', 43);
        $challenge = mcp_pkce_challenge_s256($verifier);
        $test->assertFalse(mcp_pkce_verify(str_repeat('b', 43), $challenge, 'S256'));
    },

    'PKCE plain method rejected' => function (TestCase $test): void {
        $test->assertFalse(mcp_pkce_is_supported_method('plain'));
        $test->assertTrue(mcp_pkce_is_supported_method('S256'));
    },

    'PKCE verifier length validation' => function (TestCase $test): void {
        $test->assertFalse(mcp_pkce_is_valid_verifier(str_repeat('a', 42))); // too short
        $test->assertTrue(mcp_pkce_is_valid_verifier(str_repeat('a', 43)));
        $test->assertTrue(mcp_pkce_is_valid_verifier(str_repeat('a', 128)));
        $test->assertFalse(mcp_pkce_is_valid_verifier(str_repeat('a', 129))); // too long
    },

    'URL canonicalize strips trailing slash' => function (TestCase $test): void {
        $test->assertSame('https://wolhg.test/mcp', mcp_url_canonicalize('https://wolhg.test/mcp/'));
        $test->assertSame('https://wolhg.test', mcp_url_canonicalize('https://wolhg.test/'));
    },

    'URL canonicalize normalizes scheme and host case only' => function (TestCase $test): void {
        // Scheme and host lowercased; path is case-sensitive per RFC 3986
        $result = mcp_url_canonicalize('HTTPS://WOLHG.TEST/mcp');
        $test->assertSame('https://wolhg.test/mcp', $result);
    },

    'URL canonicalize drops default port' => function (TestCase $test): void {
        $test->assertSame('https://wolhg.test/mcp', mcp_url_canonicalize('https://wolhg.test:443/mcp'));
        $test->assertSame('http://wolhg.test/mcp', mcp_url_canonicalize('http://wolhg.test:80/mcp'));
    },

    'URL equals is slash-insensitive' => function (TestCase $test): void {
        $test->assertTrue(mcp_url_equals('https://wolhg.test/mcp', 'https://wolhg.test/mcp/'));
        $test->assertTrue(mcp_url_equals('https://wolhg.test/mcp/', 'https://wolhg.test/mcp'));
        $test->assertFalse(mcp_url_equals('https://wolhg.test/mcp', 'https://evil.com/mcp'));
    },

    'http_endpoint adds trailing slash' => function (TestCase $test): void {
        $test->assertSame('https://wolhg.test/mcp/oauth/token/', mcp_http_endpoint('https://wolhg.test/mcp/oauth/token'));
        $test->assertSame('https://wolhg.test/mcp/oauth/token/', mcp_http_endpoint('https://wolhg.test/mcp/oauth/token/'));
    },

    'PRM metadata has resource, authorization_servers, bearer_methods_supported' => function (TestCase $test): void {
        $meta = mcp_oauth_prm_metadata(['read', 'attendance']);
        $test->assertTrue(isset($meta['resource']));
        $test->assertTrue(isset($meta['authorization_servers']));
        $test->assertTrue(isset($meta['bearer_methods_supported']));
        $test->assertTrue(isset($meta['scopes_supported']));
        $test->assertTrue(in_array('read', (array) $meta['scopes_supported'], true));
        // Resource must not end with slash (RFC 8707 resource indicator)
        $resource = (string) $meta['resource'];
        if ($resource !== '') {
            $test->assertFalse(str_ends_with($resource, '/'));
        }
    },

    'AS metadata has required fields' => function (TestCase $test): void {
        $meta = mcp_oauth_as_metadata(['read']);
        $test->assertTrue(isset($meta['issuer']));
        $test->assertTrue(isset($meta['authorization_endpoint']));
        $test->assertTrue(isset($meta['token_endpoint']));
        $test->assertTrue(isset($meta['registration_endpoint']));
        $test->assertTrue(isset($meta['code_challenge_methods_supported']));
        $test->assertTrue(in_array('S256', (array) $meta['code_challenge_methods_supported'], true));
        $test->assertTrue((bool) $meta['authorization_response_iss_parameter_supported']);
    },

    'AS metadata endpoints have trailing slash' => function (TestCase $test): void {
        $meta = mcp_oauth_as_metadata(['read']);
        // Endpoints must have trailing slash to avoid Apache 301 on POST
        $tokenUrl = (string) $meta['token_endpoint'];
        $regUrl = (string) $meta['registration_endpoint'];
        $authUrl = (string) $meta['authorization_endpoint'];
        if ($tokenUrl !== '') {
            $test->assertTrue(str_ends_with($tokenUrl, '/'));
        }
        if ($regUrl !== '') {
            $test->assertTrue(str_ends_with($regUrl, '/'));
        }
        if ($authUrl !== '') {
            $test->assertTrue(str_ends_with($authUrl, '/'));
        }
    },

    'discovery path list includes Cursor path-aware AS probes' => function (TestCase $test): void {
        require_once __DIR__ . '/../../src/McpOAuthEndpoints.php';
        $paths = mcp_oauth_discovery_paths();
        foreach ([
            '/.well-known/oauth-authorization-server',
            '/.well-known/oauth-authorization-server/mcp',
            '/.well-known/openid-configuration/mcp',
            '/mcp/.well-known/oauth-authorization-server',
            '/mcp/.well-known/openid-configuration',
        ] as $path) {
            $test->assertTrue(in_array($path, $paths, true), 'missing discovery path ' . $path);
        }
    },

    'protected resource metadata URL has trailing slash on well-known path' => function (TestCase $test): void {
        // mcp_http_endpoint always adds trailing slash to non-root paths
        $url = mcp_http_endpoint('https://example.com/.well-known/oauth-protected-resource/mcp');
        $test->assertTrue(str_ends_with($url, '/'));
        $test->assertStringContains('/.well-known/oauth-protected-resource/mcp/', $url);
    },

    'WWW-Authenticate contains resource_metadata' => function (TestCase $test): void {
        $url = 'https://example.com/.well-known/oauth-protected-resource/mcp/';
        $header = mcp_oauth_www_authenticate($url, [], 'invalid_token', 'reason');
        $test->assertStringContains('resource_metadata=', $header);
        $test->assertStringContains($url, $header);
        $test->assertStringContains('Bearer ', $header);
    },

    'WWW-Authenticate without error omits error field' => function (TestCase $test): void {
        $url = 'https://example.com/.well-known/oauth-protected-resource/mcp/';
        $header = mcp_oauth_www_authenticate($url);
        $test->assertStringNotContains('error=', $header);
    },

    'token prefixes correct' => function (TestCase $test): void {
        $at = mcp_oauth_generate_access_token();
        $rt = mcp_oauth_generate_refresh_token();
        $code = mcp_oauth_generate_auth_code();
        $clientId = mcp_oauth_generate_client_id();
        $test->assertTrue(str_starts_with($at, 'wolhg_at_'));
        $test->assertTrue(str_starts_with($rt, 'wolhg_rt_'));
        $test->assertTrue(str_starts_with($code, 'wolhg_ac_'));
        $test->assertTrue(str_starts_with($clientId, 'wolhg_oc_'));
        $test->assertTrue(mcp_oauth_looks_like_access_token($at));
        $test->assertTrue(mcp_oauth_looks_like_refresh_token($rt));
        $test->assertFalse(mcp_oauth_looks_like_access_token($rt));
    },

    'hash returns 64 hex chars' => function (TestCase $test): void {
        $hash = mcp_oauth_hash('test');
        $test->assertSame(64, strlen($hash));
        $test->assertTrue(ctype_xdigit($hash));
    },

    'safe return URL validates authorize path only' => function (TestCase $test): void {
        $test->assertTrue(mcp_oauth_is_safe_return_url('/mcp/oauth/authorize?client_id=x'));
        $test->assertFalse(mcp_oauth_is_safe_return_url('/profile'));
        $test->assertFalse(mcp_oauth_is_safe_return_url('https://evil.com/'));
        $test->assertFalse(mcp_oauth_is_safe_return_url(''));
    },

    'authorize request validation rejects missing PKCE' => function (TestCase $test): void {
        $result = mcp_oauth_validate_authorize_request([
            'response_type' => 'code',
            'client_id' => 'test',
            'redirect_uri' => 'https://example.com/cb',
            'resource' => 'https://wolhg.test/mcp',
        ], 'https://wolhg.test/mcp');
        $test->assertFalse($result['ok']);
        $test->assertSame('invalid_request', $result['error']);
    },

    'authorize request validation rejects wrong resource' => function (TestCase $test): void {
        $result = mcp_oauth_validate_authorize_request([
            'response_type' => 'code',
            'client_id' => 'test',
            'redirect_uri' => 'https://example.com/cb',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
            'resource' => 'https://evil.com/mcp',
        ], 'https://wolhg.test/mcp');
        $test->assertFalse($result['ok']);
        $test->assertSame('invalid_target', $result['error']);
    },

    'token request validation passes slash-insensitive resource' => function (TestCase $test): void {
        $result = mcp_oauth_validate_token_request([
            'grant_type' => 'authorization_code',
            'code' => 'wolhg_ac_abc',
            'redirect_uri' => 'https://example.com/cb',
            'client_id' => 'test',
            'code_verifier' => str_repeat('a', 43),
            'resource' => 'https://wolhg.test/mcp/', // trailing slash
        ], 'https://wolhg.test/mcp');
        $test->assertTrue($result['ok']);
    },

    'CIMD parse validates client_id matches URL' => function (TestCase $test): void {
        $doc = [
            'client_id' => 'https://example.com/app',
            'redirect_uris' => ['https://example.com/callback'],
            'client_name' => 'Test App',
        ];
        $result = mcp_oauth_parse_cimd($doc, 'https://example.com/app');
        $test->assertNotNull($result);
        $test->assertSame('https://example.com/app', $result['client_id']);
        $test->assertSame(['https://example.com/callback'], $result['redirect_uris']);
    },

    'CIMD parse rejects mismatched client_id' => function (TestCase $test): void {
        $doc = [
            'client_id' => 'https://evil.com/app',
            'redirect_uris' => ['https://example.com/callback'],
        ];
        $test->assertNull(mcp_oauth_parse_cimd($doc, 'https://example.com/app'));
    },

    'CIMD parse rejects non-https client_id' => function (TestCase $test): void {
        $doc = [
            'client_id' => 'http://example.com/app',
            'redirect_uris' => ['https://example.com/callback'],
        ];
        $test->assertNull(mcp_oauth_parse_cimd($doc, 'http://example.com/app'));
    },

    'is_url_client_id detects https URLs' => function (TestCase $test): void {
        $test->assertTrue(mcp_oauth_is_url_client_id('https://example.com/app'));
        $test->assertFalse(mcp_oauth_is_url_client_id('wolhg_oc_abc123'));
    },

    'SSRF policy blocks localhost' => function (TestCase $test): void {
        $result = mcp_oauth_ssrf_validate_url('https://localhost/app');
        $test->assertFalse($result['ok']);
    },

    'SSRF policy requires https' => function (TestCase $test): void {
        $result = mcp_oauth_ssrf_validate_url('http://example.com/app');
        $test->assertFalse($result['ok']);
        $test->assertSame('https_required', $result['reason']);
    },

    'scope parse and format round-trip' => function (TestCase $test): void {
        $scopes = mcp_oauth_parse_scopes('read attendance');
        $test->assertSame(['read', 'attendance'], $scopes);
        $test->assertSame('read attendance', mcp_oauth_format_scopes($scopes));
    },

    'scope parse deduplicates and normalizes order' => function (TestCase $test): void {
        $scopes = mcp_oauth_parse_scopes('attendance READ attendance');
        $test->assertSame(['read', 'attendance'], $scopes);
    },
];
