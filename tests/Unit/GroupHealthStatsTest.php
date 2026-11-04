<?php

declare(strict_types=1);

use function ChurchCare\health_metrics;
use function ChurchCare\meeting_counts_toward_group_health;

return [
    /**
     * TC1: Pre-created future meeting with 0 marks must not enter the denominator.
     * TC2: Past meeting without attendance rows is excluded.
     * TC3: Past meeting with marks counts; average stays stable when a future shell is added.
     */
    'TC1-TC3: future unmarked meetings do not dilute average attendance' => function (TestCase $test): void {
        $today = '2026-10-26';
        $pastCounted = meeting_counts_toward_group_health('2026-10-19', true, $today);
        $futureShell = meeting_counts_toward_group_health('2026-11-02', false, $today);
        $futureWithLeak = meeting_counts_toward_group_health('2026-11-02', true, $today);
        $unmarkedPast = meeting_counts_toward_group_health('2026-10-12', false, $today);

        $test->assertTrue($pastCounted);
        $test->assertFalse($futureShell);
        $test->assertFalse($futureWithLeak);
        $test->assertFalse($unmarkedPast);

        // Real history: 10 people × 2 meetings × ~60% present → 12 marks → 60%.
        $healthy = health_metrics(10, 2, 12, 0, 0, 0);
        $test->assertSame(60, $healthy['average_attendance']);

        // Bug before fix: counting the future shell as a 3rd meeting → 12/(10*3)=40%.
        $diluted = health_metrics(10, 3, 12, 0, 0, 0);
        $test->assertSame(40, $diluted['average_attendance']);
        $test->assertTrue($healthy['average_attendance'] > $diluted['average_attendance']);
    },

    'group_health_stats SQL excludes future and unmarked meetings' => function (TestCase $test): void {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Care.php');
        $test->assertStringContains('m.meeting_date <= CURDATE()', $src);
        $test->assertStringContains('EXISTS (SELECT 1 FROM attendance a WHERE a.meeting_id = m.id)', $src);
        $test->assertStringContains('meeting_counts_toward_group_health', (string) file_get_contents(__DIR__ . '/../../src/Domain.php'));
    },
];
