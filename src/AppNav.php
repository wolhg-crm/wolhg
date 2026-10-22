<?php

declare(strict_types=1);

/**
 * Signed-in shell navigation catalog.
 *
 * Visibility rules must stay aligned with pages the role can open.
 * «Чудеса» live under Сопровождение (/care), not in the top shell menu —
 * ministers reach them from the care hub; the /miracles route itself remains.
 *
 * @return list<array{path:string,label:string,visible:bool}>
 */
function app_sidebar_nav_catalog(array $user): array
{
    $role = (string) ($user['role'] ?? '');
    $minister = $role !== 'member';

    return [
        ['path' => '/', 'label' => 'Главная', 'visible' => true],
        ['path' => '/groups', 'label' => 'Группы', 'visible' => $minister],
        [
            'path' => '/districts',
            'label' => 'Округа',
            'visible' => function_exists('can_view_districts_page') && can_view_districts_page($user),
        ],
        ['path' => '/care', 'label' => 'Сопровождение', 'visible' => $minister],
        ['path' => '/analytics', 'label' => 'Аналитика', 'visible' => $minister],
        [
            'path' => '/users',
            'label' => 'Люди',
            'visible' => function_exists('can_manage_users') && can_manage_users($user),
        ],
        ['path' => '/knowledge', 'label' => 'Обучение', 'visible' => $minister],
        [
            'path' => '/settings',
            'label' => 'Настройки',
            'visible' => function_exists('has_settings_hub_items') && has_settings_hub_items($user),
        ],
    ];
}

/**
 * Sidebar entries for the signed-in shell (tenant-aware hrefs when app_path exists).
 *
 * @return list<array{href:string,label:string,visible:bool}>
 */
function app_sidebar_nav(array $user): array
{
    $items = [];
    foreach (app_sidebar_nav_catalog($user) as $item) {
        $path = $item['path'];
        $items[] = [
            'href' => function_exists('app_path') ? app_path($path) : $path,
            'label' => $item['label'],
            'visible' => $item['visible'],
        ];
    }

    return $items;
}

/**
 * Labels of currently visible shell nav items (order preserved).
 *
 * @return list<string>
 */
function app_sidebar_visible_labels(array $user): array
{
    $labels = [];
    foreach (app_sidebar_nav_catalog($user) as $item) {
        if ($item['visible']) {
            $labels[] = $item['label'];
        }
    }

    return $labels;
}
