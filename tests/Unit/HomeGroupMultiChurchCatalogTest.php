<?php

declare(strict_types=1);

/**
 * Multi-church public catalog: /homegroup (all) and /{slug}/homegroup (scoped).
 */

if (!function_exists('app_config')) {
    function app_config(?string $key = null, mixed $default = null): mixed
    {
        $config = [
            'legacy_default_slug' => 'pushkino',
            'default_church_id' => 1,
        ];
        if ($key === null) {
            return $config;
        }

        return $config[$key] ?? $default;
    }
}

require_once __DIR__ . '/../../src/ChurchTenant.php';
require_once __DIR__ . '/../../src/HomeGroup.php';
require_once __DIR__ . '/../../src/HomeGroupCatalog.php';

return [
    'public scope sql includes all churches or one church id' => function (TestCase $test): void {
        [$allSql, $allParams] = public_home_groups_scope_sql(null);
        $test->assertStringContains('c.deleted_at IS NULL', $allSql);
        $test->assertStringContains('g.is_active = 1', $allSql);
        $test->assertStringContains('g.latitude IS NOT NULL', $allSql);
        $test->assertStringNotContains('g.church_id = ?', $allSql);
        $test->assertSame([], $allParams);

        [$oneSql, $oneParams] = public_home_groups_scope_sql(3);
        $test->assertStringContains('g.church_id = ?', $oneSql);
        $test->assertStringContains('c.deleted_at IS NULL', $oneSql);
        $test->assertSame([3], $oneParams);

        [$zeroSql, $zeroParams] = public_home_groups_scope_sql(0);
        $test->assertStringNotContains('g.church_id = ?', $zeroSql);
        $test->assertSame([], $zeroParams);
    },

    'public catalog helpers exclude demo churches from platform-wide surfaces' => function (TestCase $test): void {
        $catalog = file_get_contents(__DIR__ . '/../../src/HomeGroupCatalog.php');
        $demo = file_get_contents(__DIR__ . '/../../src/DemoAccess.php');

        $test->assertStringContains('public_sql_exclude_demo_churches', $demo);
        $test->assertStringContains('church_visible_on_public_catalog', $demo);
        $test->assertStringContains('public_home_groups_visibility_sql', $catalog);
        $test->assertStringContains('public_sql_exclude_demo_churches', $catalog);
        $test->assertStringContains('church_visible_on_public_catalog', $catalog);
        $test->assertStringContains('is_demo = 0', $catalog);
    },

    'catalog scope church follows URL tenant only' => function (TestCase $test): void {
        set_current_church(null);
        $test->assertSame(null, homegroup_catalog_scope_church());

        set_current_church(['id' => 2, 'slug' => 'korolev', 'city' => 'Королёв', 'deleted_at' => null]);
        $scoped = homegroup_catalog_scope_church();
        $test->assertTrue(is_array($scoped));
        $test->assertSame(2, (int) $scoped['id']);

        set_current_church(['id' => 9, 'slug' => 'gone', 'deleted_at' => '2026-01-01 00:00:00']);
        $test->assertSame(null, homegroup_catalog_scope_church());
        set_current_church(null);
    },

    'catalog page sources groups from scope church or all churches' => function (TestCase $test): void {
        $catalog = file_get_contents(__DIR__ . '/../../src/HomeGroupCatalog.php');

        $test->assertStringContains('homegroup_catalog_scope_church()', $catalog);
        $test->assertStringContains('public_homegroup_catalog_path($scopeChurch)', $catalog);
        $test->assertStringContains('fetch_public_home_groups($churchId, $filters)', $catalog);
        $test->assertStringContains('INNER JOIN churches c ON c.id = g.church_id', $catalog);
        $test->assertStringContains('data-show-church', $catalog);
        $test->assertStringContains('Смотреть группы всех церквей', $catalog);
        $test->assertStringContains('homegroup-card__church', $catalog);
        $test->assertStringContains("render_filter_multiselect('churches', 'Церковь'", $catalog);
        $test->assertStringContains('homegroup_catalog_church_filter_options', $catalog);
        $test->assertStringNotContains('fetch_public_home_groups((int) $church[\'id\']', $catalog);
        $test->assertStringNotContains("\$church = default_church();", $catalog);
    },

    'church filter options require at least two churches' => function (TestCase $test): void {
        $test->assertSame([], homegroup_catalog_church_filter_options([]));
        $test->assertSame([], homegroup_catalog_church_filter_options([
            ['id' => 1, 'city' => 'Пушкино', 'name' => 'СЖ Пушкино'],
        ]));
        $test->assertSame(
            ['1' => 'Пушкино', '2' => 'Королёв'],
            homegroup_catalog_church_filter_options([
                ['id' => 1, 'city' => 'Пушкино', 'name' => 'СЖ Пушкино'],
                ['id' => 2, 'city' => 'Королёв', 'name' => 'СЖ Королёв'],
            ])
        );
    },

    'join resolves groups across churches and notifies target church admins' => function (TestCase $test): void {
        $catalog = file_get_contents(__DIR__ . '/../../src/HomeGroupCatalog.php');

        $test->assertStringContains('function fetch_public_home_group_by_id', $catalog);
        $test->assertStringContains('fetch_public_home_group_by_id($groupId, $scopeChurchId)', $catalog);
        $test->assertStringContains('fetch_public_home_group_by_id($form[\'group_id\'], $scopeChurchId)', $catalog);
        $test->assertStringContains('$targetChurchId', $catalog);
        $test->assertStringContains('[$targetChurchId]', $catalog);
        $test->assertStringContains('needsGroupPick', $catalog);
        $test->assertStringContains('текст согласия на обработку данных зависит от церкви', $catalog);
        $test->assertStringNotContains('AND g.church_id = ? AND g.is_active = 1 LIMIT 1', $catalog);
    },

    'api exposes optional church filter and all-churches scope' => function (TestCase $test): void {
        $catalog = file_get_contents(__DIR__ . '/../../src/HomeGroupCatalog.php');

        $test->assertStringContains('homegroup_catalog_api_church_from_request', $catalog);
        $test->assertStringContains("\$_GET['church']", $catalog);
        $test->assertStringContains("\$_GET['church_id']", $catalog);
        $test->assertStringContains("'label' => 'all'", $catalog);
    },

    'crm map redirect and public links use church-scoped catalog path' => function (TestCase $test): void {
        $features = file_get_contents(__DIR__ . '/../../src/Features.php');
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');

        $test->assertStringContains('public_homegroup_catalog_path(current_church())', $features);
        $test->assertStringContains('public_homegroup_catalog_path(current_church())', $account);
        $test->assertStringContains('public_homegroup_catalog_path(current_church())', $index);
        $test->assertStringContains('Карта групп церкви', $account);
        $test->assertStringContains('Все церкви', $account);
    },

    'map balloon shows church label on platform-wide catalog' => function (TestCase $test): void {
        $js = file_get_contents(__DIR__ . '/../../public/assets/app.js');
        $css = file_get_contents(__DIR__ . '/../../public/assets/app.css');

        $test->assertStringContains('data-show-church', $js);
        $test->assertStringContains('properties.churchLabel', $js);
        $test->assertStringContains('homegroup-balloon__church', $js);
        $test->assertStringContains('.homegroup-card__church', $css);
        $test->assertStringContains('.homegroup-balloon__church', $css);
    },

    'system guide documents platform and slug catalogs' => function (TestCase $test): void {
        $guide = file_get_contents(__DIR__ . '/../../src/SystemGuide.php');
        $docs = file_get_contents(__DIR__ . '/../../docs/shared-hosting.md');

        $test->assertStringContains('/{slug}/homegroup', $guide);
        $test->assertStringContains('группы всех церквей', $guide);
        $test->assertStringContains('/{slug}/homegroup', $docs);
        $test->assertStringContains('группы всех церквей', $docs);
    },
];
