<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/load_modules.php';

return [
    'application modules exist on disk and define required handlers' => function (TestCase $test): void {
        foreach (application_modules() as $module => $functions) {
            $test->assertFileExists(__DIR__ . '/../../src/' . $module, 'Missing module file: ' . $module);
        }

        load_application_modules();

        foreach (application_modules() as $module => $functions) {
            foreach ($functions as $function) {
                $test->assertTrue(function_exists($function), $module . ' must define ' . $function);
            }
        }
    },

    'bootstrap loads modules through load_modules.php' => function (TestCase $test): void {
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');

        $test->assertStringContains("require_once __DIR__ . '/load_modules.php';", $bootstrap);
        $test->assertStringContains('load_application_modules();', $bootstrap);
        $test->assertFalse(str_contains($bootstrap, "require_once __DIR__ . '/RolePreview.php';"));
    },

    'public index uses handlers registered in application modules' => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $requiredCalls = [
            'homegroup_catalog_page();',
            'homegroup_join_page();',
            'homegroup_catalog_api();',
            'miracles_page($user)',
            'miracle_page($user)',
            'miracle_share_public_page();',
            'prayer_request_public_page();',
            'handle_role_preview_post($path, $method, $user)',
            'role_preview_blocks_mutation($method, $path)',
        ];

        foreach ($requiredCalls as $call) {
            $test->assertStringContains($call, $index);
        }
    },
];
