<?php

declare(strict_types=1);

use function ChurchCare\dashboard_metrics;
use function ChurchCare\prayer_status_label;
use function ChurchCare\prayer_status_labels;

return [
    'dashboard metrics count people and open prayers without join fan-out' => function (TestCase $test): void {
        // One group with 40 people and 3 open prayers would become 120 if people ⨝ prayers.
        $groups = [['id' => 1]];
        $people = [];
        for ($i = 1; $i <= 40; $i++) {
            $people[] = [
                'id' => $i,
                'group_id' => 1,
                'first_visit_date' => $i <= 5 ? '2026-07-20' : '2024-01-01',
            ];
        }
        $prayers = [
            ['id' => 1, 'group_id' => 1, 'status' => 'new'],
            ['id' => 2, 'group_id' => 1, 'status' => 'praying'],
            ['id' => 3, 'group_id' => 1, 'status' => 'praying'],
            ['id' => 4, 'group_id' => 1, 'status' => 'testimony'],
            ['id' => 5, 'group_id' => 1, 'status' => 'closed'],
        ];

        $metrics = dashboard_metrics($groups, $people, $prayers, '2026-08-02');

        $test->assertSame(1, $metrics['groups_count']);
        $test->assertSame(40, $metrics['people_count']);
        $test->assertSame(5, $metrics['newcomers']);
        $test->assertSame(3, $metrics['open_prayers']);
    },
    'dashboard metrics ignore people and prayers outside active groups' => function (TestCase $test): void {
        $metrics = dashboard_metrics(
            [['id' => 1]],
            [
                ['id' => 1, 'group_id' => 1, 'first_visit_date' => '2026-07-28'],
                ['id' => 2, 'group_id' => 99, 'first_visit_date' => '2026-07-28'],
            ],
            [
                ['id' => 1, 'group_id' => 1, 'status' => 'new'],
                ['id' => 2, 'group_id' => 99, 'status' => 'praying'],
            ],
            '2026-08-02'
        );

        $test->assertSame(1, $metrics['groups_count']);
        $test->assertSame(1, $metrics['people_count']);
        $test->assertSame(1, $metrics['newcomers']);
        $test->assertSame(1, $metrics['open_prayers']);
    },
    'dashboard metrics treat only new and praying as open needs' => function (TestCase $test): void {
        $metrics = dashboard_metrics(
            [['id' => 1], ['id' => 2]],
            [],
            [
                ['group_id' => 1, 'status' => 'new'],
                ['group_id' => 1, 'status' => 'praying'],
                ['group_id' => 2, 'status' => 'testimony'],
                ['group_id' => 2, 'status' => 'closed'],
            ],
            '2026-08-02'
        );

        $test->assertSame(2, $metrics['groups_count']);
        $test->assertSame(0, $metrics['people_count']);
        $test->assertSame(0, $metrics['newcomers']);
        $test->assertSame(2, $metrics['open_prayers']);
    },
    'dashboard newcomers respect the rolling 30 day window' => function (TestCase $test): void {
        $metrics = dashboard_metrics(
            [['id' => 1]],
            [
                ['group_id' => 1, 'first_visit_date' => '2026-07-03'], // exactly 30 days before 2026-08-02
                ['group_id' => 1, 'first_visit_date' => '2026-07-02'], // 31 days — too old
                ['group_id' => 1, 'first_visit_date' => null],
                ['group_id' => 1, 'first_visit_date' => ''],
            ],
            [],
            '2026-08-02'
        );

        $test->assertSame(4, $metrics['people_count']);
        $test->assertSame(1, $metrics['newcomers']);
    },
    'prayer status labels are russian' => function (TestCase $test): void {
        $labels = prayer_status_labels();

        $test->assertSame('Новая', $labels['new']);
        $test->assertSame('Молимся', $labels['praying']);
        $test->assertSame('Свидетельство', $labels['testimony']);
        $test->assertSame('Закрыта', $labels['closed']);
        $test->assertSame('Новая', prayer_status_label('new'));
        $test->assertSame('Молимся', prayer_status_label('praying'));
        $test->assertSame('Свидетельство', prayer_status_label('testimony'));
        $test->assertSame('Закрыта', prayer_status_label('closed'));
        $test->assertSame('unknown', prayer_status_label('unknown'));
    },
];
