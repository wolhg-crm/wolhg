<?php

declare(strict_types=1);

use function ChurchCare\health_metrics;

return [
    'health metrics calculate attendance rate and capped score' => function (TestCase $test): void {
        $metrics = health_metrics(8, 4, 17, 1, 5, 2);

        $test->assertSame(53, $metrics['average_attendance']);
        $test->assertSame(85, $metrics['score']);
    },
    'health metrics do not divide by zero when there are no meetings' => function (TestCase $test): void {
        $metrics = health_metrics(8, 0, 0, 1, 0, 0);

        $test->assertSame(0, $metrics['average_attendance']);
        $test->assertSame(5, $metrics['score']);
    },
    'health score is capped at one hundred' => function (TestCase $test): void {
        $metrics = health_metrics(10, 1, 10, 10, 10, 10);

        $test->assertSame(100, $metrics['score']);
    },
];
