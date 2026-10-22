<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Features.php';
require_once __DIR__ . '/../../src/Domain.php';
require_once __DIR__ . '/../../src/RolePreview.php';

return [
    'only admin can use role preview' => function (TestCase $test): void {
        $admin = ['id' => 1, 'role' => 'admin', 'church_id' => 1, 'group_id' => null];
        $pastor = ['id' => 2, 'role' => 'pastor', 'church_id' => 1, 'group_id' => null];

        $test->assertTrue(can_use_role_preview_for_user($admin));
        $test->assertFalse(can_use_role_preview_for_user($pastor));
    },

    'preview role must be one of known roles' => function (TestCase $test): void {
        $test->assertTrue(is_valid_preview_role('leader'));
        $test->assertTrue(is_valid_preview_role('member'));
        $test->assertFalse(is_valid_preview_role('superadmin'));
        $test->assertFalse(is_valid_preview_role(''));
    },

    'admin preview overlays role for effective user' => function (TestCase $test): void {
        $admin = ['id' => 1, 'role' => 'admin', 'church_id' => 3, 'group_id' => 9];

        $leader = user_with_preview_role($admin, 'leader');
        $test->assertSame('leader', $leader['role']);
        $test->assertSame(3, (int) $leader['church_id']);
        $test->assertSame(9, (int) $leader['group_id']);
    },

    'non-admin cannot apply preview overlay' => function (TestCase $test): void {
        $pastor = ['id' => 2, 'role' => 'pastor', 'church_id' => 1, 'group_id' => null];

        $test->assertSame($pastor, user_with_preview_role($pastor, 'member'));
    },

    'invalid preview role is ignored' => function (TestCase $test): void {
        $admin = ['id' => 1, 'role' => 'admin', 'church_id' => 1, 'group_id' => null];

        $test->assertSame($admin, user_with_preview_role($admin, 'unknown'));
    },

    'previewing the same role keeps user unchanged' => function (TestCase $test): void {
        $admin = ['id' => 1, 'role' => 'admin', 'church_id' => 1, 'group_id' => null];

        $test->assertSame($admin, user_with_preview_role($admin, 'admin'));
    },

    'previewing own role is not treated as active preview' => function (TestCase $test): void {
        $_SESSION = ['role_preview' => 'admin'];
        $admin = ['id' => 1, 'role' => 'admin', 'church_id' => 1, 'group_id' => null];

        $test->assertNull(active_role_preview($admin));
    },

    'preview changes data scope for pastoral roles' => function (TestCase $test): void {
        $admin = ['id' => 1, 'role' => 'admin', 'church_id' => 7, 'group_id' => 12];
        $leader = user_with_preview_role($admin, 'leader');

        [$adminSql] = ChurchCare\accessible_group_filter($admin, 'g');
        [$leaderSql] = ChurchCare\accessible_group_filter($leader, 'g');

        $test->assertSame('g.church_id = ?', $adminSql);
        $test->assertSame('g.id = ?', $leaderSql);
    },

    'preview member limits person scope to own card' => function (TestCase $test): void {
        $admin = ['id' => 44, 'role' => 'admin', 'church_id' => 2, 'group_id' => null];
        $member = user_with_preview_role($admin, 'member');

        [$sql, $params] = ChurchCare\accessible_person_filter($member, 'p');

        $test->assertSame('p.user_id = ? AND p.deleted_at IS NULL', $sql);
        $test->assertSame([44], $params);
    },

    'mutation guard allows only control endpoints' => function (TestCase $test): void {
        $allowed = role_preview_mutation_allowed_paths();

        $test->assertTrue(in_array('/logout', $allowed, true));
        $test->assertTrue(in_array('/role-preview-start', $allowed, true));
        $test->assertTrue(in_array('/role-preview-stop', $allowed, true));
        $test->assertFalse(in_array('/person-update', $allowed, true));
    },
];
