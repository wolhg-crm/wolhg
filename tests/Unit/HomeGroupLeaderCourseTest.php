<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/HomeGroupLeaderCourse.php';

return [
    'home group leader course keeps imported structure' => function (TestCase $test): void {
        $course = home_group_leader_course_data();

        $test->assertSame('Курс лидеров домашних групп', $course['title']);
        $test->assertSame(6, count($course['lessons']));
        $test->assertSame('lesson-1', $course['lessons'][0]['anchor']);
        $test->assertSame('lesson-6', $course['lessons'][5]['anchor']);
        $test->assertTrue(count($course['lessons'][0]['paragraphs']) > 70);
        $test->assertTrue(count($course['lessons'][3]['paragraphs']) > 120);
        $test->assertStringContains('Домашняя группа - это группа людей', implode("\n", $course['lessons'][0]['paragraphs']));
        $test->assertStringContains('Собрание группы состоит из шести разных частей', implode("\n", $course['lessons'][3]['paragraphs']));
        $test->assertStringContains('Душепопечение - это забота о сердце человека', implode("\n", $course['lessons'][5]['paragraphs']));
    },

    'home group leader course assets exist' => function (TestCase $test): void {
        $course = home_group_leader_course_data();

        $test->assertFileExists(__DIR__ . '/../../public' . $course['hero_image']);
    },
];
