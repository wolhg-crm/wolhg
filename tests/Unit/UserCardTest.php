<?php

declare(strict_types=1);

if (!function_exists('app_config')) {
    function app_config(?string $key = null): mixed
    {
        $config = ['legacy_default_slug' => 'pushkino'];
        if ($key === null) {
            return $config;
        }
        return $config[$key] ?? null;
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

if (!function_exists("roles")) {
    function roles(): array
    {
        return ["member", "assistant", "leader", "district_leader", "pastor", "admin", "super_admin"];
    }
}

if (!function_exists('role_label')) {
    function role_label(string $role): string
    {
        return match ($role) {
            'super_admin' => 'Суперадминистратор',
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
    'user card href for other user stays on /user' => function (TestCase $test): void {
        $viewer = ['id' => 1, 'role' => 'admin'];
        $test->assertSame('/pushkino/user?id=42', user_card_href(42));
        $test->assertSame('/pushkino/user?id=42', user_card_href(42, $viewer));
    },

    'user card href for own account points to unified profile' => function (TestCase $test): void {
        $viewer = ['id' => 42, 'role' => 'leader'];
        $test->assertSame('/pushkino/profile', user_card_href(42, $viewer));
    },

    'user can always view own record' => function (TestCase $test): void {
        $viewer = ['id' => 5, 'role' => 'member', 'church_id' => 1, 'group_id' => 2];
        $test->assertTrue(can_view_user_record($viewer, $viewer));
    },

    'church admin cannot edit users from another church' => function (TestCase $test): void {
        $admin = ['id' => 1, 'role' => 'admin', 'church_id' => 1, 'group_id' => null];
        $sameChurch = ['id' => 8, 'role' => 'pastor', 'church_id' => 1, 'group_id' => null];
        $otherChurch = ['id' => 9, 'role' => 'pastor', 'church_id' => 2, 'group_id' => 4];

        $test->assertTrue(can_edit_user_record($admin, $sameChurch));
        $test->assertFalse(can_edit_user_record($admin, $otherChurch));
    },

    'super_admin can edit users across churches' => function (TestCase $test): void {
        $super = ['id' => 1, 'role' => 'super_admin', 'church_id' => 1, 'group_id' => null];
        $target = ['id' => 9, 'role' => 'pastor', 'church_id' => 2, 'group_id' => 4];

        $test->assertTrue(can_view_user_record($super, $target));
        $test->assertTrue(can_edit_user_record($super, $target));
    },

    'pastor can view and edit peer pastors and church admins' => function (TestCase $test): void {
        $pastor = ['id' => 2, 'role' => 'pastor', 'church_id' => 1, 'group_id' => null];
        $otherPastor = ['id' => 3, 'role' => 'pastor', 'church_id' => 1, 'group_id' => null];
        $admin = ['id' => 5, 'role' => 'admin', 'church_id' => 1, 'group_id' => null];
        $leader = ['id' => 4, 'role' => 'leader', 'church_id' => 1, 'group_id' => 7];
        $otherChurchAdmin = ['id' => 6, 'role' => 'admin', 'church_id' => 2, 'group_id' => null];
        $super = ['id' => 7, 'role' => 'super_admin', 'church_id' => 1, 'group_id' => null];

        $test->assertTrue(can_view_user_record($pastor, $otherPastor));
        $test->assertTrue(can_edit_user_record($pastor, $otherPastor));
        $test->assertTrue(can_edit_user_record($pastor, $admin));
        $test->assertTrue(can_view_user_record($pastor, $leader));
        $test->assertFalse(can_edit_user_record($pastor, $otherChurchAdmin));
        $test->assertFalse(can_edit_user_record($pastor, $super));
    },

    'leader is limited to assistants and members in own group' => function (TestCase $test): void {
        $leader = ['id' => 10, 'role' => 'leader', 'church_id' => 1, 'group_id' => 7];
        $assistant = ['id' => 11, 'role' => 'assistant', 'church_id' => 1, 'group_id' => 7];
        $otherLeader = ['id' => 12, 'role' => 'leader', 'church_id' => 1, 'group_id' => 7];
        $foreignMember = ['id' => 13, 'role' => 'member', 'church_id' => 1, 'group_id' => 8];

        $test->assertTrue(can_view_user_record($leader, $assistant));
        $test->assertFalse(can_view_user_record($leader, $otherLeader));
        $test->assertFalse(can_view_user_record($leader, $foreignMember));
    },

    'role invite pending requests tolerate missing storage guard' => function (TestCase $test): void {
        $pastor = ['id' => 4, 'role' => 'pastor', 'church_id' => 1, 'group_id' => null];

        $test->assertSame([], role_invite_pending_requests_for($pastor));
    },

    'participant requests link shows count badge only when needed' => function (TestCase $test): void {
        $empty = participant_requests_link(0);
        $withRequests = participant_requests_link(7);

        $test->assertStringContains('Заявки участников', $empty);
        $test->assertStringNotContains('status-badge pending', $empty);
        $test->assertStringContains('status-badge pending', $withRequests);
        $test->assertStringContains('>7</span>', $withRequests);
    },

    'role requests link points to dedicated inbox page' => function (TestCase $test): void {
        $empty = role_requests_link(0);
        $withRequests = role_requests_link(2);

        $test->assertStringContains('href="/pushkino/role-requests"', $empty);
        $test->assertStringContains('Заявки на роли', $empty);
        $test->assertStringNotContains('status-badge pending', $empty);
        $test->assertStringContains('status-badge pending', $withRequests);
        $test->assertStringContains('>2</span>', $withRequests);
    },

    'role registration links follow the requested leadership ladder' => function (TestCase $test): void {
        $member = ['id' => 1, 'role' => 'member', 'church_id' => 1, 'group_id' => 7];
        $leader = ['id' => 2, 'role' => 'leader', 'church_id' => 1, 'group_id' => 7];
        $regional = ['id' => 3, 'role' => 'district_leader', 'church_id' => 1, 'group_id' => null];
        $pastor = ['id' => 4, 'role' => 'pastor', 'church_id' => 1, 'group_id' => null];
        $admin = ['id' => 5, 'role' => 'admin', 'church_id' => 1, 'group_id' => null];
        $super = ['id' => 6, 'role' => 'super_admin', 'church_id' => 1, 'group_id' => null];

        $test->assertSame([], role_invite_roles_for_user($member));
        $test->assertSame(['assistant'], role_invite_roles_for_user($leader));
        $test->assertSame(['assistant', 'leader'], role_invite_roles_for_user($regional));
        $test->assertSame(['assistant', 'leader', 'district_leader', 'admin'], role_invite_roles_for_user($pastor));
        $test->assertSame(['assistant', 'leader', 'district_leader', 'pastor'], role_invite_roles_for_user($admin));
        $test->assertSame(['assistant', 'leader', 'district_leader', 'pastor', 'admin'], role_invite_roles_for_user($super));
        $test->assertTrue(role_invite_role_requires_group('leader'));
        $test->assertFalse(role_invite_role_requires_group('district_leader'));
        $test->assertFalse(role_invite_role_requires_group('admin'));
    },

    'pastor can approve administrator role invites in own church' => function (TestCase $test): void {
        $pastor = ['id' => 4, 'role' => 'pastor', 'church_id' => 1, 'group_id' => null];
        $otherPastor = ['id' => 8, 'role' => 'pastor', 'church_id' => 2, 'group_id' => null];
        $admin = ['id' => 5, 'role' => 'admin', 'church_id' => 1, 'group_id' => null];
        $super = ['id' => 6, 'role' => 'super_admin', 'church_id' => 1, 'group_id' => null];

        $adminRequest = [
            'status' => 'pending',
            'church_id' => 1,
            'requested_role' => 'admin',
        ];
        $districtRequest = [
            'status' => 'pending',
            'church_id' => 1,
            'requested_role' => 'district_leader',
        ];
        $pastorRequest = [
            'status' => 'pending',
            'church_id' => 1,
            'requested_role' => 'pastor',
        ];
        $foreignAdminRequest = [
            'status' => 'pending',
            'church_id' => 2,
            'requested_role' => 'admin',
        ];

        $test->assertTrue(can_approve_role_invite_request($pastor, $adminRequest));
        $test->assertTrue(can_approve_role_invite_request($pastor, $districtRequest));
        $test->assertFalse(can_approve_role_invite_request($pastor, $pastorRequest));
        $test->assertFalse(can_approve_role_invite_request($pastor, $foreignAdminRequest));
        $test->assertFalse(can_approve_role_invite_request($otherPastor, $adminRequest));
        $test->assertTrue(can_approve_role_invite_request($admin, $adminRequest));
        $test->assertFalse(can_approve_role_invite_request($admin, $foreignAdminRequest));
        $test->assertTrue(can_approve_role_invite_request($super, $foreignAdminRequest));
        $test->assertFalse(can_approve_role_invite_request($pastor, array_merge($adminRequest, ['status' => 'approved'])));
    },

    'user admin return path respects posted return target' => function (TestCase $test): void {
        set_current_church(['id' => 2, 'slug' => 'korolev', 'city' => 'Королёв', 'deleted_at' => null]);

        $_POST = ['return' => 'user'];
        $test->assertSame('/korolev/user?id=15', user_admin_return_path(['id' => 15]));

        $_POST = ['return' => 'admin'];
        $test->assertSame('/admin', user_admin_return_path(['id' => 15]));

        $_POST = ['return' => 'users'];
        $test->assertSame('/korolev/users?view=accounts', user_admin_return_path(['id' => 15]));

        $_POST = ['return' => 'accounts'];
        $test->assertSame('/korolev/users?view=accounts', user_admin_return_path(['id' => 15]));

        $_POST = ['return' => 'people'];
        $test->assertSame('/korolev/users', user_admin_return_path(['id' => 15]));

        $_POST = [];
        $test->assertSame('/korolev/users?view=accounts', user_admin_return_path(['id' => 15]));

        set_current_church(null);
        $_POST = [];
    },

    'account hero markup includes role login and 2fa status' => function (TestCase $test): void {
        ob_start();
        render_account_hero(
            [
                'name' => 'Иван Тестов',
                'login' => 'itestov',
                'role' => 'leader',
                'church_name' => 'Пушкино',
                'group_name' => 'Серебрянка',
                'two_factor_enabled' => 1,
            ],
            [['href' => '/person?id=9', 'label' => 'Пастырская карточка']],
            'Моя учетная запись'
        );
        $html = (string) ob_get_clean();

        $test->assertStringContains('account-hero', $html);
        $test->assertStringContains('Иван Тестов', $html);
        $test->assertStringContains('itestov', $html);
        $test->assertStringContains('2FA включена', $html);
        $test->assertStringContains('Пушкино · Серебрянка', $html);
        $test->assertStringContains('Моя учетная запись', $html);
        $test->assertStringContains('href="/person?id=9"', $html);
    },

    'account person panel distinguishes self-service copy' => function (TestCase $test): void {
        ob_start();
        render_account_person_panel(null, true);
        $emptySelf = (string) ob_get_clean();
        $test->assertStringContains('Пастырская карточка пока не привязана', $emptySelf);

        ob_start();
        render_account_person_panel(['id' => 3, 'full_name' => 'Анна', 'group_name' => 'Центр'], true);
        $linkedSelf = (string) ob_get_clean();
        $test->assertStringContains('Открыть мою карточку', $linkedSelf);
        $test->assertStringContains('/person?id=3', $linkedSelf);
    },
];
