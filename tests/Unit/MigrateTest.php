<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Migrate.php';

return [
    'migrate_split_sql strips comments and splits statements' => function (TestCase $test): void {
        $sql = <<<'SQL'
SET NAMES utf8mb4;
-- comment
CREATE TABLE foo (id INT);
/* block
comment */
ALTER TABLE foo ADD COLUMN bar INT;
SQL;
        $parts = migrate_split_sql($sql);
        $test->assertCount(3, $parts);
        $test->assertTrue(str_contains($parts[0], 'SET NAMES'));
        $test->assertTrue(str_contains($parts[1], 'CREATE TABLE foo'));
        $test->assertTrue(str_contains($parts[2], 'ALTER TABLE foo'));
    },

    'migrate_list_files returns numbered sql only' => function (TestCase $test): void {
        $files = migrate_list_files();
        $test->assertTrue($files !== []);
        $test->assertTrue(in_array('021_mcp_api_keys.sql', $files, true));
        $test->assertTrue(in_array('026_districts.sql', $files, true));
        $test->assertTrue(in_array('027_rename_regional_leader.sql', $files, true));
        $test->assertTrue(in_array('038_mcp_oauth.sql', $files, true));
        $test->assertTrue(in_array('043_users_email_unique_per_church.sql', $files, true));
        $test->assertTrue(in_array('044_users_login_unique_per_church.sql', $files, true));
        $test->assertTrue(in_array('045_social_identity_per_church.sql', $files, true));
        $sorted = $files;
        sort($sorted, SORT_STRING);
        $test->assertSame($sorted, $files);
    },

    'effect probe map covers MCP api keys and oauth exactly' => function (TestCase $test): void {
        $map = migrate_effect_probe_map();
        $test->assertTrue(isset($map['021_mcp_api_keys.sql']));
        $test->assertSame('table', $map['021_mcp_api_keys.sql']['type']);
        $test->assertSame('user_api_keys', $map['021_mcp_api_keys.sql']['table']);

        $test->assertTrue(isset($map['038_mcp_oauth.sql']));
        $test->assertSame('tables', $map['038_mcp_oauth.sql']['type']);
        $tables = $map['038_mcp_oauth.sql']['tables'] ?? [];
        $test->assertTrue(in_array('mcp_oauth_clients', $tables, true));
        $test->assertTrue(in_array('mcp_oauth_tokens', $tables, true));
        $test->assertTrue(in_array('mcp_oauth_codes', $tables, true));
        $test->assertTrue(in_array('mcp_oauth_cimd', $tables, true));

        $test->assertTrue(isset($map['043_users_email_unique_per_church.sql']));
        $test->assertSame('index', $map['043_users_email_unique_per_church.sql']['type']);
        $test->assertSame('uniq_users_email_church', $map['043_users_email_unique_per_church.sql']['index']);
    },

    'effect probes use exact filenames not shared 030 prefix' => function (TestCase $test): void {
        $map = migrate_effect_probe_map();
        $test->assertTrue(isset($map['030_church_landing_logo.sql']));
        $test->assertFalse(isset($map['030_drop_manage_permissions_action.sql']),
            'drop migration must not share the logo probe — DELETE is idempotent');
        // Content-only / ops seeds must not live under database/migrations/.
        $test->assertFalse(isset($map['031_seed_korolev_landing.sql']));
    },

    'database migrations exclude church-specific ops and landing seeds' => function (TestCase $test): void {
        $dir = __DIR__ . '/../../database/migrations';
        $forbidden = [
            '031_seed_korolev_landing.sql',
            '032_update_korolev_landing_copy.sql',
            '033_seed_pushkino_landing_and_korolev_phone.sql',
            '034_korolev_max_channel_url.sql',
            '035_seed_landing_faq_korolev_pushkino.sql',
            '036_landing_og_image_logo.sql',
            '037_seed_korolev_landing_gallery.sql',
            '043_move_skycrew42_pushkino_to_korolev.sql',
            '044_role_invite_skycrew42_church_korolev.sql',
        ];
        foreach ($forbidden as $name) {
            $test->assertFalse(is_file($dir . '/' . $name), $name . ' must not ship in public migrations');
        }
        $files = migrate_list_files();
        foreach ($forbidden as $name) {
            $test->assertFalse(in_array($name, $files, true), $name . ' must not be listed by migrate_list_files');
        }
        foreach ($files as $name) {
            $body = (string) file_get_contents($dir . '/' . $name);
            $test->assertFalse(
                (bool) preg_match('/skycrew42|Туляков/u', $body),
                $name . ' must not contain personal ops fixes'
            );
        }
    },

    '021 and 038 migration SQL create required tables' => function (TestCase $test): void {
        $apiKeys = (string) file_get_contents(__DIR__ . '/../../database/migrations/021_mcp_api_keys.sql');
        $oauth = (string) file_get_contents(__DIR__ . '/../../database/migrations/038_mcp_oauth.sql');
        $test->assertStringContains('CREATE TABLE IF NOT EXISTS user_api_keys', $apiKeys);
        $test->assertStringContains('token_hash CHAR(64)', $apiKeys);
        foreach (['mcp_oauth_clients', 'mcp_oauth_codes', 'mcp_oauth_tokens', 'mcp_oauth_cimd'] as $table) {
            $test->assertStringContains('CREATE TABLE IF NOT EXISTS ' . $table, $oauth);
        }
        $test->assertFalse(str_contains($oauth, "LONGTEXT NOT NULL DEFAULT"),
            'MySQL rejects DEFAULT on LONGTEXT — migration must not use it');
    },

    'baseline must only record verified effects (regression guard)' => function (TestCase $test): void {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Migrate.php');
        $test->assertStringContains('migrate_effect_present($pdo, $file) === true', $src);
        $test->assertFalse(
            str_contains($src, 'No known pending probes: if districts+role ok, mark everything applied'),
            'dangerous baseline that marked 021 applied without creating user_api_keys must stay removed'
        );
        $test->assertStringContains('Self-heal', $src);
        $test->assertStringContains("'repaired'", $src);
    },

    'verify-schema requires user_api_keys and oauth tables' => function (TestCase $test): void {
        $src = (string) file_get_contents(__DIR__ . '/../../bin/verify-schema.php');
        $test->assertStringContains("'user_api_keys'", $src);
        $test->assertStringContains("'mcp_oauth_clients'", $src);
        $test->assertStringContains('021_mcp_api_keys.sql', $src);
        $test->assertStringContains('038_mcp_oauth.sql', $src);
    },
];
