<?php

declare(strict_types=1);

use function ChurchCare\geojson_bbox;
use function ChurchCare\normalize_district_geojson;
use function ChurchCare\point_in_geojson;
use function ChurchCare\point_in_polygon;
use function ChurchCare\resolve_district_for_point;

$square = [
    [55.0, 37.0],
    [55.0, 38.0],
    [56.0, 38.0],
    [56.0, 37.0],
    [55.0, 37.0],
];

$geoPolygon = [
    'type' => 'Polygon',
    'coordinates' => [[
        [37.0, 55.0],
        [38.0, 55.0],
        [38.0, 56.0],
        [37.0, 56.0],
        [37.0, 55.0],
    ]],
];

$geoWithHole = [
    'type' => 'Polygon',
    'coordinates' => [
        [
            [37.0, 55.0],
            [38.0, 55.0],
            [38.0, 56.0],
            [37.0, 56.0],
            [37.0, 55.0],
        ],
        [
            [37.4, 55.4],
            [37.6, 55.4],
            [37.6, 55.6],
            [37.4, 55.6],
            [37.4, 55.4],
        ],
    ],
];

return [
    'point_in_polygon detects interior and exterior' => function (TestCase $test) use ($square): void {
        $test->assertTrue(point_in_polygon(55.5, 37.5, $square));
        $test->assertFalse(point_in_polygon(54.0, 37.5, $square));
        $test->assertFalse(point_in_polygon(55.5, 39.0, $square));
    },
    'point_in_geojson respects holes' => function (TestCase $test) use ($geoPolygon, $geoWithHole): void {
        $test->assertTrue(point_in_geojson(55.5, 37.5, $geoPolygon));
        $test->assertTrue(point_in_geojson(55.5, 37.2, $geoWithHole));
        $test->assertFalse(point_in_geojson(55.5, 37.5, $geoWithHole));
    },
    'geojson_bbox computes extents' => function (TestCase $test) use ($geoPolygon): void {
        $bbox = geojson_bbox($geoPolygon);
        $test->assertNotNull($bbox);
        $test->assertSame(55.0, $bbox['min_lat']);
        $test->assertSame(56.0, $bbox['max_lat']);
        $test->assertSame(37.0, $bbox['min_lon']);
        $test->assertSame(38.0, $bbox['max_lon']);
    },
    'normalize_district_geojson unwraps Feature' => function (TestCase $test) use ($geoPolygon): void {
        $normalized = normalize_district_geojson([
            'type' => 'Feature',
            'geometry' => $geoPolygon,
            'properties' => ['name' => 'X'],
        ]);
        $test->assertSame('Polygon', $normalized['type'] ?? null);
        $test->assertSame($geoPolygon['coordinates'], $normalized['coordinates'] ?? null);
    },
    'resolve_district_for_point prefers smaller bbox match' => function (TestCase $test) use ($geoPolygon): void {
        $small = [
            'type' => 'Polygon',
            'coordinates' => [[
                [37.4, 55.4],
                [37.6, 55.4],
                [37.6, 55.6],
                [37.4, 55.6],
                [37.4, 55.4],
            ]],
        ];
        $districts = [
            [
                'id' => 1,
                'polygon_geojson' => $geoPolygon,
                'bbox_min_lat' => 55.0,
                'bbox_max_lat' => 56.0,
                'bbox_min_lon' => 37.0,
                'bbox_max_lon' => 38.0,
            ],
            [
                'id' => 2,
                'polygon_geojson' => $small,
                'bbox_min_lat' => 55.4,
                'bbox_max_lat' => 55.6,
                'bbox_min_lon' => 37.4,
                'bbox_max_lon' => 37.6,
            ],
        ];

        $test->assertSame(2, resolve_district_for_point($districts, 55.5, 37.5));
        $test->assertSame(1, resolve_district_for_point($districts, 55.1, 37.1));
        $test->assertNull(resolve_district_for_point($districts, 50.0, 30.0));
    },
];
