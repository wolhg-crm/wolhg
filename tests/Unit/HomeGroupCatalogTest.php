<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/HomeGroup.php';

use function ChurchCare\home_group_age_categories;
use function ChurchCare\home_group_catalog_default_filters;
use function ChurchCare\home_group_catalog_filter_sql;
use function ChurchCare\home_group_catalog_normalize_filters;
use function ChurchCare\home_group_catalog_row;
use function ChurchCare\home_group_matches_location_query;
use function ChurchCare\home_group_settings_from_post;
use function ChurchCare\home_group_short_address;
use function ChurchCare\home_group_types_json;
use function ChurchCare\home_group_types_label;
use function ChurchCare\parse_home_group_catalog_filters;

return [
    'parse filters accepts multiple values and normalizes time range' => function (TestCase $test): void {
        $filters = parse_home_group_catalog_filters([
            'age' => ['adults', 'teens', 'invalid'],
            'weekday' => ['2', '4'],
            'type' => ['general', 'female'],
            'time' => ['19:00', 'bad'],
            'churches' => ['3', '1', '0', '3', 'bad'],
            'time_from' => '14:00',
            'time_to' => '10:00',
        ]);

        $test->assertSame(['adults', 'teens'], $filters['ages']);
        $test->assertSame([2, 4], $filters['weekdays']);
        $test->assertSame(['general', 'female'], $filters['types']);
        $test->assertSame(['19:00'], $filters['times']);
        $test->assertSame([3, 1], $filters['church_ids']);
        $test->assertSame('10:00', $filters['time_from']);
        $test->assertSame('14:00', $filters['time_to']);
    },

    'filter sql accepts null and partial filters without type errors' => function (TestCase $test): void {
        foreach ([null, [], ['ages' => null], ['weekdays' => null, 'types' => null, 'church_ids' => null]] as $filters) {
            [$sql, $params] = home_group_catalog_filter_sql($filters);
            $test->assertSame('', $sql);
            $test->assertSame([], $params);
        }
    },

    'normalize filters fills missing keys with safe defaults' => function (TestCase $test): void {
        $test->assertSame(home_group_catalog_default_filters(), home_group_catalog_normalize_filters(null));
        $test->assertSame(['adults'], home_group_catalog_normalize_filters(['ages' => ['adults']])['ages']);
        $test->assertSame([], home_group_catalog_normalize_filters(['ages' => null])['ages']);
        $test->assertSame([2, 5], home_group_catalog_normalize_filters(['church_ids' => ['2', 5, 0]])['church_ids']);
        $test->assertSame(null, home_group_catalog_normalize_filters(['time_from' => ''])['time_from']);
    },

    'filter sql builds age weekday type church and time clauses' => function (TestCase $test): void {
        [$sql, $params] = home_group_catalog_filter_sql([
            'ages' => ['adults'],
            'weekdays' => [2],
            'types' => ['general'],
            'church_ids' => [1, 4],
            'times' => ['19:00'],
            'time_from' => null,
            'time_to' => null,
        ]);

        $test->assertStringContains('g.age_category IN', $sql);
        $test->assertStringContains('g.meeting_weekday IN', $sql);
        $test->assertStringContains('JSON_CONTAINS', $sql);
        $test->assertStringContains('g.church_id IN', $sql);
        $test->assertStringContains("TIME_FORMAT(g.meeting_time_start", $sql);
        $test->assertSame(['adults', 2, 'general', 1, 4, '19:00'], $params);
    },

    'catalog row exposes leader schedule address church and join url' => function (TestCase $test): void {
        $row = home_group_catalog_row([
            'id' => 7,
            'church_id' => 3,
            'church_name' => 'Слово Жизни Королёв',
            'church_city' => 'Королёв',
            'church_slug' => 'korolev',
            'name' => 'Группа №5',
            'leader_name' => 'Андрей Смирнов',
            'group_types' => '["general","family"]',
            'age_category' => 'adults',
            'meeting_weekday' => 2,
            'meeting_time_start' => '19:00:00',
            'meeting_time_end' => '21:00:00',
            'short_address' => 'Пушкино / Серебрянка',
            'address' => 'Пушкино, ул. Набережная, 12',
            'latitude' => 56.0189,
            'longitude' => 37.8486,
            'people_count' => 8,
            'max_people' => 18,
        ]);

        $test->assertSame(7, $row['id']);
        $test->assertSame(3, $row['church_id']);
        $test->assertSame('korolev', $row['church_slug']);
        $test->assertSame('Королёв', $row['church_label']);
        $test->assertSame('Андрей Смирнов', $row['leader_name']);
        $test->assertSame('Общая, Семейная', $row['types_label']);
        $test->assertSame('Вторник, 19:00–21:00', $row['schedule_label']);
        $test->assertSame('Пушкино / Серебрянка', $row['short_address']);
        $test->assertSame('/homegroup/join?group=7', $row['join_url']);
    },

    'short address falls back to district and address parts' => function (TestCase $test): void {
        $test->assertSame('Серебрянка', home_group_short_address(['district' => 'Серебрянка']));
        $test->assertSame('Пушкино / ул. Набережная / 12', home_group_short_address(['address' => 'Пушкино, ул. Набережная, 12']));
    },

    'location query matches short address address and legacy district' => function (TestCase $test): void {
        $test->assertTrue(home_group_matches_location_query(['short_address' => 'Митино / Москва'], 'митино'));
        $test->assertTrue(home_group_matches_location_query(['address' => 'Пушкино, ул. Набережная, 12'], 'Пушкино'));
        $test->assertTrue(home_group_matches_location_query(['district' => 'Серебрянка'], 'серебр'));
        $test->assertFalse(home_group_matches_location_query(['short_address' => 'Митино'], ''));
        $test->assertFalse(home_group_matches_location_query(['short_address' => 'Митино'], 'Химки'));
    },

    'settings from post keeps single age weekday and multiple types' => function (TestCase $test): void {
        $settings = home_group_settings_from_post([
            'age_category' => 'youth_under25',
            'meeting_weekday' => '4',
            'group_types' => ['general', 'english', 'invalid'],
            'meeting_time_start' => '19:30',
            'meeting_time_end' => '21:00',
            'short_address' => 'Митино / Москва',
        ]);

        $test->assertSame('youth_under25', $settings['age_category']);
        $test->assertSame(4, $settings['meeting_weekday']);
        $test->assertSame(['general', 'english'], $settings['group_types']);
        $test->assertSame('19:30:00', $settings['meeting_time_start']);
        $test->assertSame('21:00:00', $settings['meeting_time_end']);
        $test->assertSame('Четверг', $settings['weekday']);
        $test->assertSame('["general","english"]', home_group_types_json($settings['group_types']));
    },

    'types label handles empty selection' => function (TestCase $test): void {
        $test->assertSame('Не указано', home_group_types_label([]));
        $test->assertSame('Женская', home_group_types_label(['female']));
    },

    'age categories include required public filter values' => function (TestCase $test): void {
        $categories = home_group_age_categories();
        foreach (['adults', 'youth_under25', 'youth_over25', 'teens'] as $key) {
            $test->assertTrue(array_key_exists($key, $categories), 'Missing age category: ' . $key);
        }
    },

    'filter multiselect summary describes empty single and multiple selections' => function (TestCase $test): void {
        require_once __DIR__ . '/../../src/HomeGroupCatalog.php';

        $test->assertSame('Любой', filter_multiselect_summary([]));
        $test->assertSame('Взрослые', filter_multiselect_summary(['Взрослые']));
        $test->assertSame('Взрослые, Подростки', filter_multiselect_summary(['Взрослые', 'Подростки']));
        $test->assertSame('3 выбрано', filter_multiselect_summary(['A', 'B', 'C']));
    },
];
