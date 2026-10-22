<?php

declare(strict_types=1);

use function ChurchCare\accessible_group_filter;
use function ChurchCare\accessible_person_filter;

require_once __DIR__ . '/../../src/ChurchTenant.php';

return [
    'pastor is scoped to all groups in the current church' => function (TestCase $test): void {
        [$sql, $params] = accessible_group_filter(['role' => 'pastor', 'church_id' => 7], 'hg');

        $test->assertSame('hg.church_id = ?', $sql);
        $test->assertSame([7], $params);
    },
    'church admin is scoped to own church_id' => function (TestCase $test): void {
        [$sql, $params] = accessible_group_filter(['role' => 'admin', 'church_id' => 4], 'g');

        $test->assertSame('g.church_id = ?', $sql);
        $test->assertSame([4], $params);
    },
    'super_admin without tenant sees all groups' => function (TestCase $test): void {
        if (function_exists('set_current_church')) {
            set_current_church(null);
        }
        [$sql, $params] = accessible_group_filter(['role' => 'super_admin', 'church_id' => 1], 'g');

        $test->assertSame('1=1', $sql);
        $test->assertSame([], $params);
    },
    'super_admin in tenant is scoped to current church' => function (TestCase $test): void {
        if (!function_exists('set_current_church')) {
            require_once __DIR__ . '/../../src/ChurchTenant.php';
        }
        set_current_church(['id' => 11, 'slug' => 'demo']);
        [$sql, $params] = accessible_group_filter(['role' => 'super_admin', 'church_id' => 1], 'g');

        $test->assertSame('g.church_id = ?', $sql);
        $test->assertSame([11], $params);
        set_current_church(null);
    },
    'pastor in URL tenant is scoped to that church not home membership' => function (TestCase $test): void {
        set_current_church(['id' => 9, 'slug' => 'church-3']);
        [$sql, $params] = accessible_group_filter(['role' => 'pastor', 'church_id' => 7], 'g');
        $test->assertSame('g.church_id = ?', $sql);
        $test->assertSame([9], $params);
        set_current_church(null);
    },
    'district leader without church districts stays church-wide' => function (TestCase $test): void {
        [$sql, $params] = accessible_group_filter([
            'role' => 'district_leader',
            'church_id' => 3,
            'id' => 9,
            '_church_has_districts' => false,
        ], 'g');

        $test->assertSame('g.church_id = ?', $sql);
        $test->assertSame([3], $params);
    },
    'district leader with districts is scoped to assigned district_ids' => function (TestCase $test): void {
        [$sql, $params] = accessible_group_filter([
            'role' => 'district_leader',
            'church_id' => 3,
            'id' => 9,
            '_church_has_districts' => true,
            '_district_ids' => [10, 12],
        ], 'g');

        $test->assertSame('g.church_id = ? AND g.district_id IN (?,?)', $sql);
        $test->assertSame([3, 10, 12], $params);
    },
    'district leader with districts but no assignment sees nothing' => function (TestCase $test): void {
        [$sql, $params] = accessible_group_filter([
            'role' => 'district_leader',
            'church_id' => 3,
            'id' => 9,
            '_church_has_districts' => true,
            '_district_ids' => [],
        ], 'g');

        $test->assertSame('1=0', $sql);
        $test->assertSame([], $params);
    },
    'leader and assistant are scoped to one home group' => function (TestCase $test): void {
        [$sql, $params] = accessible_group_filter(['role' => 'assistant', 'group_id' => 12], 'g');

        $test->assertSame('g.id = ?', $sql);
        $test->assertSame([12], $params);
    },
    'member can only see own not deleted person card' => function (TestCase $test): void {
        [$sql, $params] = accessible_person_filter(['role' => 'member', 'id' => 44], 'p');

        $test->assertSame('p.user_id = ? AND p.deleted_at IS NULL', $sql);
        $test->assertSame([44], $params);
    },
    'pastoral roles see people through home group scope' => function (TestCase $test): void {
        [$sql, $params] = accessible_person_filter(['role' => 'admin', 'church_id' => 2], 'person');

        $test->assertStringContains('person.group_id IN (SELECT g.id FROM home_groups g WHERE g.church_id = ?', $sql);
        $test->assertSame([2], $params);
    },
];
