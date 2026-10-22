<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/McpApiKeys.php';
require_once __DIR__ . '/../../src/McpTools.php';
require_once __DIR__ . '/../../src/Mcp.php';

if (!function_exists('app_config')) {
    function app_config(?string $key = null): mixed
    {
        if ($key === null) {
            return ['name' => 'CRM Домашних групп', 'mcp' => ['enabled' => true]];
        }
        return match ($key) {
            'name' => 'CRM Домашних групп',
            'mcp.enabled' => true,
            'url' => 'https://example.test',
            default => null,
        };
    }
}

return [
    'api key scopes normalize and reject unknown' => function (TestCase $test): void {
        $test->assertSame(['read', 'attendance'], mcp_normalize_api_key_scopes(['attendance', 'READ', 'attendance', 'nope']));
        $test->assertSame([], mcp_normalize_api_key_scopes(['nope']));
    },

    'write scopes imply read' => function (TestCase $test): void {
        $test->assertTrue(mcp_has_api_key_scope(['attendance'], 'read'));
        $test->assertTrue(mcp_has_api_key_scope(['people'], 'people'));
        $test->assertFalse(mcp_has_api_key_scope(['read'], 'attendance'));
        $test->assertFalse(mcp_has_api_key_scope(['read'], 'church'));
    },

    'role restricts grantable scopes' => function (TestCase $test): void {
        $test->assertSame(['read', 'people'], mcp_scopes_allowed_for_role('member'));
        $test->assertSame(['read', 'attendance', 'people'], mcp_scopes_allowed_for_role('leader'));
        $test->assertTrue(in_array('church', mcp_scopes_allowed_for_role('pastor'), true));
        $test->assertTrue(in_array('roles', mcp_scopes_allowed_for_role('admin'), true));
    },

    'filter scopes for user drops forbidden church for leader' => function (TestCase $test): void {
        $user = ['role' => 'leader'];
        $test->assertSame(
            ['read', 'attendance'],
            mcp_filter_scopes_for_user($user, ['read', 'attendance', 'church', 'roles'])
        );
    },

    'tools list is filtered by scopes' => function (TestCase $test): void {
        $readOnly = mcp_tools_for_scopes(['read']);
        $names = array_column($readOnly, 'name');
        $test->assertTrue(in_array('get_me', $names, true));
        $test->assertTrue(in_array('get_care_radar', $names, true));
        $test->assertFalse(in_array('save_meeting_attendance', $names, true));
        $test->assertFalse(in_array('change_user_role', $names, true));
        $test->assertFalse(in_array('get_church_stats', $names, true));

        $withChurch = array_column(mcp_tools_for_scopes(['read', 'church']), 'name');
        $test->assertTrue(in_array('get_church_stats', $withChurch, true));
    },

    'token prefix detection' => function (TestCase $test): void {
        $test->assertTrue(mcp_is_api_key_token('wolhg_ak_abc'));
        $test->assertFalse(mcp_is_api_key_token('smp_ak_abc'));
        $test->assertSame(64, strlen(mcp_hash_api_key_token('wolhg_ak_secret')));
    },

    'jsonrpc initialize returns protocol and instructions' => function (TestCase $test): void {
        $principal = [
            'user' => [
                'id' => 1,
                'name' => 'Test',
                'login' => 'test',
                'email' => 't@example.com',
                'role' => 'leader',
                'church_id' => 1,
                'group_id' => 1,
            ],
            'scopes' => ['read'],
            'key_id' => 9,
        ];
        $response = mcp_handle_jsonrpc($principal, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '0'],
            ],
        ]);
        $test->assertSame('2025-03-26', $response['result']['protocolVersion']);
        $test->assertSame('wolhg', $response['result']['serverInfo']['name']);
        $test->assertStringContains('152-ФЗ', $response['result']['instructions']);
    },

    'jsonrpc tools/list respects scopes' => function (TestCase $test): void {
        $principal = [
            'user' => ['id' => 1, 'role' => 'leader'],
            'scopes' => ['read', 'attendance'],
            'key_id' => 1,
        ];
        $response = mcp_handle_jsonrpc($principal, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ]);
        $names = array_column($response['result']['tools'], 'name');
        $test->assertTrue(in_array('create_meeting', $names, true));
        $test->assertFalse(in_array('change_user_role', $names, true));
    },

    'jsonrpc unknown method returns -32601' => function (TestCase $test): void {
        $principal = ['user' => ['id' => 1, 'role' => 'admin'], 'scopes' => ['read'], 'key_id' => 1];
        $response = mcp_handle_jsonrpc($principal, [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'no/such',
            'params' => [],
        ]);
        $test->assertSame(-32601, $response['error']['code']);
    },

    'notifications return null' => function (TestCase $test): void {
        $principal = ['user' => ['id' => 1, 'role' => 'admin'], 'scopes' => ['read'], 'key_id' => 1];
        $response = mcp_handle_jsonrpc($principal, [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);
        $test->assertNull($response);
    },
];
