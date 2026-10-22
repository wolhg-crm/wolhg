<?php

declare(strict_types=1);

return [
    'districts schema and migrations exist' => function (TestCase $test): void {
        $schema = file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');
        $test->assertStringContains('CREATE TABLE districts', $schema);
        $test->assertStringContains('CREATE TABLE district_leaders', $schema);
        $test->assertStringContains('CREATE TABLE district_catalog_cache', $schema);
        $test->assertStringContains('district_id INT UNSIGNED NULL', $schema);
        $test->assertFileExists(__DIR__ . '/../../database/migrations/026_districts.sql');
        $test->assertFileExists(__DIR__ . '/../../database/migrations/027_rename_regional_leader.sql');
    },
    'application uses district_leader not regional_leader' => function (TestCase $test): void {
        $features = file_get_contents(__DIR__ . '/../../src/Features.php');
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');
        $test->assertStringContains("'district_leader'", $features);
        $test->assertStringNotContains("'regional_leader'", $features);
        $test->assertStringContains('Окружной лидер', $bootstrap);
        $test->assertStringNotContains('Областной лидер', $bootstrap);
    },
    'districts module and route are wired' => function (TestCase $test): void {
        $modules = file_get_contents(__DIR__ . '/../../src/load_modules.php');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $districts = file_get_contents(__DIR__ . '/../../src/Districts.php');
        $test->assertStringContains("'Districts.php'", $modules);
        $test->assertStringContains("'OsmDistrictCatalog.php'", $modules);
        $test->assertStringContains("'/districts' =>", $index);
        $test->assertStringContains('function districts_page', $districts);
        $test->assertStringContains('function home_group_apply_district', $districts);
        $test->assertStringContains('data-districts-map', $districts);
    },
    'group forms do not write legacy district column' => function (TestCase $test): void {
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        $test->assertStringNotContains("\$_POST['district']", $account);
        $test->assertStringContains('home_group_apply_district', $account);
    },
    'db helper execute is defined for district writers' => function (TestCase $test): void {
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');
        $districts = file_get_contents(__DIR__ . '/../../src/Districts.php');
        $osm = file_get_contents(__DIR__ . '/../../src/OsmDistrictCatalog.php');
        $test->assertMatches('/function execute\s*\(\s*string \$sql\s*,\s*array \$params\s*=\s*\[\]\s*\)\s*:\s*int/', $bootstrap);
        $test->assertStringContains('return $stmt->rowCount();', $bootstrap);
        // District mutations and OSM cache must call the shared helper (not a missing global).
        $districtCalls = preg_match_all('/(?<!->)\bexecute\s*\(/', $districts);
        $osmCalls = preg_match_all('/(?<!->)\bexecute\s*\(/', $osm);
        $test->assertTrue($districtCalls > 0, 'Districts.php must call execute()');
        $test->assertTrue($osmCalls > 0, 'OsmDistrictCatalog.php must call execute()');
    },
    'group create and update assign district after persisting location' => function (TestCase $test): void {
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        $createPos = strpos($account, "if (\$path === '/group-create')");
        $updatePos = strpos($account, "if (\$path === '/group-update')");
        $test->assertTrue($createPos !== false, 'group-create handler missing');
        $test->assertTrue($updatePos !== false, 'group-update handler missing');

        $createBlock = substr($account, $createPos, $updatePos - $createPos);
        $test->assertStringContains('INSERT INTO home_groups', $createBlock);
        $test->assertStringContains('home_group_apply_district(', $createBlock);
        $test->assertStringContains("Укажите название группы", $createBlock);
        $insertPos = strpos($createBlock, 'INSERT INTO home_groups');
        $applyPos = strpos($createBlock, 'home_group_apply_district(');
        $test->assertTrue($insertPos !== false && $applyPos !== false && $insertPos < $applyPos);

        $deletePos = strpos($account, "if (\$path === '/group-delete')");
        $updateBlock = $deletePos !== false
            ? substr($account, $updatePos, $deletePos - $updatePos)
            : substr($account, $updatePos, 2500);
        $test->assertStringContains('UPDATE home_groups SET', $updateBlock);
        $test->assertStringContains('home_group_apply_district(', $updateBlock);
    },
];
