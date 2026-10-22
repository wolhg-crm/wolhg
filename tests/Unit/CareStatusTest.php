<?php

declare(strict_types=1);

use function ChurchCare\care_gap_task;
use function ChurchCare\care_status;
use function ChurchCare\effective_last_attendance;
use function ChurchCare\effective_last_contact;
use function ChurchCare\latest_care_date;
use function ChurchCare\needs_radar_notification;

return [
    'care status marks a person present during this week' => function (TestCase $test): void {
        [$level, $label, $attendanceDays, $contactDays] = care_status('2026-07-08', '2026-07-10', '2026-07-15');

        $test->assertSame('green', $level);
        $test->assertSame('Был на этой неделе', $label);
        $test->assertSame(7, $attendanceDays);
        $test->assertSame(5, $contactDays);
    },
    'care status escalates two week absence to yellow' => function (TestCase $test): void {
        [$level, $label] = care_status('2026-07-01', '2026-07-10', '2026-07-15');

        $test->assertSame('yellow', $level);
        $test->assertSame('Не был 2 недели', $label);
    },
    'care status escalates month absence to orange' => function (TestCase $test): void {
        [$level, $label] = care_status('2026-06-10', '2026-07-10', '2026-07-15');

        $test->assertSame('orange', $level);
        $test->assertSame('Не был месяц', $label);
    },
    'care status escalates six week absence to red' => function (TestCase $test): void {
        [$level, $label] = care_status('2026-05-30', '2026-07-10', '2026-07-15');

        $test->assertSame('red', $level);
        $test->assertSame('Не был больше 6 недель', $label);
    },
    'lack of contact escalates otherwise healthy attendance to orange' => function (TestCase $test): void {
        [$level, $label] = care_status('2026-07-14', '2026-06-01', '2026-07-15');

        $test->assertSame('orange', $level);
        $test->assertSame('Был на этой неделе, нет контакта больше месяца', $label);
    },
    'radar notification is only needed after more than six weeks away' => function (TestCase $test): void {
        $test->assertFalse(needs_radar_notification('2026-06-03', '2026-07-15'));
        $test->assertTrue(needs_radar_notification('2026-06-02', '2026-07-15'));
    },

    // --- first_visit_date anchors (Korolev church import regression) ---

    'latest_care_date picks the newest parseable date' => function (TestCase $test): void {
        $test->assertNull(latest_care_date(null, '', '   '));
        $test->assertSame('2026-10-18', latest_care_date(null, '2026-10-18'));
        $test->assertSame('2026-10-19', latest_care_date('2026-10-18', '2026-10-19'));
        $test->assertSame('2026-10-19', latest_care_date('2026-10-19', 'not-a-date', null));
    },
    'effective_last_attendance falls back to first visit when no meeting marks exist' => function (TestCase $test): void {
        $test->assertSame('2026-10-18', effective_last_attendance(null, '2026-10-18'));
        $test->assertSame('2026-10-18', effective_last_attendance('', '2026-10-18'));
        $test->assertNull(effective_last_attendance(null, null));
    },
    'effective_last_attendance prefers a newer meeting mark over first visit' => function (TestCase $test): void {
        $test->assertSame('2026-10-25', effective_last_attendance('2026-10-25', '2026-10-18'));
        $test->assertSame('2026-10-18', effective_last_attendance('2026-09-01', '2026-10-18'));
    },
    'effective_last_contact falls back to first visit when no contact logs exist' => function (TestCase $test): void {
        $test->assertSame('2026-10-18', effective_last_contact(null, '2026-10-18'));
        $test->assertSame('2026-11-01', effective_last_contact('2026-11-01', '2026-10-18'));
    },

    'TC1: person added today with first visit yesterday is green without meeting attendance' => function (TestCase $test): void {
        // Production bug: 19.10.2026, first_visit=18.10.2026, no attendance rows → was red.
        $lastAttendance = effective_last_attendance(null, '2026-10-18');
        $lastContact = effective_last_contact(null, '2026-10-18');
        [$level, $label, $attendanceDays, $contactDays] = care_status($lastAttendance, $lastContact, '2026-10-19');

        $test->assertSame('2026-10-18', $lastAttendance);
        $test->assertSame('green', $level);
        $test->assertSame('Был на этой неделе', $label);
        $test->assertSame(1, $attendanceDays);
        $test->assertSame(1, $contactDays);
        $test->assertFalse(needs_radar_notification($lastAttendance, '2026-10-19'));
        $test->assertNull(care_gap_task($lastAttendance, $lastContact, 1, 1, 2, 'Анатолий Стетюха', '2026-10-19'));
    },
    'TC2: first visit exactly 7 days ago stays green' => function (TestCase $test): void {
        $last = effective_last_attendance(null, '2026-10-12');
        [$level] = care_status($last, effective_last_contact(null, '2026-10-12'), '2026-10-19');
        $test->assertSame('green', $level);
    },
    'TC3: first visit 8 days ago is yellow' => function (TestCase $test): void {
        $last = effective_last_attendance(null, '2026-10-11');
        [$level, $label] = care_status($last, effective_last_contact(null, '2026-10-11'), '2026-10-19');
        $test->assertSame('yellow', $level);
        $test->assertSame('Не был 2 недели', $label);
    },
    'TC4: first visit 15 days ago is orange month band' => function (TestCase $test): void {
        $last = effective_last_attendance(null, '2026-10-04');
        [$level, $label] = care_status($last, effective_last_contact(null, '2026-10-04'), '2026-10-19');
        $test->assertSame('orange', $level);
        $test->assertSame('Не был месяц', $label);
    },
    'TC5: first visit 43 days ago is red and needs radar' => function (TestCase $test): void {
        $last = effective_last_attendance(null, '2026-09-06');
        [$level, $label, $days] = care_status($last, effective_last_contact(null, '2026-09-06'), '2026-10-19');
        $test->assertSame(43, $days);
        $test->assertSame('red', $level);
        $test->assertSame('Не был больше 6 недель', $label);
        $test->assertTrue(needs_radar_notification($last, '2026-10-19'));
    },
    'TC6: old first visit plus recent meeting attendance is green' => function (TestCase $test): void {
        $last = effective_last_attendance('2026-10-18', '2025-01-01');
        [$level] = care_status($last, effective_last_contact('2026-10-18', '2025-01-01'), '2026-10-19');
        $test->assertSame('green', $level);
    },
    'TC7: recent first visit plus older meeting mark still uses first visit' => function (TestCase $test): void {
        $last = effective_last_attendance('2026-08-01', '2026-10-18');
        $contact = effective_last_contact(null, '2026-10-18');
        [$level, , $days] = care_status($last, $contact, '2026-10-19');
        $test->assertSame('2026-10-18', $last);
        $test->assertSame(1, $days);
        $test->assertSame('green', $level);
    },
    'TC8: null first visit and null attendance stay red (unknown history)' => function (TestCase $test): void {
        $last = effective_last_attendance(null, null);
        [$level, $label, $days] = care_status($last, null, '2026-10-19');
        $test->assertNull($last);
        $test->assertSame(999, $days);
        $test->assertSame('red', $level);
        $test->assertSame('Не был больше 6 недель', $label);
        $test->assertTrue(needs_radar_notification($last, '2026-10-19'));
    },
    'TC9: absence gap task is not opened for newcomer with only first visit yesterday' => function (TestCase $test): void {
        $lastAttendance = effective_last_attendance(null, '2026-10-18');
        $lastContact = effective_last_contact(null, '2026-10-18');
        $task = care_gap_task($lastAttendance, $lastContact, 10, 3, 4, 'Ирина Лашина', '2026-10-19');
        $test->assertNull($task);
    },
    'TC10: absence gap task still opens when first visit is older than 21 days and no contacts' => function (TestCase $test): void {
        $lastAttendance = effective_last_attendance(null, '2026-09-20');
        $lastContact = effective_last_contact(null, '2026-09-20');
        $task = care_gap_task($lastAttendance, $lastContact, 10, 3, 4, 'Иван Раевский', '2026-10-19');
        $test->assertNotNull($task);
        $test->assertSame('absence', $task['type']);
    },
];
