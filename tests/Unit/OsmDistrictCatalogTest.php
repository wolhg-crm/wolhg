<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Domain.php';
require_once __DIR__ . '/../../src/OsmDistrictCatalog.php';

return [
    'overpass parser builds polygon candidates from closed ways' => function (TestCase $test): void {
        $elements = [
            [
                'type' => 'way',
                'id' => 101,
                'tags' => ['name' => 'Серебрянка', 'place' => 'suburb'],
                'geometry' => [
                    ['lat' => 56.0, 'lon' => 37.83],
                    ['lat' => 56.0, 'lon' => 37.86],
                    ['lat' => 56.02, 'lon' => 37.86],
                    ['lat' => 56.02, 'lon' => 37.83],
                    ['lat' => 56.0, 'lon' => 37.83],
                ],
            ],
            [
                'type' => 'way',
                'id' => 102,
                'tags' => ['place' => 'suburb'],
                'geometry' => [
                    ['lat' => 56.0, 'lon' => 37.83],
                    ['lat' => 56.0, 'lon' => 37.86],
                    ['lat' => 56.02, 'lon' => 37.86],
                ],
            ],
        ];

        $candidates = osm_district_catalog_parse_overpass_elements($elements);
        $test->assertCount(1, $candidates);
        $test->assertSame('Серебрянка', $candidates[0]['name']);
        $test->assertSame('way/101', $candidates[0]['id']);
        $test->assertSame('Polygon', $candidates[0]['geojson']['type']);
        $test->assertTrue(ChurchCare\point_in_geojson(56.01, 37.845, $candidates[0]['geojson']));
    },
    'normalize candidates rejects invalid geojson' => function (TestCase $test): void {
        $normalized = osm_district_catalog_normalize_candidates([
            ['id' => 'x', 'name' => 'Ok', 'geojson' => ['type' => 'Point', 'coordinates' => [1, 2]]],
            ['id' => 'y', 'name' => 'Good', 'geojson' => [
                'type' => 'Polygon',
                'coordinates' => [[[37.0, 55.0], [38.0, 55.0], [38.0, 56.0], [37.0, 56.0], [37.0, 55.0]]],
            ]],
        ]);
        $test->assertCount(1, $normalized);
        $test->assertSame('Good', $normalized[0]['name']);
    },
];
