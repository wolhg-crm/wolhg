<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/AppNav.php';

/**
 * @return array{id:int,role:string,church_id:int,group_id:?int}
 */
function app_nav_test_user(string $role): array
{
    return [
        'id' => 1,
        'role' => $role,
        'church_id' => 1,
        'group_id' => $role === 'member' || $role === 'assistant' || $role === 'leader' ? 4 : null,
    ];
}

/**
 * @param list<string> $labels
 */
function app_nav_test_has_label(array $labels, string $label): bool
{
    return in_array($label, $labels, true);
}

return [
    'member shell menu is home only without miracles or care' => function (TestCase $test): void {
        $labels = app_sidebar_visible_labels(app_nav_test_user('member'));

        $test->assertSame(['Главная'], $labels);
        $test->assertFalse(app_nav_test_has_label($labels, 'Чудеса'));
        $test->assertFalse(app_nav_test_has_label($labels, 'Сопровождение'));
    },

    'minister shell menu includes care hub and never lists miracles' => function (TestCase $test): void {
        foreach (['assistant', 'leader', 'district_leader', 'pastor', 'admin', 'super_admin'] as $role) {
            $labels = app_sidebar_visible_labels(app_nav_test_user($role));

            $test->assertTrue(app_nav_test_has_label($labels, 'Главная'), $role);
            $test->assertTrue(app_nav_test_has_label($labels, 'Сопровождение'), $role);
            $test->assertTrue(app_nav_test_has_label($labels, 'Группы'), $role);
            $test->assertTrue(app_nav_test_has_label($labels, 'Аналитика'), $role);
            $test->assertTrue(app_nav_test_has_label($labels, 'Обучение'), $role);
            $test->assertFalse(app_nav_test_has_label($labels, 'Чудеса'), $role . ' must not see Miracles in shell nav');
        }
    },

    'sidebar catalog has no miracles path for any role' => function (TestCase $test): void {
        foreach (['member', 'leader', 'pastor', 'admin', 'super_admin'] as $role) {
            foreach (app_sidebar_nav_catalog(app_nav_test_user($role)) as $item) {
                $test->assertFalse($item['path'] === '/miracles', $role . ' catalog must not include /miracles');
                $test->assertFalse($item['label'] === 'Чудеса', $role . ' catalog must not include Чудеса');
            }
        }
    },

    'app_sidebar_nav projects catalog into href items without miracles' => function (TestCase $test): void {
        // Prefer catalog-level checks: full app_path() needs app_config from bootstrap.
        $catalog = app_sidebar_nav_catalog(app_nav_test_user('leader'));
        $paths = array_column($catalog, 'path');
        $test->assertTrue(in_array('/care', $paths, true));
        $test->assertFalse(in_array('/miracles', $paths, true));

        if (function_exists('app_path') && !function_exists('app_config')) {
            // ChurchTenant may already be loaded by another suite file; skip href projection.
            return;
        }

        $items = app_sidebar_nav(app_nav_test_user('leader'));
        $visibleLabels = [];
        foreach ($items as $item) {
            if ($item['visible']) {
                $visibleLabels[] = $item['label'];
                if ($item['label'] === 'Сопровождение') {
                    $test->assertTrue(
                        $item['href'] === '/care' || str_ends_with($item['href'], '/care'),
                        'care href should end with /care'
                    );
                }
            }
        }
        $test->assertTrue(in_array('Сопровождение', $visibleLabels, true));
        $test->assertFalse(in_array('Чудеса', $visibleLabels, true));
    },

    'care hub remains the in-app entry to miracles' => function (TestCase $test): void {
        $account = (string) file_get_contents(__DIR__ . '/../../src/Account.php');
        $index = (string) file_get_contents(__DIR__ . '/../../public/index.php');
        $nav = (string) file_get_contents(__DIR__ . '/../../src/AppNav.php');

        $test->assertStringContains("function care_hub_page", $account);
        $test->assertStringContains("app_path('/miracles')", $account);
        $test->assertStringContains('<h2>Чудеса</h2>', $account);
        $test->assertStringContains("'/care' => care_hub_page(\$user)", $index);
        $test->assertStringContains("'/miracles' => miracles_page(\$user)", $index);
        $test->assertStringNotContains("'/miracles'", $nav);
        $test->assertStringNotContains("'Чудеса'", $nav);
    },
];
