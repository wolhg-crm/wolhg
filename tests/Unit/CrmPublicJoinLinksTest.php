<?php

declare(strict_types=1);

/**
 * CRM "public join" buttons must open the church-scoped join form.
 */

if (!function_exists('app_config')) {
    function app_config(?string $key = null, mixed $default = null): mixed
    {
        $config = ['legacy_default_slug' => 'pushkino'];
        if ($key === null) {
            return $config;
        }

        return $config[$key] ?? $default;
    }
}

require_once __DIR__ . '/../../src/ChurchTenant.php';
require_once __DIR__ . '/../../src/HomeGroupCatalog.php';

return [
    'crm_public_group_join_url prefers current church slug' => function (TestCase $test): void {
        set_current_church(['id' => 2, 'slug' => 'korolev', 'deleted_at' => null]);
        $test->assertSame('/korolev/homegroup/join?group=15', crm_public_group_join_url(15));
        $test->assertSame('/korolev/homegroup/join', crm_public_group_join_url(0));
        set_current_church(['id' => 1, 'slug' => 'pushkino', 'deleted_at' => null]);
        $test->assertSame('/pushkino/homegroup/join?group=3', crm_public_group_join_url(3));
        set_current_church(null);
        $test->assertSame('/homegroup/join?group=3', crm_public_group_join_url(3));
    },

    'groups list and group page use tenant join URLs' => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        $catalog = file_get_contents(__DIR__ . '/../../src/HomeGroupCatalog.php');

        $test->assertStringContains('function crm_public_group_join_url', $catalog);
        $test->assertStringContains('crm_public_group_join_url((int) $group[\'id\'])', $index);
        $test->assertStringContains('crm_public_group_join_url($id)', $index);
        $test->assertStringNotContains('href="/homegroup/join?group=', $index);
        $test->assertStringContains('crm_public_group_join_url(0', $account);
        $test->assertStringNotContains('href="/homegroup/join"', $account);
    },
];
