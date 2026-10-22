<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Account.php';

return [
    'membership helpers expose API and group sql' => function (TestCase $test): void {
        $test->assertTrue(function_exists('person_add_group_membership'));
        $test->assertTrue(function_exists('person_remove_group_membership'));
        $test->assertTrue(function_exists('person_sync_primary_group'));
        $test->assertTrue(function_exists('person_belongs_to_group'));
        $test->assertSame([], person_membership_group_ids(0));
        $test->assertFalse(person_belongs_to_group(0, 1));

        $sql = people_in_group_sql('p');
        $test->assertStringContains('p.group_id = ?', $sql);
        if (person_group_memberships_ready()) {
            $test->assertStringContains('person_group_memberships', $sql);
        } else {
            $test->assertStringNotContains('person_group_memberships', $sql);
        }
    },

    'people filter sql group clause matches membership readiness' => function (TestCase $test): void {
        [$sql, $params] = people_list_filter_sql(['group_id' => 7]);
        $test->assertStringContains('p.group_id = ?', $sql);
        if (person_group_memberships_ready()) {
            $test->assertStringContains('person_group_memberships', $sql);
            $test->assertSame([7, 7], $params);
        } else {
            $test->assertSame([7], $params);
        }
    },
];
