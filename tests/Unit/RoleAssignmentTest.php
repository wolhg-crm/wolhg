<?php

declare(strict_types=1);

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
        return $role;
    }
}

return [
    'roles_assignable_by follows leadership ladder' => function (TestCase $test): void {
        $test->assertSame(['member', 'assistant'], roles_assignable_by('leader'));
        $test->assertSame(['member', 'assistant', 'leader'], roles_assignable_by('district_leader'));
        $test->assertSame(['member', 'assistant', 'leader', 'district_leader', 'pastor', 'admin'], roles_assignable_by('pastor'));
        $test->assertSame(['member', 'assistant', 'leader', 'district_leader', 'pastor', 'admin'], roles_assignable_by('admin'));
        $test->assertSame(['member', 'assistant', 'leader', 'district_leader', 'pastor', 'admin', 'super_admin'], roles_assignable_by('super_admin'));
        $test->assertSame([], roles_assignable_by('member'));
        $test->assertSame([], roles_assignable_by('assistant'));
    },

    'leader can only swap member and assistant' => function (TestCase $test): void {
        $test->assertTrue(can_transition_role('leader', 'member', 'assistant'));
        $test->assertTrue(can_transition_role('leader', 'assistant', 'member'));
        $test->assertTrue(can_transition_role('leader', 'member', 'member'));
        $test->assertFalse(can_transition_role('leader', 'member', 'leader'));
        $test->assertFalse(can_transition_role('leader', 'assistant', 'leader'));
        $test->assertSame(['member', 'assistant'], assignable_roles(['role' => 'leader'], 'member'));
        $test->assertSame(['member', 'assistant'], assignable_roles(['role' => 'leader'], 'assistant'));
    },

    'regional leader transition matrix matches product rules' => function (TestCase $test): void {
        $test->assertSame(['assistant', 'leader'], role_transition_sources('district_leader', 'member'));
        $test->assertSame(['member', 'leader'], role_transition_sources('district_leader', 'assistant'));
        $test->assertSame(['assistant', 'leader'], role_transition_sources('district_leader', 'leader'));

        $test->assertTrue(can_transition_role('district_leader', 'assistant', 'member'));
        $test->assertTrue(can_transition_role('district_leader', 'leader', 'member'));
        $test->assertTrue(can_transition_role('district_leader', 'member', 'assistant'));
        $test->assertTrue(can_transition_role('district_leader', 'leader', 'assistant'));
        $test->assertTrue(can_transition_role('district_leader', 'assistant', 'leader'));
        $test->assertTrue(can_transition_role('district_leader', 'leader', 'leader'));
        $test->assertFalse(can_transition_role('district_leader', 'member', 'leader'));
        $test->assertFalse(can_transition_role('district_leader', 'leader', 'district_leader'));

        $test->assertSame(['member', 'assistant'], assignable_roles(['role' => 'district_leader'], 'member'));
        $test->assertSame(['member', 'assistant', 'leader'], assignable_roles(['role' => 'district_leader'], 'assistant'));
        $test->assertSame(['member', 'assistant', 'leader'], assignable_roles(['role' => 'district_leader'], 'leader'));
    },

    'pastor can assign admin with one-step promotion from pastor' => function (TestCase $test): void {
        $test->assertTrue(can_transition_role('pastor', 'district_leader', 'pastor'));
        $test->assertTrue(can_transition_role('pastor', 'pastor', 'pastor'));
        $test->assertTrue(can_transition_role('pastor', 'pastor', 'admin'));
        $test->assertTrue(can_transition_role('pastor', 'admin', 'admin'));
        $test->assertFalse(can_transition_role('pastor', 'member', 'pastor'));
        $test->assertFalse(can_transition_role('pastor', 'leader', 'pastor'));
        $test->assertFalse(can_transition_role('pastor', 'district_leader', 'admin'));
        $test->assertFalse(can_transition_role('pastor', 'member', 'admin'));
        $test->assertTrue(can_transition_role('pastor', 'assistant', 'leader'));
        $test->assertFalse(can_transition_role('pastor', 'member', 'leader'));
        $test->assertTrue(can_transition_role('pastor', 'pastor', 'member'));
        $test->assertTrue(can_transition_role('pastor', 'admin', 'pastor'));
        $test->assertTrue(can_transition_role('pastor', 'admin', 'member'));
        $test->assertFalse(can_transition_role('pastor', 'admin', 'super_admin'));
        $test->assertTrue(in_array('pastor', assignable_roles(['role' => 'pastor'], 'district_leader'), true));
        $test->assertTrue(in_array('admin', assignable_roles(['role' => 'pastor'], 'pastor'), true));
        $test->assertFalse(in_array('admin', assignable_roles(['role' => 'pastor'], 'district_leader'), true));
        $test->assertFalse(in_array('super_admin', assignable_roles(['role' => 'pastor'], 'admin'), true));
        $test->assertSame(['pastor', 'admin'], role_transition_sources('pastor', 'admin'));
    },

    'admin can assign church roles with ladder promotions' => function (TestCase $test): void {
        $test->assertTrue(can_transition_role('admin', 'pastor', 'admin'));
        $test->assertFalse(can_transition_role('admin', 'member', 'admin'));
        $test->assertTrue(can_transition_role('admin', 'admin', 'member'));
        $test->assertTrue(in_array('admin', assignable_roles(['role' => 'admin'], 'pastor'), true));
        $test->assertFalse(in_array('super_admin', assignable_roles(['role' => 'admin'], null), true));
        $test->assertSame(roles_assignable_by('admin'), assignable_roles(['role' => 'admin'], null));
    },

    'super_admin can assign platform roles' => function (TestCase $test): void {
        $test->assertTrue(in_array('super_admin', roles_assignable_by('super_admin'), true));
        $test->assertTrue(can_transition_role('super_admin', 'admin', 'super_admin'));
    },

    'people in group sql supports memberships when ready is mocked off' => function (TestCase $test): void {
        $sql = people_in_group_sql('p');
        $test->assertStringContains('p.group_id = ?', $sql);
    },
];
