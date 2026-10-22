<?php

declare(strict_types=1);

return [
    'homegroup catalog uses touch friendly filter dropdowns' => function (TestCase $test): void {
        $catalog = file_get_contents(__DIR__ . '/../../src/HomeGroupCatalog.php');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');

        $test->assertStringContains('render_filter_multiselect(', $catalog);
        $test->assertStringContains('data-filter-dropdown', $catalog);
        $test->assertStringContains('type="checkbox"', $catalog);
        $test->assertStringContains('data-filter-apply', $catalog);
        $test->assertStringContains('клавиатура не нужна', $catalog);
        $test->assertStringNotContains("render_multiselect('age'", $catalog);

        $test->assertStringContains("render_filter_multiselect('group_types', 'Тип'", $index);
        $settingsPos = strpos($index, '<h2>Настройки группы</h2>');
        $test->assertTrue($settingsPos !== false);
        $settingsBlock = substr($index, $settingsPos, 1200);
        $test->assertStringContains("render_filter_multiselect('group_types', 'Тип'", $settingsBlock);
        $test->assertStringNotContains('<label>Тип<select name="group_types[]" multiple', $settingsBlock);
        $test->assertStringNotContains('<label>Район<input name="district"', $settingsBlock);
    },

    'homegroup catalog renders list and map as tabs' => function (TestCase $test): void {
        $catalog = file_get_contents(__DIR__ . '/../../src/HomeGroupCatalog.php');

        $test->assertStringContains('data-homegroup-view', $catalog);
        $test->assertStringContains('data-homegroup-tab="list"', $catalog);
        $test->assertStringContains('data-homegroup-tab="map"', $catalog);
        $test->assertStringContains('data-homegroup-panel="list"', $catalog);
        $test->assertStringContains('data-homegroup-panel="map"', $catalog);
        $test->assertStringContains('data-show-church', $catalog);
        $test->assertStringNotContains('homegroup-layout', $catalog);
    },

    'homegroup client script wires filters tabs and lazy map init' => function (TestCase $test): void {
        $js = file_get_contents(__DIR__ . '/../../public/assets/app.js');

        $test->assertStringContains('initHomegroupFilters', $js);
        $test->assertStringContains('initHomegroupTabs', $js);
        $test->assertStringContains('ensureHomegroupMap', $js);
        $test->assertStringContains('homegroupFilterSummary', $js);
        $test->assertStringContains('data-filter-dropdown', $js);
        $test->assertStringContains('data-homegroup-tab', $js);
    },

    'homegroup stylesheet styles filter dropdowns and tabs' => function (TestCase $test): void {
        $css = file_get_contents(__DIR__ . '/../../public/assets/app.css');

        $test->assertStringContains('.filter-dropdown', $css);
        $test->assertStringContains('.homegroup-tabs', $css);
        $test->assertStringContains('.homegroup-tab-panel', $css);
        $test->assertStringNotContains('.homegroup-layout', $css);
    },

    'home group forms no longer expose district field' => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $features = file_get_contents(__DIR__ . '/../../src/Features.php');
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        $bots = file_get_contents(__DIR__ . '/../../src/Bots.php');

        $test->assertStringNotContains('<label>Район', $index);
        $test->assertStringNotContains('<label>Район', $features);
        $test->assertStringNotContains('Район или адрес', $features);
        $test->assertStringContains('name="q"', $features);
        $test->assertStringContains('home_group_matches_location_query', $features);
        $test->assertStringContains('home_group_matches_location_query', $bots);

        $test->assertStringNotContains("\$_POST['district']", $account);
        $test->assertStringNotContains("\$_POST['district']", $features);
        $test->assertStringNotContains('SET name = ?, district = ?', $account);
        $test->assertStringNotContains('INSERT INTO home_groups (church_id, name, district,', $account);
        $test->assertStringNotContains('INSERT INTO home_groups (church_id, name, district,', $features);

        $createPos = strpos($index, "e(app_path('/group-create'))");
        $test->assertTrue($createPos !== false);
        $createBlock = substr($index, $createPos, 1800);
        $test->assertStringNotContains('name="district"', $createBlock);
        $test->assertStringContains('name="short_address"', $createBlock);
    },
];
