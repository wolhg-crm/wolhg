<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Bots.php';

return [
    'geocoder cache key reuses equivalent address queries' => function (TestCase $test): void {
        $test->assertSame('Пушкино, ул. Ленина 1', yandex_geocode_normalize_query("  Пушкино,\nул.   Ленина\t1  "));
        $test->assertSame(
            yandex_geocode_cache_key('Пушкино, ул. Ленина 1'),
            yandex_geocode_cache_key("  пушкино,\nул.   ленина\t1  ")
        );
    },

    'geocoder response parser converts yandex lon lat into app coordinates' => function (TestCase $test): void {
        $data = [
            'response' => [
                'GeoObjectCollection' => [
                    'featureMember' => [[
                        'GeoObject' => [
                            'Point' => ['pos' => '37.8486000 56.0189000'],
                            'metaDataProperty' => [
                                'GeocoderMetaData' => ['text' => 'Россия, Московская область, Пушкино'],
                            ],
                        ],
                    ]],
                ],
            ],
        ];

        $test->assertSame([
            'latitude' => 56.0189,
            'longitude' => 37.8486,
            'address' => 'Россия, Московская область, Пушкино',
        ], yandex_geocode_result_from_response($data, 'Пушкино'));
    },

    'geocoder caches valid empty responses as negative lookups' => function (TestCase $test): void {
        $data = [
            'response' => [
                'GeoObjectCollection' => [
                    'featureMember' => [],
                ],
            ],
        ];

        $test->assertTrue(yandex_geocode_response_has_collection($data));
        $test->assertNull(yandex_geocode_result_from_response($data, 'неизвестный адрес'));
    },

    'geocoder ttl defaults protect yandex limits' => function (TestCase $test): void {
        $test->assertSame(7 * 24 * 60 * 60, yandex_geocode_cache_ttl(false));
        $test->assertSame(24 * 60 * 60, yandex_geocode_cache_ttl(true));
        $test->assertSame(3, yandex_geocode_http_timeout());
    },
];
