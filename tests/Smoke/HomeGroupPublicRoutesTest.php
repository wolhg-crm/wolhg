<?php

declare(strict_types=1);

return [
    'homegroup handlers are registered in application modules' => function (TestCase $test): void {
        require_once __DIR__ . '/../../src/load_modules.php';
        $modules = application_modules();
        $catalog = file_get_contents(__DIR__ . '/../../src/HomeGroupCatalog.php');

        $test->assertTrue(array_key_exists('HomeGroupCatalog.php', $modules));
        $test->assertSame(
            ['homegroup_catalog_page', 'homegroup_join_page', 'homegroup_catalog_api'],
            $modules['HomeGroupCatalog.php']
        );

        foreach ([
            'homegroup_catalog_page',
            'homegroup_join_page',
            'homegroup_catalog_api',
            'fetch_public_home_groups',
            'public_home_groups_scope_sql',
            'homegroup_catalog_scope_church',
            'fetch_public_home_group_by_id',
        ] as $function) {
            $test->assertStringContains('function ' . $function, $catalog);
        }
    },

    'application source does not query removed regions table' => function (TestCase $test): void {
        $forbidden = ['FROM regions', 'JOIN regions', 'INTO regions', 'UPDATE regions', 'regions r'];
        foreach (glob(__DIR__ . '/../../src/*.php') ?: [] as $file) {
            $contents = file_get_contents($file);
            foreach ($forbidden as $needle) {
                $test->assertFalse(
                    str_contains($contents, $needle),
                    basename($file) . ' must not reference removed table: ' . $needle
                );
            }
        }
    },

    'home group php modules pass syntax check' => function (TestCase $test): void {
        foreach (['src/HomeGroup.php', 'src/HomeGroupCatalog.php'] as $file) {
            $path = __DIR__ . '/../../' . $file;
            exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
            $test->assertSame(0, $exitCode, implode("\n", $output));
        }
    },
];
