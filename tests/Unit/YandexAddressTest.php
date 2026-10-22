<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/YandexAddress.php';
require_once __DIR__ . '/../../src/Bots.php';

return [
    'yandex profile addresses are normalized into crm lines' => function (TestCase $test): void {
        $addresses = yandex_address_normalize_profile([
            'addresses' => [[
                'city' => 'Пушкино',
                'street' => 'ул. Ленина',
                'house' => '1',
                'flat' => '12',
            ]],
        ]);

        $test->assertSame(1, count($addresses));
        $test->assertSame('Пушкино, ул. Ленина, 1, кв. 12', yandex_address_formatted_line($addresses[0]));
    },

    'yandex addresses deduplicate equivalent entries' => function (TestCase $test): void {
        $addresses = yandex_address_sanitize_list([
            ['formatted' => 'Пушкино, ул. Ленина, 1'],
            ['formatted' => 'Пушкино, ул. Ленина, 1'],
        ]);

        $test->assertSame(1, count($addresses));
    },

    'reverse geocode cache key rounds coordinates' => function (TestCase $test): void {
        $test->assertSame(
            yandex_geocode_coords_cache_key(56.018911, 37.848641),
            yandex_geocode_coords_cache_key(56.018914, 37.848644)
        );
    },
];
