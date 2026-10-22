<?php

declare(strict_types=1);

use function ChurchCare\care_gap_task;
use function ChurchCare\effective_last_attendance;
use function ChurchCare\effective_last_contact;
use function ChurchCare\needs_radar_notification;
use function ChurchCare\newcomer_followup_tasks;

return [
    'newcomer flow creates due contacts within the planning horizon' => function (TestCase $test): void {
        $tasks = newcomer_followup_tasks(5, 1, 2, 'Ольга Соколова', '2026-07-01', '2026-07-03');

        $test->assertSame(2, count($tasks));
        $test->assertSame('Позвонить новичку Ольга Соколова', $tasks[0]['title']);
        $test->assertSame('2026-07-03', $tasks[0]['due_date']);
        $test->assertSame('Пригласить новичка снова: Ольга Соколова', $tasks[1]['title']);
        $test->assertSame('newcomer', $tasks[1]['type']);
    },
    'care gap task is not created for active contacted person' => function (TestCase $test): void {
        $task = care_gap_task('2026-07-10', '2026-07-12', 3, 1, 2, 'Иван Кузнецов', '2026-07-15');

        $test->assertNull($task);
    },
    'care gap task is created when contact is stale even if attendance is recent' => function (TestCase $test): void {
        $task = care_gap_task('2026-07-12', '2026-06-01', 3, 1, 2, 'Иван Кузнецов', '2026-07-15');

        $test->assertSame('absence', $task['type']);
        $test->assertSame('Связаться: Иван Кузнецов выпадает из общения', $task['title']);
        $test->assertSame('2026-07-15', $task['due_date']);
    },
    'ensure_automations equivalent: recent first visit without meetings skips absence and radar' => function (TestCase $test): void {
        // Mirrors ensure_automations() after wiring effective_* helpers.
        $firstVisit = '2026-10-18';
        $today = '2026-10-19';
        $lastAttendance = effective_last_attendance(null, $firstVisit);
        $lastContact = effective_last_contact(null, $firstVisit);

        $test->assertNull(care_gap_task($lastAttendance, $lastContact, 7, 2, 3, 'Максим Садаков', $today));
        $test->assertFalse(needs_radar_notification($lastAttendance, $today));

        $newcomerTasks = newcomer_followup_tasks(7, 2, 3, 'Максим Садаков', $firstVisit, $today);
        $test->assertSame(2, count($newcomerTasks));
        $test->assertSame('2026-10-20', $newcomerTasks[0]['due_date']);
        $test->assertSame('2026-10-25', $newcomerTasks[1]['due_date']);
    },
];
