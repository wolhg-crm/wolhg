<?php

declare(strict_types=1);

/**
 * Guards call-site wiring so first_visit_date cannot be dropped from care radar again.
 */
return [
    'care_people enriches status via effective first-visit anchors' => function (TestCase $test): void {
        $care = file_get_contents(__DIR__ . '/../../src/Care.php');

        $test->assertStringContains('effective_last_attendance', $care);
        $test->assertStringContains('effective_last_contact', $care);
        $test->assertStringContains('first_visit_date', $care);
        $test->assertStringContains('care_status($lastAttendance, $lastContact)', $care);
    },

    'person card uses the same first-visit anchors as group lists' => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . '/../../public/index.php');

        $test->assertStringContains('ChurchCare\\effective_last_attendance', $index);
        $test->assertStringContains('ChurchCare\\effective_last_contact', $index);
        $test->assertStringContains('care_status($lastAttendance, $lastContact)', $index);
    },

    'ensure_automations reuses domain planners instead of duplicating thresholds' => function (TestCase $test): void {
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');

        $test->assertStringContains('ChurchCare\\newcomer_followup_tasks', $bootstrap);
        $test->assertStringContains('ChurchCare\\care_gap_task', $bootstrap);
        $test->assertStringContains('ChurchCare\\needs_radar_notification', $bootstrap);
        $test->assertStringContains('ChurchCare\\effective_last_attendance', $bootstrap);
        $test->assertStringContains('ChurchCare\\effective_last_contact', $bootstrap);
        $test->assertStringNotContains('attendanceDays > 42', $bootstrap);
        $test->assertStringNotContains('attendanceDays > 21', $bootstrap);
    },
];
