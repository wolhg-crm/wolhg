<?php

declare(strict_types=1);

/**
 * OpenStreetMap district catalog (Nominatim + Overpass) with DB cache.
 */

function osm_district_catalog_user_agent(): string
{
    $app = function_exists('app_name') ? app_name() : 'ChurchCare';

    return $app . '/district-catalog (https://wolhg.ru; contact=admin@wolhg.ru)';
}

function osm_district_catalog_cache_ttl(): int
{
    return 7 * 24 * 3600;
}

function osm_district_catalog_cache_key(string $cityLabel): string
{
    return 'osm_districts:' . sha1(mb_strtolower(trim($cityLabel)));
}

/**
 * @return list<array{id: string, name: string, source: string, geojson: array<string, mixed>}>
 */
function osm_district_catalog_from_cache(string $cityLabel): ?array
{
    $key = osm_district_catalog_cache_key($cityLabel);
    try {
        $row = fetch_one(
            'SELECT payload, expires_at FROM district_catalog_cache WHERE cache_key = ? LIMIT 1',
            [$key]
        );
    } catch (Throwable) {
        return null;
    }
    if ($row === null) {
        return null;
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        return null;
    }
    $payload = json_decode((string) $row['payload'], true);

    return is_array($payload) ? osm_district_catalog_normalize_candidates($payload) : null;
}

/**
 * @param list<array<string, mixed>> $candidates
 */
function osm_district_catalog_store_cache(string $cityLabel, array $candidates): void
{
    $key = osm_district_catalog_cache_key($cityLabel);
    $ttl = osm_district_catalog_cache_ttl();
    $expires = date('Y-m-d H:i:s', time() + $ttl);
    $json = json_encode(array_values($candidates), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    try {
        execute(
            'INSERT INTO district_catalog_cache (cache_key, city_label, payload, fetched_at, expires_at)
             VALUES (?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE city_label = VALUES(city_label), payload = VALUES(payload),
               fetched_at = VALUES(fetched_at), expires_at = VALUES(expires_at)',
            [$key, $cityLabel, $json, $expires]
        );
    } catch (Throwable) {
        // Cache is best-effort.
    }
}

/**
 * @param callable|null $httpFetcher fn(string $url, array $opts): ?string
 * @return list<array{id: string, name: string, source: string, geojson: array<string, mixed>}>
 */
function osm_district_catalog_fetch(string $cityLabel, ?callable $httpFetcher = null): array
{
    $cityLabel = trim($cityLabel);
    if ($cityLabel === '') {
        return [];
    }

    $cached = osm_district_catalog_from_cache($cityLabel);
    if ($cached !== null) {
        return $cached;
    }

    $fetcher = $httpFetcher ?? 'osm_district_catalog_http_get';
    $city = osm_district_catalog_nominatim_city($cityLabel, $fetcher);
    if ($city === null) {
        return [];
    }

    $overpass = osm_district_catalog_overpass_areas($city['bbox'], $fetcher);
    $candidates = osm_district_catalog_normalize_candidates($overpass);
    if ($candidates !== []) {
        osm_district_catalog_store_cache($cityLabel, $candidates);
    }

    return $candidates;
}

/**
 * @param callable $fetcher
 * @return array{bbox: array{0: float, 1: float, 2: float, 3: float}, name: string}|null
 */
function osm_district_catalog_nominatim_city(string $cityLabel, callable $fetcher): ?array
{
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $cityLabel,
        'format' => 'json',
        'limit' => 1,
        'addressdetails' => 0,
    ]);
    $raw = $fetcher($url, ['headers' => ['Accept: application/json']]);
    $data = json_decode((string) $raw, true);
    if (!is_array($data) || $data === [] || !isset($data[0]['boundingbox'])) {
        return null;
    }
    $bb = $data[0]['boundingbox'];
    if (!is_array($bb) || count($bb) < 4) {
        return null;
    }

    // Nominatim boundingbox: south, north, west, east
    return [
        'name' => (string) ($data[0]['display_name'] ?? $cityLabel),
        'bbox' => [(float) $bb[0], (float) $bb[2], (float) $bb[1], (float) $bb[3]],
    ];
}

/**
 * @param array{0: float, 1: float, 2: float, 3: float} $bbox south, west, north, east
 * @param callable $fetcher
 * @return list<array<string, mixed>>
 */
