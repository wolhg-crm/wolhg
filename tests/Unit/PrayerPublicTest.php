<?php

declare(strict_types=1);

use function ChurchCare\prayer_normalize_submitter_name;
use function ChurchCare\prayer_submitter_label;

require_once __DIR__ . '/../../src/Domain.php';
require_once __DIR__ . '/../../src/ChurchTenant.php';
require_once __DIR__ . '/../../src/PrayerPublic.php';

return [
    'prayer submitter label prefers person name then guest name then anonymous' => function (TestCase $test): void {
        $test->assertSame('Анонимно', prayer_submitter_label(['is_anonymous' => 1, 'full_name' => 'Иван', 'submitter_name' => 'Гость']));
        $test->assertSame('Иван Петров', prayer_submitter_label(['is_anonymous' => 0, 'full_name' => 'Иван Петров', 'submitter_name' => 'Гость']));
        $test->assertSame('Анна Гость', prayer_submitter_label(['is_anonymous' => 0, 'full_name' => '', 'submitter_name' => 'Анна Гость']));
        $test->assertSame('Без имени', prayer_submitter_label(['is_anonymous' => 0, 'full_name' => null, 'submitter_name' => '']));
    },

    'prayer guest name normalization collapses spaces and truncates' => function (TestCase $test): void {
        $test->assertNull(prayer_normalize_submitter_name(''));
        $test->assertNull(prayer_normalize_submitter_name('   '));
        $test->assertSame('Иван Петров', prayer_normalize_submitter_name('  Иван   Петров  '));
        $long = str_repeat('а', 200);
        $normalized = prayer_normalize_submitter_name($long);
        $test->assertTrue($normalized !== null);
        $test->assertSame(190, mb_strlen((string) $normalized));
    },

    'public prayer request route is available before login and requires tenant' => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $module = file_get_contents(__DIR__ . '/../../src/PrayerPublic.php');
        $modules = file_get_contents(__DIR__ . '/../../src/load_modules.php');

        $routePos = strpos($index, "if (\$path === '/prayer-request')");
        $loginPos = strpos($index, '$user = require_login();');
        $test->assertTrue($routePos !== false);
        $test->assertTrue($loginPos !== false);
        $test->assertTrue($routePos < $loginPos);
        $test->assertStringContains('redirect_legacy_crm_to_default_church(\'/prayer-request\'', $index);
        $test->assertStringContains('prayer_request_public_page();', $index);
        $test->assertStringContains('function prayer_request_public_page', $module);
        $test->assertStringContains('current_church()', $module);
        $test->assertStringContains('prayer_public_target_church_id', $module);
        $test->assertStringContains('prayer_public_linked_person_for_church', $module);
        $test->assertStringContains('data-prayer-anonymous', $module);
        $test->assertStringContains('data-prayer-guest-name', $module);
        $test->assertStringContains('Отправить анонимно', $module);
        $test->assertStringContains("'PrayerPublic.php'", $modules);
        $test->assertFileExists(__DIR__ . '/../../database/migrations/018_prayer_public_submit.sql');
    },

    'linked person is only attached when church matches' => function (TestCase $test): void {
        $linked = ['id' => 10, 'church_id' => 2, 'full_name' => 'Иван', 'group_id' => 5];
        $test->assertNull(prayer_public_linked_person_for_church($linked, 1));
        $test->assertNull(prayer_public_linked_person_for_church(null, 2));
        $same = prayer_public_linked_person_for_church($linked, 2);
        $test->assertNotNull($same);
        $test->assertSame(10, (int) $same['id']);
    },

    'prayer public target church prefers tenant over default' => function (TestCase $test): void {
        set_current_church(['id' => 7, 'slug' => 'korolev', 'name' => 'СЖ Королёв', 'deleted_at' => null]);
        $test->assertSame(7, prayer_public_target_church_id());
        set_current_church(null);
    },
];
