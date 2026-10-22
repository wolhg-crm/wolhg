<?php

declare(strict_types=1);

return [
    'resolve_group_location forward geocodes typed address' => function (TestCase $test): void {
        $result = ChurchCare\resolve_group_location(
            'Пушкино, ул. Ленина 1',
            static fn(string $query): ?array => [
                'address' => 'Россия, Московская область, Пушкино, улица Ленина, 1',
                'latitude' => 56.018911,
                'longitude' => 37.848641,
            ]
        );

        $test->assertSame('Россия, Московская область, Пушкино, улица Ленина, 1', $result['address']);
        $test->assertSame(56.018911, $result['latitude']);
        $test->assertSame(37.848641, $result['longitude']);
    },

    'resolve_group_location keeps typed address when geocoder misses' => function (TestCase $test): void {
        $result = ChurchCare\resolve_group_location(
            'Пушкино, ул. Ленина 1',
            static fn(string $query): ?array => null
        );

        $test->assertSame('Пушкино, ул. Ленина 1', $result['address']);
        $test->assertNull($result['latitude']);
        $test->assertNull($result['longitude']);
    },

    'resolve_group_location returns empty result for blank address' => function (TestCase $test): void {
        $result = ChurchCare\resolve_group_location(
            '   ',
            static fn(string $query): ?array => null
        );

        $test->assertNull($result['address']);
        $test->assertNull($result['latitude']);
        $test->assertNull($result['longitude']);
    },

    'participant_location_is_present accepts address or coordinates' => function (TestCase $test): void {
        $test->assertTrue(ChurchCare\participant_location_is_present('Пушкино', null, null));
        $test->assertTrue(ChurchCare\participant_location_is_present(null, 56.01, 37.84));
        $test->assertFalse(ChurchCare\participant_location_is_present('   ', null, null));
        $test->assertFalse(ChurchCare\participant_location_is_present(null, 56.01, null));
    },

    'participant_request_address_line prefers address over legacy district' => function (TestCase $test): void {
        $test->assertSame(
            'Россия, Пушкино, ул. Ленина 1',
            ChurchCare\participant_request_address_line([
                'address' => 'Россия, Пушкино, ул. Ленина 1',
                'district' => 'Пушкино',
            ])
        );
        $test->assertSame(
            'Пушкино',
            ChurchCare\participant_request_address_line(['district' => 'Пушкино'])
        );
        $test->assertNull(ChurchCare\participant_request_address_line([]));
    },
];
