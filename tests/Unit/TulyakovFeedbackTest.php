<?php

declare(strict_types=1);

/**
 * Regression coverage for ClickUp tasks from Alexander Tulyakov feedback.
 */

require_once __DIR__ . '/../../src/Features.php';

return [
    'TC: dashboard badge uses COUNT not LIMIT length' => function (TestCase $test): void {
        $care = (string) file_get_contents(__DIR__ . '/../../src/Care.php');
        $index = (string) file_get_contents(__DIR__ . '/../../public/index.php');

        $test->assertStringContains('function open_leader_tasks_for_user', $care);
        $test->assertStringContains('function open_leader_tasks_count_for_user', $care);
        $test->assertStringContains('SELECT COUNT(*) AS c', $care);
        $test->assertStringContains('open_leader_tasks_for_user($user, 8)', $index);
        $test->assertStringContains('open_leader_tasks_count_for_user($user)', $index);
        $test->assertStringContains('(int) $tasksTotal', $index);
        $test->assertFalse(str_contains($index, "count(\$tasks) . '</span></div>'"));
    },

    'TC: person attendance block shows first_visit_date' => function (TestCase $test): void {
        $index = (string) file_get_contents(__DIR__ . '/../../public/index.php');
        $test->assertStringContains('Первое посещение</span></div>', $index);
        $test->assertStringContains("\$person['first_visit_date']", $index);
        $test->assertStringContains('firstVisitDate === \'\' && !$attendance', $index);
    },

    'TC: group page can attach existing church person without duplicate' => function (TestCase $test): void {
        $index = (string) file_get_contents(__DIR__ . '/../../public/index.php');
        $account = (string) file_get_contents(__DIR__ . '/../../src/Account.php');
        $tenant = (string) file_get_contents(__DIR__ . '/../../src/ChurchTenant.php');

        $test->assertStringContains('function church_people_attach_candidates', $account);
        $test->assertStringContains('/person-group-attach', $index);
        $test->assertStringContains('Добавить из базы церкви', $index);
        $test->assertStringContains('person-group-attach', $tenant);
        $test->assertStringContains("person_add_group_membership(\$personId, (int) \$group['id'], false)", $index);
    },

    'TC: role-invite ensures pastoral people.user_id link' => function (TestCase $test): void {
        $account = (string) file_get_contents(__DIR__ . '/../../src/Account.php');
        $test->assertStringContains('function ensure_pastoral_person_for_user', $account);
        $test->assertTrue(substr_count($account, 'ensure_pastoral_person_for_user(') >= 3);
        $test->assertStringContains('INSERT INTO people (user_id, group_id, full_name, email, first_visit_date, training_stage)', $account);
    },

    'TC: meeting report notifies pastor/admin when leader missing' => function (TestCase $test): void {
        $account = (string) file_get_contents(__DIR__ . '/../../src/Account.php');
        $features = (string) file_get_contents(__DIR__ . '/../../src/Features.php');
        $test->assertStringContains('function meeting_report_notify_user_ids', $account);
        $test->assertStringContains('role IN ("pastor", "admin", "super_admin")', $account);
        $test->assertStringContains("meeting_report_notify_user_ids((int) (\$meeting['group_id'] ?? 0))", $features);
        $test->assertFalse(str_contains(
            $features,
            'JOIN people p ON p.id = g.leader_person_id WHERE u.id = p.user_id LIMIT 1'
        ));
    },

    'TC: attendance_taker permissions and field visibility' => function (TestCase $test): void {
        $bootstrap = (string) file_get_contents(__DIR__ . '/../../src/bootstrap.php');
        $account = (string) file_get_contents(__DIR__ . '/../../src/Account.php');
        $care = (string) file_get_contents(__DIR__ . '/../../src/Care.php');
        $taker = ['role' => 'attendance_taker', 'id' => 1, 'group_id' => 5];

        $test->assertTrue(in_array('attendance_taker', roles(), true));
        $test->assertTrue(default_permission('attendance_taker', 'mark_attendance'));
        $test->assertFalse(default_permission('attendance_taker', 'edit_people'));
        $test->assertTrue(can_view_person_field($taker, 'full_name'));
        $test->assertFalse(can_view_person_field($taker, 'sensitive_notes'));
        $test->assertFalse(can_view_person_field($taker, 'notes'));
        $test->assertStringContains("'attendance_taker' => 'Служитель учёта посещаемости'", $bootstrap);
        $test->assertStringContains('function can_mark_attendance', $bootstrap);
        $test->assertStringContains("(\$user['role'] ?? '') === 'attendance_taker'", $bootstrap);
        $test->assertStringContains("['attendance_taker', 'assistant', 'leader']", $account);
        $test->assertStringContains('can_mark_attendance($user)', $care);
        $test->assertFalse(str_contains($care, 'if (!can_edit_group_data($user)) {' . "\n" . '        throw new RuntimeException(\'Недостаточно прав для отметки посещаемости\')'));
    },

    'TC: church service flag and migration are wired' => function (TestCase $test): void {
        $migration = __DIR__ . '/../../database/migrations/046_church_service_and_attendance_taker.sql';
        $schema = (string) file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');
        $migrate = (string) file_get_contents(__DIR__ . '/../../src/Migrate.php');
        $index = (string) file_get_contents(__DIR__ . '/../../public/index.php');

        $test->assertFileExists($migration);
        $test->assertStringContains('is_church_service', $schema);
        $test->assertStringContains('attendance_taker', $schema);
        $test->assertStringContains('migrate_apply_church_service_and_attendance_taker', $migrate);
        $test->assertStringContains('046_church_service_and_attendance_taker.sql', $migrate);
        $test->assertStringContains('is_church_service', $index);
        $test->assertStringContains('Воскресное собрание', $index);
    },
];