function osm_district_catalog_overpass_areas(array $bbox, callable $fetcher): array
{
    [$south, $west, $north, $east] = $bbox;
    $query = <<<OVERPASS
[out:json][timeout:60];
(
  way["place"~"suburb|neighbourhood|quarter|city_block"]({$south},{$west},{$north},{$east});
  relation["place"~"suburb|neighbourhood|quarter|city_block"]({$south},{$west},{$north},{$east});
  relation["boundary"="administrative"]["admin_level"~"8|9|10"]({$south},{$west},{$north},{$east});
);
out body geom;
OVERPASS;

    $url = 'https://overpass-api.de/api/interpreter';
    $raw = $fetcher($url, [
        'method' => 'POST',
        'body' => 'data=' . rawurlencode($query),
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $data = json_decode((string) $raw, true);
    if (!is_array($data) || !isset($data['elements']) || !is_array($data['elements'])) {
        return [];
    }

    return osm_district_catalog_parse_overpass_elements($data['elements']);
}

/**
 * @param list<array<string, mixed>> $elements
 * @return list<array{id: string, name: string, source: string, geojson: array<string, mixed>}>
 */
function osm_district_catalog_parse_overpass_elements(array $elements): array
{
    $ways = [];
    $relations = [];
    foreach ($elements as $el) {
        if (!is_array($el)) {
            continue;
        }
        $type = (string) ($el['type'] ?? '');
        if ($type === 'way') {
            $ways[(int) ($el['id'] ?? 0)] = $el;
        } elseif ($type === 'relation') {
            $relations[] = $el;
        }
    }

    $candidates = [];
    foreach ($ways as $way) {
        $name = trim((string) (($way['tags']['name'] ?? $way['tags']['name:ru'] ?? '')));
        if ($name === '') {
            continue;
        }
        $ring = osm_district_catalog_way_ring_lonlat($way);
        if ($ring === null) {
            continue;
        }
        $geojson = ['type' => 'Polygon', 'coordinates' => [$ring]];
        if (ChurchCare\normalize_district_geojson($geojson) === null) {
            continue;
        }
        $candidates[] = [
            'id' => 'way/' . (int) ($way['id'] ?? 0),
            'name' => $name,
            'source' => 'osm',
            'geojson' => $geojson,
        ];
    }

    foreach ($relations as $relation) {
        $name = trim((string) (($relation['tags']['name'] ?? $relation['tags']['name:ru'] ?? '')));
        if ($name === '') {
            continue;
        }
        $outers = [];
        foreach (($relation['members'] ?? []) as $member) {
            if (!is_array($member)) {
                continue;
            }
            if (($member['type'] ?? '') !== 'way') {
                continue;
            }
            $role = (string) ($member['role'] ?? 'outer');
            if ($role !== '' && $role !== 'outer') {
                continue;
            }
            $wayId = (int) ($member['ref'] ?? 0);
            $way = $ways[$wayId] ?? null;
            if ($way === null && isset($member['geometry']) && is_array($member['geometry'])) {
                $way = ['geometry' => $member['geometry']];
            }
            if ($way === null) {
                continue;
            }
            $ring = osm_district_catalog_way_ring_lonlat($way);
            if ($ring !== null) {
                $outers[] = $ring;
            }
        }
        if ($outers === []) {
            continue;
        }
        $geojson = count($outers) === 1
            ? ['type' => 'Polygon', 'coordinates' => [$outers[0]]]
            : ['type' => 'MultiPolygon', 'coordinates' => array_map(static fn (array $r): array => [$r], $outers)];
        if (ChurchCare\normalize_district_geojson($geojson) === null) {
            continue;
        }
        $candidates[] = [
            'id' => 'relation/' . (int) ($relation['id'] ?? 0),
            'name' => $name,
            'source' => 'osm',
            'geojson' => $geojson,
        ];
    }

    return osm_district_catalog_dedupe_by_name($candidates);
}

/**
 * @param array<string, mixed> $way
 * @return list<array{0: float, 1: float}>|null lon/lat ring
 */
function osm_district_catalog_way_ring_lonlat(array $way): ?array
{
    $geometry = $way['geometry'] ?? null;
    if (!is_array($geometry) || count($geometry) < 3) {
        return null;
    }
    $ring = [];
    foreach ($geometry as $pt) {
        if (!is_array($pt)) {
            continue;
        }
        $ring[] = [(float) ($pt['lon'] ?? 0), (float) ($pt['lat'] ?? 0)];
    }
    if (count($ring) < 4) {
        return null;
    }
    $first = $ring[0];
    $last = $ring[count($ring) - 1];
    if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
        $ring[] = $first;
    }

    return $ring;
}

/**
 * @param list<array<string, mixed>> $candidates
 * @return list<array{id: string, name: string, source: string, geojson: array<string, mixed>}>
 */
function osm_district_catalog_normalize_candidates(array $candidates): array
{
    $out = [];
    foreach ($candidates as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string) ($row['name'] ?? ''));
        $id = trim((string) ($row['id'] ?? ''));
        $geojson = ChurchCare\normalize_district_geojson($row['geojson'] ?? []);
        if ($name === '' || $id === '' || $geojson === null) {
            continue;
        }
        $out[] = [
            'id' => $id,
            'name' => $name,
            'source' => (string) ($row['source'] ?? 'osm'),
            'geojson' => $geojson,
        ];
    }

    return osm_district_catalog_dedupe_by_name($out);
}

/**
 * @param list<array{id: string, name: string, source: string, geojson: array<string, mixed>}> $candidates
 * @return list<array{id: string, name: string, source: string, geojson: array<string, mixed>}>
 */
function osm_district_catalog_dedupe_by_name(array $candidates): array
{
    $byName = [];
    foreach ($candidates as $row) {
        $key = mb_strtolower($row['name']);
        if (!isset($byName[$key])) {
            $byName[$key] = $row;
        }
    }
    $list = array_values($byName);
    usort($list, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

    return $list;
}

/**
 * @param array{headers?: list<string>, method?: string, body?: string} $opts
 */
function osm_district_catalog_http_get(string $url, array $opts = []): ?string
{
    $headers = array_merge(
        ['User-Agent: ' . osm_district_catalog_user_agent()],
        $opts['headers'] ?? []
    );
    $method = strtoupper((string) ($opts['method'] ?? 'GET'));
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => (string) ($opts['body'] ?? ''),
            'timeout' => 45,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);

    return $raw === false ? null : $raw;
}
