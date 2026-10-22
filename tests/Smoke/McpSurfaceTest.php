<?php

declare(strict_types=1);

return [
    'mcp modules and migration are present' => function (TestCase $test): void {
        foreach (['Care.php', 'McpApiKeys.php', 'McpTools.php', 'Mcp.php',
                  'McpOAuth.php', 'McpOAuthStore.php', 'McpOAuthEndpoints.php'] as $file) {
            $test->assertFileExists(__DIR__ . '/../../src/' . $file);
        }
        $test->assertFileExists(__DIR__ . '/../../database/migrations/021_mcp_api_keys.sql');
        $test->assertFileExists(__DIR__ . '/../../database/migrations/038_mcp_oauth.sql');
        $test->assertFileExists(__DIR__ . '/../../docs/mcp.md');
        $test->assertFileExists(__DIR__ . '/../../docs/oauth.md');
        $test->assertFileExists(__DIR__ . '/../../docs/personal-data-rf.md');
        $test->assertFileExists(__DIR__ . '/../../.cursor/mcp.json.example');
    },

    'schema and seed include user_api_keys and oauth tables' => function (TestCase $test): void {
        $schema = file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');
        $migration = file_get_contents(__DIR__ . '/../../database/migrations/021_mcp_api_keys.sql');
        $oauthMigration = file_get_contents(__DIR__ . '/../../database/migrations/038_mcp_oauth.sql');
        $test->assertStringContains('CREATE TABLE user_api_keys', $schema);
        $test->assertStringContains('CREATE TABLE IF NOT EXISTS user_api_keys', $migration);
        $test->assertStringContains('token_hash CHAR(64)', $migration);
        $test->assertStringContains('mcp_oauth_clients', $schema);
        $test->assertStringContains('mcp_oauth_codes', $schema);
        $test->assertStringContains('mcp_oauth_tokens', $schema);
        $test->assertStringContains('mcp_oauth_clients', $oauthMigration);
        $test->assertStringContains('code_challenge', $oauthMigration);
    },

    'public index exposes /mcp before login' => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $test->assertStringContains("if (\$path === '/mcp') { mcp_handle_http_request(); exit; }", $index);
        $test->assertFalse(
            strpos($index, "if (\$path === '/mcp')") > strpos($index, 'require_login()'),
            '/mcp must be registered before require_login'
        );
    },

    'public index registers oauth handler before mcp and require_login' => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $test->assertStringContains('mcp_oauth_handle_request($path, $method)', $index);
        $oauthPos = (int) strpos($index, 'mcp_oauth_handle_request');
        $mcpPos = (int) strpos($index, "if (\$path === '/mcp') { mcp_handle_http_request()");
        $loginPos = (int) strpos($index, 'require_login()');
        $test->assertTrue($oauthPos > 0, 'mcp_oauth_handle_request must be present');
        $test->assertTrue($oauthPos < $mcpPos, 'oauth handler must be before /mcp route');
        $test->assertTrue($oauthPos < $loginPos, 'oauth handler must be before require_login');
    },

    'oauth discovery covers path-aware AS URLs Cursor probes' => function (TestCase $test): void {
        require_once __DIR__ . '/../../src/McpOAuth.php';
        require_once __DIR__ . '/../../src/McpOAuthEndpoints.php';
        $paths = mcp_oauth_discovery_paths();
        $test->assertTrue(in_array('/.well-known/oauth-authorization-server/mcp', $paths, true));
        $test->assertTrue(in_array('/.well-known/openid-configuration/mcp', $paths, true));
        $test->assertTrue(in_array('/mcp/.well-known/oauth-authorization-server', $paths, true));
        $test->assertTrue(in_array('/mcp/.well-known/openid-configuration', $paths, true));
        $src = file_get_contents(__DIR__ . '/../../src/McpOAuthEndpoints.php');
        $test->assertStringContains('mcp_oauth_is_discovery_like_path', $src);
        $test->assertStringContains('Unexpected token', $src);
        $test->assertTrue(mcp_oauth_is_discovery_like_path('/.well-known/oauth-authorization-server/mcp'));
        $test->assertTrue(mcp_oauth_is_discovery_like_path('/mcp/.well-known/openid-configuration'));
        $test->assertFalse(mcp_oauth_is_discovery_like_path('/profile'));
    },

    'login page has mcp_oauth_return redirect' => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $test->assertStringContains('mcp_oauth_return', $index);
        $test->assertStringContains('mcp_oauth_is_safe_return_url', $index);
    },

    'profile wires api key panel and posts' => function (TestCase $test): void {
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $test->assertStringContains('mcp_render_api_keys_panel($user)', $account);
        $test->assertStringContains('mcp_handle_account_post($path, $method, $user)', $index);
    },

    'htaccess forwards Authorization for Bearer keys' => function (TestCase $test): void {
        $ht = file_get_contents(__DIR__ . '/../../public/.htaccess');
        $test->assertStringContains('HTTP_AUTHORIZATION', $ht);
        $test->assertStringContains('%{HTTP:Authorization}', $ht);
    },

    'meeting handlers use shared Care services' => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $care = file_get_contents(__DIR__ . '/../../src/Care.php');
        $test->assertStringContains('meeting_create_for_user', $index);
        $test->assertStringContains('meeting_save_attendance_for_user', $index);
        $test->assertStringContains('function care_people', $care);
        $test->assertFalse(str_contains($index, 'function care_people'));
    },

    'load_modules includes oauth modules before Mcp.php' => function (TestCase $test): void {
        $modules = file_get_contents(__DIR__ . '/../../src/load_modules.php');
        $test->assertStringContains("'McpOAuth.php'", $modules);
        $test->assertStringContains("'McpOAuthStore.php'", $modules);
        $test->assertStringContains("'McpOAuthEndpoints.php'", $modules);
        // OAuth modules must come before Mcp.php
        $oauthPos = (int) strpos($modules, "'McpOAuth.php'");
        $mcpPos = (int) strpos($modules, "'Mcp.php'");
        $test->assertTrue($oauthPos < $mcpPos, 'McpOAuth must load before Mcp.php');
    },

    'well-known paths treated as platform roots in ChurchTenant' => function (TestCase $test): void {
        $tenant = file_get_contents(__DIR__ . '/../../src/ChurchTenant.php');
        $test->assertStringContains("'/.well-known/'", $tenant);
        $test->assertStringContains("'/mcp/'", $tenant);
    },

    'oauth docs exist and mention endpoints' => function (TestCase $test): void {
        $oauth = file_get_contents(__DIR__ . '/../../docs/oauth.md');
        $test->assertStringContains('oauth-protected-resource', $oauth);
        $test->assertStringContains('ChatGPT', $oauth);
        $test->assertStringContains('Cursor', $oauth);
        $test->assertStringContains('wolhg_at_', $oauth);
        $test->assertStringContains('DCR', $oauth);
    },

    'mcp.md OAuth section updated' => function (TestCase $test): void {
        $mcp = file_get_contents(__DIR__ . '/../../docs/mcp.md');
        $test->assertStringContains('OAuth', $mcp);
        $test->assertStringContains('oauth.md', $mcp);
        $test->assertStringNotContains('можно добавить позже', $mcp);
    },

    'config app.php has oauth section' => function (TestCase $test): void {
        $cfg = file_get_contents(__DIR__ . '/../../config/app.php');
        $test->assertStringContains("'oauth'", $cfg);
        $test->assertStringContains("'access_ttl'", $cfg);
        $test->assertStringContains("'advertise_cimd'", $cfg);
    },

    'mcp freeze keeps endpoint and profile UI off by default' => function (TestCase $test): void {
        $cfg = require __DIR__ . '/../../config/app.php';
        $mcp = file_get_contents(__DIR__ . '/../../src/Mcp.php');
        $guide = file_get_contents(__DIR__ . '/../../src/SystemGuide.php');
        $oauth = file_get_contents(__DIR__ . '/../../src/McpOAuthEndpoints.php');

        $test->assertFalse((bool) ($cfg['mcp']['enabled'] ?? true));
        $test->assertStringContains('Fail-closed', $mcp);
        $test->assertStringContains("ajax_redirect('/profile', 'MCP отключён')", $mcp);
        $test->assertStringContains("'flags' => ['mcp_enabled']", $guide);
        $test->assertStringContains('mcp_oauth_protocol_paths()', $oauth);
    },
];
