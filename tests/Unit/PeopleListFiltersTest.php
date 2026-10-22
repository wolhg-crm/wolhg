<?php

declare(strict_types=1);

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

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

require_once __DIR__ . '/../../src/ChurchTenant.php';
require_once __DIR__ . '/../../src/Account.php';

if (!function_exists('roles')) {
    function roles(): array
    {
        return ['member', 'assistant', 'leader', 'district_leader', 'pastor', 'admin'];
    }
}

if (!function_exists('role_label')) {
    function role_label(string $role): string
    {
        return match ($role) {
            'admin' => 'Администратор',
            'pastor' => 'Пастор',
            'district_leader' => 'Окружной лидер',
            'leader' => 'Лидер',
            'assistant' => 'Помощник',
            default => 'Участник',
        };
    }
}

return [
    'parse filters keeps valid values and drops out-of-scope ids' => function (TestCase $test): void {
        $filters = parse_people_list_filters([
            'q' => '  Андрей  ',
            'group_id' => '2',
            'registered' => 'yes',
            'role' => 'leader',
            'spiritual_stage_id' => '5',
            'flag' => 'potential',
        ], [
            'group_ids' => [1, 2],
            'stage_ids' => [5, 6],
            'roles' => roles(),
        ]);

        $test->assertSame('Андрей', $filters['q']);
        $test->assertSame(2, $filters['group_id']);
        $test->assertSame('yes', $filters['registered']);
        $test->assertSame('leader', $filters['role']);
        $test->assertSame(5, $filters['spiritual_stage_id']);
        $test->assertSame('potential', $filters['flag']);

        $rejected = parse_people_list_filters([
            'group_id' => '9',
            'spiritual_stage_id' => '3',
            'role' => 'hacker',
            'registered' => 'maybe',
            'flag' => 'boss',
            'q' => '',
        ], [
            'group_ids' => [1, 2],
            'stage_ids' => [5, 6],
        ]);

        $test->assertSame(people_list_default_filters(), $rejected);
    },

    'normalize filters fills missing keys with safe defaults' => function (TestCase $test): void {
        $test->assertSame(people_list_default_filters(), people_list_normalize_filters(null));
        $test->assertSame('', people_list_normalize_filters(['registered' => null])['registered']);
        $test->assertSame(null, people_list_normalize_filters(['group_id' => 0])['group_id']);
        $test->assertSame(null, people_list_normalize_filters(['spiritual_stage_id' => ''])['spiritual_stage_id']);
        $test->assertFalse(people_list_filters_active(people_list_default_filters()));
        $test->assertTrue(people_list_filters_active(['q' => 'иван']));
    },

    'like pattern escapes mysql wildcards' => function (TestCase $test): void {
        $test->assertSame('%100\\%\\_done%', people_list_like_pattern('100%_done'));
        $test->assertSame('%a\\\\b%', people_list_like_pattern('a\\b'));
    },

    'people filter sql builds search registration role stage and flag clauses' => function (TestCase $test): void {
        [$sql, $params] = people_list_filter_sql([
            'q' => 'Мария',
            'group_id' => 1,
            'registered' => 'no',
            'role' => 'assistant',
            'spiritual_stage_id' => 2,
            'flag' => 'leader',
        ]);

        $test->assertStringContains('p.full_name LIKE ?', $sql);
        $test->assertStringContains('p.phone LIKE ?', $sql);
        $test->assertStringContains('p.email LIKE ?', $sql);
        $test->assertStringContains('p.telegram LIKE ?', $sql);
        $test->assertStringContains('p.address LIKE ?', $sql);
        $test->assertStringContains('u.login LIKE ?', $sql);
        $test->assertStringContains('p.group_id = ?', $sql);
        $test->assertStringContains('p.user_id IS NULL', $sql);
        $test->assertStringContains('u.role = ?', $sql);
        $test->assertStringContains('p.spiritual_stage_id = ?', $sql);
        $test->assertStringContains('p.is_leader = 1', $sql);
        $test->assertStringContains('ESCAPE', $sql);
        $test->assertTrue(str_starts_with($sql, ' AND '));
        $test->assertSame('%Мария%', $params[0]);
        if (person_group_memberships_ready()) {
            $test->assertStringContains('person_group_memberships', $sql);
            $test->assertSame(1, $params[8]);
            $test->assertSame(1, $params[9]);
            $test->assertSame('assistant', $params[10]);
            $test->assertSame(2, $params[11]);
        } else {
            $test->assertSame(1, $params[8]);
            $test->assertSame('assistant', $params[9]);
            $test->assertSame(2, $params[10]);
        }
    },

    'people filter sql accepts registered yes and potential flag' => function (TestCase $test): void {
        [$sql, $params] = people_list_filter_sql([
            'registered' => 'yes',
            'flag' => 'potential',
        ]);

        $test->assertStringContains('p.user_id IS NOT NULL', $sql);
        $test->assertStringContains('p.is_potential_leader = 1', $sql);
        $test->assertSame([], $params);
    },

    'people filter sql returns empty clause for defaults' => function (TestCase $test): void {
        foreach ([null, [], people_list_default_filters()] as $filters) {
            [$sql, $params] = people_list_filter_sql($filters ?? []);
            $test->assertSame('', $sql);
            $test->assertSame([], $params);
        }
    },

    'users filter sql uses shared text group and role filters only' => function (TestCase $test): void {
        [$sql, $params] = users_list_filter_sql([
            'q' => 'leader',
            'group_id' => 3,
            'role' => 'leader',
            'registered' => 'no',
            'spiritual_stage_id' => 4,
            'flag' => 'potential',
        ]);

        $test->assertStringContains('u.name LIKE ?', $sql);
        $test->assertStringContains('u.login LIKE ?', $sql);
        $test->assertStringContains('u.email LIKE ?', $sql);
        $test->assertStringContains('u.group_id = ?', $sql);
        $test->assertStringContains('u.role = ?', $sql);
        $test->assertStringNotContains('spiritual_stage', $sql);
        $test->assertStringNotContains('is_potential_leader', $sql);
        $test->assertStringNotContains('user_id IS', $sql);
        $test->assertSame(['%leader%', '%leader%', '%leader%', 3, 'leader'], $params);
    },

    'users filter sql returns empty clause without shared filters' => function (TestCase $test): void {
        [$sql, $params] = users_list_filter_sql([
            'registered' => 'yes',
            'spiritual_stage_id' => 1,
            'flag' => 'leader',
        ]);
        $test->assertSame('', $sql);
        $test->assertSame([], $params);
    },

    'count label uses russian plural forms' => function (TestCase $test): void {
        $test->assertSame('карточка', people_list_count_label(1, 'карточка', 'карточки', 'карточек'));
        $test->assertSame('карточки', people_list_count_label(2, 'карточка', 'карточки', 'карточек'));
        $test->assertSame('карточек', people_list_count_label(5, 'карточка', 'карточки', 'карточек'));
        $test->assertSame('карточек', people_list_count_label(11, 'карточка', 'карточки', 'карточек'));
        $test->assertSame('карточки', people_list_count_label(22, 'карточка', 'карточки', 'карточек'));
        $test->assertSame('пользователей', people_list_count_label(0, 'пользователь', 'пользователя', 'пользователей'));
    },

    'filters form markup exposes get controls and selected values' => function (TestCase $test): void {
        set_current_church(['id' => 1, 'slug' => 'pushkino', 'city' => 'Пушкино', 'deleted_at' => null]);
        ob_start();
        render_people_list_filters(
            [['id' => 1, 'name' => 'Группа А'], ['id' => 2, 'name' => 'Группа Б']],
            [['id' => 5, 'name' => 'Новичок']],
            [
                'q' => 'Иван',
                'group_id' => 2,
                'registered' => 'no',
                'role' => 'member',
                'spiritual_stage_id' => 5,
                'flag' => 'leader',
            ],
            3,
            'people'
        );
        $html = (string) ob_get_clean();

        $test->assertStringContains('data-people-filters', $html);
        $test->assertStringContains('method="get"', $html);
        $test->assertStringContains('action="/pushkino/users"', $html);
        $test->assertStringContains('name="q"', $html);
        $test->assertStringContains('value="Иван"', $html);
        $test->assertStringContains('name="group_id"', $html);
        $test->assertStringContains('option value="2" selected', $html);
        $test->assertStringContains('name="registered"', $html);
        $test->assertStringContains('option value="no" selected', $html);
        $test->assertStringContains('Нет учётки', $html);
        $test->assertStringContains('Роль в CRM', $html);
        $test->assertStringContains('Лидер / потенциал', $html);
        $test->assertStringContains('Ещё фильтры', $html);
        $test->assertStringContains('name="role"', $html);
        $test->assertStringContains('name="spiritual_stage_id"', $html);
        $test->assertStringContains('name="flag"', $html);
        $test->assertStringContains('Сбросить', $html);
        $test->assertStringContains('Найдено: 3 человека', $html);
        set_current_church(null);
    },

    'accounts filters markup keeps only shared controls' => function (TestCase $test): void {
        set_current_church(['id' => 1, 'slug' => 'pushkino', 'city' => 'Пушкино', 'deleted_at' => null]);
        ob_start();
        render_people_list_filters(
            [['id' => 1, 'name' => 'Группа А']],
            [['id' => 5, 'name' => 'Новичок']],
            [
                'q' => 'лидер',
                'group_id' => 1,
                'registered' => '',
                'role' => 'leader',
                'spiritual_stage_id' => null,
                'flag' => '',
            ],
            2,
            'accounts'
        );
        $html = (string) ob_get_clean();

        $test->assertStringContains('name="view" value="accounts"', $html);
        $test->assertStringContains('Роль в CRM', $html);
        $test->assertStringNotContains('name="registered"', $html);
        $test->assertStringNotContains('spiritual_stage_id', $html);
        $test->assertStringNotContains('name="flag"', $html);
        $test->assertStringContains('Найдено: 2 учётные записи', $html);
        $test->assertStringContains('action="/pushkino/users"', $html);
        set_current_church(null);
    },

    'people hub logical path preserves view and active filters' => function (TestCase $test): void {
        $test->assertSame('/users', people_hub_logical_path());
        $test->assertSame('/users?view=accounts', people_hub_logical_path([], 'accounts'));
        $test->assertSame(
            '/users?q=%D0%98%D0%B2%D0%B0%D0%BD&group_id=2&registered=no',
            people_hub_logical_path([
                'q' => 'Иван',
                'group_id' => 2,
                'registered' => 'no',
            ], 'people')
        );
        $test->assertSame(
            '/users?view=accounts&q=admin&role=leader',
            people_hub_logical_path([
                'q' => 'admin',
                'role' => 'leader',
                'registered' => 'no',
                'flag' => 'leader',
            ], 'accounts')
        );
    },

    'people hub url is tenant-prefixed for current church' => function (TestCase $test): void {
        set_current_church(['id' => 2, 'slug' => 'korolev', 'city' => 'Королёв', 'deleted_at' => null]);
        $test->assertSame('/korolev/users', people_hub_url());
        $test->assertSame('/korolev/users?view=accounts', people_hub_url([], 'accounts'));
        $test->assertSame('/korolev/users?view=accounts', people_accounts_hub_path());
        $test->assertSame(
            '/korolev/users?q=%D0%98%D0%B2%D0%B0%D0%BD&group_id=2&registered=no',
            people_hub_url([
                'q' => 'Иван',
                'group_id' => 2,
                'registered' => 'no',
            ], 'people')
        );
        $test->assertSame(
            '/korolev/users?view=accounts&q=admin&role=leader',
            people_hub_url([
                'q' => 'admin',
                'role' => 'leader',
                'registered' => 'no',
                'flag' => 'leader',
            ], 'accounts')
        );

        set_current_church(['id' => 1, 'slug' => 'pushkino', 'city' => 'Пушкино', 'deleted_at' => null]);
        $test->assertSame('/pushkino/users?view=accounts', people_accounts_hub_path());
        set_current_church(null);
    },

    'people hub view switch links stay on current church slug' => function (TestCase $test): void {
        set_current_church(['id' => 2, 'slug' => 'korolev', 'city' => 'Королёв', 'deleted_at' => null]);
        ob_start();
        render_people_hub_view_switch([
            'q' => 'Анна',
            'group_id' => 7,
            'registered' => '',
            'role' => '',
            'spiritual_stage_id' => null,
            'flag' => '',
        ], 'people');
        $html = (string) ob_get_clean();

        $test->assertStringContains('people-view-switch', $html);
        $test->assertStringContains('href="/korolev/users?q=%D0%90%D0%BD%D0%BD%D0%B0&amp;group_id=7"', $html);
        $test->assertStringContains('href="/korolev/users?view=accounts&amp;q=%D0%90%D0%BD%D0%BD%D0%B0&amp;group_id=7"', $html);
        $test->assertStringContains('>Учётные записи</a>', $html);
        $test->assertStringContains('>Люди</a>', $html);
        $test->assertStringNotContains('href="/users"', $html);
        $test->assertStringNotContains('href="/users?', $html);
        set_current_church(null);
    },
];
