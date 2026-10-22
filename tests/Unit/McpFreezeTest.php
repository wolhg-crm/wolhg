<?php

declare(strict_types=1);

/**
 * Freeze-mode contracts for MCP: default-off config, UI/POST guards, OAuth fail-closed.
 * Runtime behaviour of mcp_is_enabled() is covered via subprocess against real config.
 */

return [
    'default config freezes MCP' => function (TestCase $test): void {
        $app = (string) file_get_contents(__DIR__ . '/../../config/app.php');
        $localExample = (string) file_get_contents(__DIR__ . '/../../config/local.example.php');
        $merged = require __DIR__ . '/../../config/app.php';

        $test->assertStringContains("'enabled' => false", $app);
        $test->assertStringContains("'enabled' => false", $localExample);
        $test->assertFalse((bool) ($merged['mcp']['enabled'] ?? true));
    },

    'mcp_is_enabled is fail-closed for missing config' => function (TestCase $test): void {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Mcp.php');
        $test->assertStringContains('Fail-closed', $src);
        $test->assertMatches(
            '/if \(\$cfg === null \|\| \$cfg === \'\'\) \{\s*return false;/s',
            $src
        );
    },

    'profile panel and account posts refuse when MCP is disabled' => function (TestCase $test): void {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Mcp.php');
        $account = (string) file_get_contents(__DIR__ . '/../../src/Account.php');

        $test->assertStringContains('function mcp_render_api_keys_panel', $src);
        $test->assertMatches(
            '/function mcp_render_api_keys_panel\(array \$user\): void\s*\{\s*if \(!mcp_is_enabled\(\)\) \{\s*return;/s',
            $src
        );
        $test->assertStringContains("ajax_redirect('/profile', 'MCP отключён')", $src);
        $test->assertStringContains("unset(\$_SESSION['mcp_new_api_key'])", $src);
        $test->assertStringContains('mcp_render_api_keys_panel($user)', $account);
        $test->assertStringContains('mcp_flash_new_key_banner()', $account);
    },

    'http endpoint returns disabled payload when frozen' => function (TestCase $test): void {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Mcp.php');
        $test->assertMatches(
            '/function mcp_handle_http_request\(\): void\s*\{\s*if \(!mcp_is_enabled\(\)\) \{\s*http_response_code\(503\);/s',
            $src
        );
        $test->assertStringContains("['error' => 'MCP отключён']", $src);
    },

    'oauth protocol paths are blocked when OAuth is disabled' => function (TestCase $test): void {
        $src = (string) file_get_contents(__DIR__ . '/../../src/McpOAuthEndpoints.php');
        $test->assertStringContains('mcp_oauth_protocol_paths()', $src);
        $test->assertMatches(
            '/if \(!mcp_oauth_is_enabled\(\)\) \{.*?in_array\(\$normalPath, mcp_oauth_protocol_paths\(\), true\)/s',
            $src
        );
        $test->assertStringContains("mcp_oauth_json_not_found('OAuth is disabled on this server.')", $src);
    },

    'system guide MCP article is gated by mcp_enabled flag' => function (TestCase $test): void {
        $guide = (string) file_get_contents(__DIR__ . '/../../src/SystemGuide.php');
        $test->assertStringContains("'mcp_enabled' => function_exists('mcp_is_enabled') ? mcp_is_enabled() : false", $guide);
        $test->assertStringContains("'id' => 'settings-mcp'", $guide);
        $test->assertStringContains("'see' => ['flags' => ['mcp_enabled']]", $guide);
    },

    'runtime mcp_is_enabled follows merged app config' => function (TestCase $test): void {
        $merged = require __DIR__ . '/../../config/app.php';
        $test->assertTrue(isset($merged['mcp']['enabled']));
        $test->assertFalse((bool) $merged['mcp']['enabled']);

        // Keep the fail-closed contract in source (full bootstrap shell is covered by smoke deploy).
        $src = (string) file_get_contents(__DIR__ . '/../../src/Mcp.php');
        $test->assertMatches(
            '/function mcp_is_enabled\(\): bool\s*\{\s*\$cfg = app_config\(\'mcp\.enabled\'\);\s*\/\/ Fail-closed:[^\n]*\n\s*if \(\$cfg === null \|\| \$cfg === \'\'\) \{\s*return false;/s',
            $src
        );
    },

    'docs and readme describe freeze default' => function (TestCase $test): void {
        $mcp = (string) file_get_contents(__DIR__ . '/../../docs/mcp.md');
        $oauth = (string) file_get_contents(__DIR__ . '/../../docs/oauth.md');
        $readme = (string) file_get_contents(__DIR__ . '/../../README.md');
        $status = (string) file_get_contents(__DIR__ . '/../../docs/implementation-status.md');

        $test->assertStringContains('выключено', $mcp);
        $test->assertStringContains('mcp.enabled = false', $mcp);
        $test->assertStringContains('выключен', $oauth);
        $test->assertStringContains('временно выключен', $readme);
        $test->assertStringContains('временно заморожен', $status);
    },
];
