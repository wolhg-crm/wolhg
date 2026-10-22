<?php

declare(strict_types=1);

namespace ChurchCare;

use DateTimeImmutable;

/**
 * Latest parseable calendar date among candidates (Y-m-d), or null when none.
 *
 * @param list<string|null> $dates
 */
function latest_care_date(?string ...$dates): ?string
{
    $candidates = [];
    foreach ($dates as $value) {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            continue;
        }
        try {
            $candidates[] = (new DateTimeImmutable($trimmed))->format('Y-m-d');
        } catch (\Exception) {
            // Ignore unparseable values from legacy/import data.
        }
    }

    if ($candidates === []) {
        return null;
    }

    return max($candidates);
}

/**
 * Latest known visit date for care radar.
 *
 * Meeting attendance (`present` / `new`) wins when newer; otherwise the person's
 * first_visit_date anchors the radar so newcomers are not marked red before any
 * meeting has been recorded.
 */
function effective_last_attendance(?string $lastAttendance, ?string $firstVisitDate): ?string
{
    return latest_care_date($lastAttendance, $firstVisitDate);
}

/**
 * Latest pastoral touchpoint for care radar / absence tasks.
 *
 * Logged contacts win when newer; first_visit_date anchors the clock so a person
 * added with a recent first visit is not treated as "no contact for a month".
 */
function effective_last_contact(?string $lastContact, ?string $firstVisitDate): ?string
{
    return latest_care_date($lastContact, $firstVisitDate);
}

function care_status(?string $lastAttendance, ?string $lastContact, string $today = 'today'): array
{
    $current = new DateTimeImmutable($today);
    $attendanceDays = $lastAttendance ? $current->diff(new DateTimeImmutable($lastAttendance))->days : 999;
    $contactDays = $lastContact ? $current->diff(new DateTimeImmutable($lastContact))->days : 999;

    if ($attendanceDays <= 7) {
        $level = ['green', 'Был на этой неделе'];
    } elseif ($attendanceDays <= 14) {
        $level = ['yellow', 'Не был 2 недели'];
    } elseif ($attendanceDays <= 42) {
        $level = ['orange', 'Не был месяц'];
    } else {
        $level = ['red', 'Не был больше 6 недель'];
    }

    if ($contactDays > 30 && $level[0] !== 'red') {
        $level = ['orange', $level[1] . ', нет контакта больше месяца'];
    }

    return [$level[0], $level[1], $attendanceDays, $contactDays];
}

function accessible_group_filter(array $user, string $alias = 'g'): array
{
    $role = (string) ($user['role'] ?? '');
    $tenantChurchId = \function_exists('effective_scope_church_id') ? \effective_scope_church_id($user) : null;

    if ($role === 'district_leader') {
        $churchId = ($tenantChurchId !== null && $tenantChurchId > 0)
            ? (int) $tenantChurchId
            : (int) ($user['church_id'] ?? 0);

        $churchHasDistricts = array_key_exists('_church_has_districts', $user)
            ? (bool) $user['_church_has_districts']
            : (\function_exists('church_has_active_districts') && $churchId > 0
                ? (bool) \church_has_active_districts($churchId)
                : false);

        // Bootstrap: until districts are configured, keep church-wide visibility.
        if (!$churchHasDistricts) {
            return ["$alias.church_id = ?", [$churchId]];
        }

        $districtIds = array_key_exists('_district_ids', $user)
            ? array_values(array_map('intval', (array) $user['_district_ids']))
            : (\function_exists('user_district_ids')
                ? array_values(array_map('intval', \user_district_ids((int) ($user['id'] ?? 0))))
                : []);

        if ($districtIds === []) {
            return ['1=0', []];
        }

        $placeholders = implode(',', array_fill(0, count($districtIds), '?'));

        return [
            "$alias.church_id = ? AND $alias.district_id IN ($placeholders)",
            array_merge([$churchId], $districtIds),
        ];
    }

    if ($tenantChurchId !== null && $tenantChurchId > 0 && in_array($role, ['super_admin', 'pastor', 'admin'], true)) {
        return ["$alias.church_id = ?", [$tenantChurchId]];
    }

    if ($role === 'super_admin') {
        return ['1=1', []];
    }

    if (in_array($role, ['pastor', 'admin'], true)) {
        return ["$alias.church_id = ?", [$user['church_id']]];
    }

    return ["$alias.id = ?", [$user['group_id']]];
}

/**
 * Ray-casting point-in-polygon. Ring is list of [lat, lon] (closed or open).
 *
 * @param list<array{0: float|int|string, 1: float|int|string}> $ring
 */
function point_in_polygon(float $lat, float $lon, array $ring): bool
{
    $n = count($ring);
    if ($n < 3) {
        return false;
    }

    // Close ring if needed.
    $first = $ring[0];
    $last = $ring[$n - 1];
    if ((float) $first[0] !== (float) $last[0] || (float) $first[1] !== (float) $last[1]) {
        $ring[] = $first;
        $n++;
    }

    $inside = false;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $yi = (float) $ring[$i][0];
        $xi = (float) $ring[$i][1];
        $yj = (float) $ring[$j][0];
        $xj = (float) $ring[$j][1];

        $intersect = (($yi > $lat) !== ($yj > $lat))
            && ($lon < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-15) + $xi);
        if ($intersect) {
            $inside = !$inside;
        }
    }

    return $inside;
}

/**
 * GeoJSON Polygon or MultiPolygon coordinates (lon/lat order as in GeoJSON).
 * Converted internally to [lat, lon] rings.
 *
 * @param array<string, mixed> $geojson
 */
function point_in_geojson(float $lat, float $lon, array $geojson): bool
{
    $type = (string) ($geojson['type'] ?? '');
    $coordinates = $geojson['coordinates'] ?? null;
    if (!is_array($coordinates)) {
        return false;
    }

    if ($type === 'Polygon') {
        return point_in_geojson_polygon_coords($lat, $lon, $coordinates);
    }

    if ($type === 'MultiPolygon') {
        foreach ($coordinates as $polygonCoords) {
            if (is_array($polygonCoords) && point_in_geojson_polygon_coords($lat, $lon, $polygonCoords)) {
                return true;
            }
        }

        return false;
    }

    return false;
}

/**
 * @param list<mixed> $polygonCoords GeoJSON polygon: [outerRing, hole, ...]
 */
function point_in_geojson_polygon_coords(float $lat, float $lon, array $polygonCoords): bool
{
    if ($polygonCoords === [] || !is_array($polygonCoords[0] ?? null)) {
        return false;
    }

    $outer = geojson_ring_to_latlon($polygonCoords[0]);
    if (!point_in_polygon($lat, $lon, $outer)) {
        return false;
    }

    for ($i = 1, $c = count($polygonCoords); $i < $c; $i++) {
        if (!is_array($polygonCoords[$i])) {
            continue;
        }
        $hole = geojson_ring_to_latlon($polygonCoords[$i]);
        if (point_in_polygon($lat, $lon, $hole)) {
            return false;
        }
    }

    return true;
}

/**
 * @param list<mixed> $ring GeoJSON ring of [lon, lat]
 * @return list<array{0: float, 1: float}> [lat, lon]
 */
function geojson_ring_to_latlon(array $ring): array
{
    $out = [];
    foreach ($ring as $pt) {
        if (!is_array($pt) || count($pt) < 2) {
            continue;
        }
        $out[] = [(float) $pt[1], (float) $pt[0]];
    }

    return $out;
}

/**
 * @param array<string, mixed> $geojson
 * @return array{min_lat: float, min_lon: float, max_lat: float, max_lon: float}|null
 */
function geojson_bbox(array $geojson): ?array
{
    $type = (string) ($geojson['type'] ?? '');
    $coordinates = $geojson['coordinates'] ?? null;
    if (!is_array($coordinates)) {
        return null;
    }

    $minLat = INF;
    $minLon = INF;
    $maxLat = -INF;
    $maxLon = -INF;
    $found = false;

    $visit = static function (array $ring) use (&$minLat, &$minLon, &$maxLat, &$maxLon, &$found): void {
        foreach ($ring as $pt) {
            if (!is_array($pt) || count($pt) < 2) {
                continue;
            }
            $lon = (float) $pt[0];
            $lat = (float) $pt[1];
            $minLat = min($minLat, $lat);
            $maxLat = max($maxLat, $lat);
            $minLon = min($minLon, $lon);
            $maxLon = max($maxLon, $lon);
            $found = true;
        }
    };

    if ($type === 'Polygon') {
        foreach ($coordinates as $ring) {
            if (is_array($ring)) {
                $visit($ring);
            }
        }
    } elseif ($type === 'MultiPolygon') {
        foreach ($coordinates as $polygon) {
            if (!is_array($polygon)) {
                continue;
            }
            foreach ($polygon as $ring) {
                if (is_array($ring)) {
                    $visit($ring);
                }
            }
        }
    } else {
        return null;
    }

    if (!$found) {
        return null;
    }

    return [
        'min_lat' => (float) $minLat,
        'min_lon' => (float) $minLon,
        'max_lat' => (float) $maxLat,
        'max_lon' => (float) $maxLon,
    ];
}

/**
 * Normalize stored/API GeoJSON into a Polygon or MultiPolygon feature geometry.
 *
 * @param array<string, mixed>|string $raw
 * @return array<string, mixed>|null
 */
function normalize_district_geojson(array|string $raw): ?array
{
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $raw = $decoded;
    }

    $type = (string) ($raw['type'] ?? '');
    if ($type === 'Feature' && isset($raw['geometry']) && is_array($raw['geometry'])) {
        return normalize_district_geojson($raw['geometry']);
    }
    if ($type === 'FeatureCollection' && isset($raw['features'][0]) && is_array($raw['features'][0])) {
        return normalize_district_geojson($raw['features'][0]);
    }
    if (in_array($type, ['Polygon', 'MultiPolygon'], true) && isset($raw['coordinates']) && is_array($raw['coordinates'])) {
        return [
            'type' => $type,
            'coordinates' => $raw['coordinates'],
        ];
    }

    return null;
}

/**
 * Pick the smallest-area matching district for a point (bbox prefilter + PIP).
 *
 * @param list<array<string, mixed>> $districts rows with polygon_geojson / bbox_* / id
 */
function resolve_district_for_point(array $districts, float $lat, float $lon): ?int
{
    $matches = [];
    foreach ($districts as $district) {
        $minLat = isset($district['bbox_min_lat']) ? (float) $district['bbox_min_lat'] : null;
        $maxLat = isset($district['bbox_max_lat']) ? (float) $district['bbox_max_lat'] : null;
        $minLon = isset($district['bbox_min_lon']) ? (float) $district['bbox_min_lon'] : null;
        $maxLon = isset($district['bbox_max_lon']) ? (float) $district['bbox_max_lon'] : null;
        if ($minLat !== null && $maxLat !== null && $minLon !== null && $maxLon !== null) {
            if ($lat < $minLat || $lat > $maxLat || $lon < $minLon || $lon > $maxLon) {
                continue;
            }
        }

        $geojson = normalize_district_geojson($district['polygon_geojson'] ?? []);
        if ($geojson === null || !point_in_geojson($lat, $lon, $geojson)) {
            continue;
        }

        $area = ($maxLat !== null && $minLat !== null && $maxLon !== null && $minLon !== null)
            ? max(0.0, ($maxLat - $minLat) * ($maxLon - $minLon))
            : PHP_FLOAT_MAX;
        $matches[] = ['id' => (int) ($district['id'] ?? 0), 'area' => $area];
    }

    if ($matches === []) {
        return null;
    }

    usort($matches, static fn (array $a, array $b): int => $a['area'] <=> $b['area']);
    $id = (int) $matches[0]['id'];

    return $id > 0 ? $id : null;
}

function accessible_person_filter(array $user, string $alias = 'p'): array
{
    if ($user['role'] === 'member') {
        return ["$alias.user_id = ? AND $alias.deleted_at IS NULL", [$user['id']]];
    }

    [$groupSql, $params] = accessible_group_filter($user, 'g');
    if (\function_exists('person_group_memberships_ready') && \person_group_memberships_ready()) {
        return [
            "$alias.deleted_at IS NULL AND (
                $alias.group_id IN (SELECT g.id FROM home_groups g WHERE $groupSql)
                OR EXISTS (
                    SELECT 1 FROM person_group_memberships pgm
                    INNER JOIN home_groups g ON g.id = pgm.group_id
                    WHERE pgm.person_id = $alias.id AND $groupSql
                )
            )",
            array_merge($params, $params),
        ];
    }

    return ["$alias.deleted_at IS NULL AND $alias.group_id IN (SELECT g.id FROM home_groups g WHERE $groupSql)", $params];
}

function health_metrics(int $peopleCount, int $meetingsCount, int $attendanceMarks, int $newcomers, int $serving, int $futureLeaders): array
{
    $averageAttendance = ($peopleCount > 0 && $meetingsCount > 0)
        ? (int) round($attendanceMarks / ($peopleCount * $meetingsCount) * 100)
        : 0;

    $score = min(100, $averageAttendance + ($newcomers * 5) + ($serving * 3) + ($futureLeaders * 6));

    return [
        'average_attendance' => $averageAttendance,
        'score' => $score,
    ];
}

/**
 * Russian labels for prayer_requests.status values.
 *
 * @return array<string, string>
 */
function prayer_status_labels(): array
{
    return [
        'new' => 'Новая',
        'praying' => 'Молимся',
        'testimony' => 'Свидетельство',
        'closed' => 'Закрыта',
    ];
}

function prayer_status_label(?string $status): string
{
    $labels = prayer_status_labels();
    $key = (string) $status;

    return $labels[$key] ?? $key;
}

/**
 * Display name for a prayer need in lists (person card, guest name, or anonymous).
 *
 * @param array{is_anonymous?:int|string|bool,full_name?:string|null,submitter_name?:string|null} $prayer
 */
function prayer_submitter_label(array $prayer): string
{
    if (!empty($prayer['is_anonymous'])) {
        return 'Анонимно';
    }

    $fullName = trim((string) ($prayer['full_name'] ?? ''));
    if ($fullName !== '') {
        return $fullName;
    }

    $submitter = trim((string) ($prayer['submitter_name'] ?? ''));
    if ($submitter !== '') {
        return $submitter;
    }

    return 'Без имени';
}

/**
 * Normalize guest full name for a public prayer submission.
 */
function prayer_normalize_submitter_name(?string $name): ?string
{
    $name = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');
    if ($name === '') {
        return null;
    }

    return mb_substr($name, 0, 190);
}

/**
 * Russian labels for miracles.status values.
 *
 * @return array<string, string>
 */
function miracle_status_labels(): array
{
    return [
        'published' => 'Опубликовано',
        'archived' => 'В архиве',
    ];
}

function miracle_status_label(?string $status): string
{
    $labels = miracle_status_labels();
    $key = (string) $status;

    return $labels[$key] ?? $key;
}

/**
 * Default miracle title when publishing a testimony from a prayer need.
 */
function miracle_default_title_from_prayer(string $prayerTitle): string
{
    $title = trim($prayerTitle);
    if ($title === '') {
        return 'Свидетельство';
    }

    if (mb_stripos($title, 'свидетельств') !== false || mb_stripos($title, 'чуд') !== false) {
        return mb_substr($title, 0, 190);
    }

    return mb_substr('Свидетельство: ' . $title, 0, 190);
}

/**
 * Normalize and validate miracle title/body before insert/update.
 *
 * @return array{title:string,body:string,is_anonymous:int}|null
 */
function miracle_prepare_payload(?string $title, ?string $body, bool $isAnonymous = false): ?array
{
    $normalizedTitle = trim(mb_substr(trim((string) $title), 0, 190));
    $normalizedBody = trim((string) $body);
    if ($normalizedTitle === '' || $normalizedBody === '') {
        return null;
    }

    return [
        'title' => $normalizedTitle,
        'body' => $normalizedBody,
        'is_anonymous' => $isAnonymous ? 1 : 0,
    ];
}

/**
 * Short preview for miracle cards.
 */
function miracle_excerpt(string $body, int $maxLength = 220): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);
    if ($text === '') {
        return '';
    }

    if (mb_strlen($text) <= $maxLength) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, max(1, $maxLength - 1))) . '…';
}

/**
 * Public author line for a miracle card/detail.
 *
 * @param array{is_anonymous?:int|string|bool,full_name?:string|null,submitter_name?:string|null,group_name?:string|null} $miracle
 */
function miracle_author_label(array $miracle): string
{
    if (!empty($miracle['is_anonymous'])) {
        return 'Анонимное свидетельство';
    }

    $name = trim((string) ($miracle['full_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($miracle['submitter_name'] ?? ''));
    }
    $group = trim((string) ($miracle['group_name'] ?? ''));
    if ($name !== '' && $group !== '') {
        return $name . ' · ' . $group;
    }
    if ($name !== '') {
        return $name;
    }
    if ($group !== '') {
        return $group;
    }

    return 'Свидетельство домашней группы';
}

/**
 * Whether setting prayer status to testimony requires a miracle body.
 */
function miracle_body_required_for_testimony(?array $existingMiracle, string $miracleBody): bool
{
    if ($existingMiracle !== null) {
        return false;
    }

    return trim($miracleBody) === '';
}

/**
 * Homepage metric tiles: count active groups, people, 30-day newcomers and open prayers
 * without fan-out from joining people × prayer_requests on the same group.
 *
 * @param list<array{id:int|string}> $activeGroups
 * @param list<array{id?:int|string,group_id:int|string|null,first_visit_date?:string|null}> $people non-deleted only
 * @param list<array{id?:int|string,group_id:int|string|null,status:string}> $prayers
 * @return array{groups_count:int,people_count:int,newcomers:int,open_prayers:int}
 */
function dashboard_metrics(array $activeGroups, array $people, array $prayers, string $today = 'today'): array
{
    $groupIds = [];
    foreach ($activeGroups as $group) {
        $groupIds[(int) $group['id']] = true;
    }

    $cutoff = (new DateTimeImmutable($today))->modify('-30 days')->format('Y-m-d');
    $peopleCount = 0;
    $newcomers = 0;
    foreach ($people as $person) {
        $groupId = (int) ($person['group_id'] ?? 0);
        if ($groupId <= 0 || !isset($groupIds[$groupId])) {
            continue;
        }
        $peopleCount++;
        $visit = trim((string) ($person['first_visit_date'] ?? ''));
        if ($visit !== '' && $visit >= $cutoff) {
            $newcomers++;
        }
    }

    $openPrayers = 0;
    foreach ($prayers as $prayer) {
        $groupId = (int) ($prayer['group_id'] ?? 0);
        if ($groupId <= 0 || !isset($groupIds[$groupId])) {
            continue;
        }
        if (in_array((string) $prayer['status'], ['new', 'praying'], true)) {
            $openPrayers++;
        }
    }

    return [
        'groups_count' => count($groupIds),
        'people_count' => $peopleCount,
        'newcomers' => $newcomers,
        'open_prayers' => $openPrayers,
    ];
}

function newcomer_followup_tasks(int $personId, int $groupId, int $assignedTo, string $fullName, string $firstVisitDate, string $today = 'today'): array
{
    $firstVisit = new DateTimeImmutable($firstVisitDate);
    $current = new DateTimeImmutable($today);
    $horizon = $current->modify('+14 days');

    $flow = [
        [2, 'Позвонить новичку ' . $fullName],
        [7, 'Пригласить новичка снова: ' . $fullName],
        [30, 'Предложить встречу новичку: ' . $fullName],
    ];

    $tasks = [];
    foreach ($flow as [$days, $title]) {
        $due = $firstVisit->modify('+' . $days . ' days');
        if ($due <= $horizon) {
            $tasks[] = [
                'person_id' => $personId,
                'group_id' => $groupId,
                'assigned_to' => $assignedTo,
                'title' => $title,
                'due_date' => $due->format('Y-m-d'),
                'type' => 'newcomer',
            ];
        }
    }

    return $tasks;
}

function care_gap_task(?string $lastAttendance, ?string $lastContact, int $personId, int $groupId, int $assignedTo, string $fullName, string $today = 'today'): ?array
{
    [, , $attendanceDays, $contactDays] = care_status($lastAttendance, $lastContact, $today);
    if ($attendanceDays <= 21 && $contactDays <= 30) {
        return null;
    }

    return [
        'person_id' => $personId,
        'group_id' => $groupId,
        'assigned_to' => $assignedTo,
        'title' => 'Связаться: ' . $fullName . ' выпадает из общения',
        'due_date' => (new DateTimeImmutable($today))->format('Y-m-d'),
        'type' => 'absence',
    ];
}

function needs_radar_notification(?string $lastAttendance, string $today = 'today'): bool
{
    [, , $attendanceDays] = care_status($lastAttendance, null, $today);
    return $attendanceDays > 42;
}

/**
 * Keep only digits from a phone-like string.
 */
function phone_digits(?string $value): string
{
    return preg_replace('/\D+/', '', (string) $value) ?? '';
}

/**
 * Normalize a Russian phone to E.164 (+7XXXXXXXXXX) or null when empty/incomplete.
 * Accepts 8XXXXXXXXXX, 7XXXXXXXXXX, +7..., spaced and dashed variants.
 */
function normalize_phone(?string $value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '' || $raw === '+7' || $raw === '+7 ') {
        return null;
    }

    $digits = phone_digits($raw);
    if ($digits === '') {
        return null;
    }

    if (str_starts_with($digits, '8') && strlen($digits) === 11) {
        $digits = '7' . substr($digits, 1);
    } elseif (strlen($digits) === 10) {
        $digits = '7' . $digits;
    }

    if (!preg_match('/^7\d{10}$/', $digits)) {
        return null;
    }

    return '+' . $digits;
}

/**
 * Display phone as +7 977 818-97-73. Empty string when missing.
 */
function format_phone(?string $value): string
{
    $normalized = normalize_phone($value);
    if ($normalized === null) {
        $digits = phone_digits($value);
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '8') && strlen($digits) >= 1) {
            $digits = '7' . substr($digits, 1);
        } elseif (!str_starts_with($digits, '7')) {
            $digits = '7' . $digits;
        }
        $national = substr($digits, 1, 10);
    } else {
        $national = substr($normalized, 2);
    }

    $parts = [
        substr($national, 0, 3),
        substr($national, 3, 3),
        substr($national, 6, 2),
        substr($national, 8, 2),
    ];
    $formatted = '+7';
    if ($parts[0] !== '') {
        $formatted .= ' ' . $parts[0];
    }
    if ($parts[1] !== '') {
        $formatted .= ' ' . $parts[1];
    }
    if ($parts[2] !== '') {
        $formatted .= '-' . $parts[2];
    }
    if ($parts[3] !== '') {
        $formatted .= '-' . $parts[3];
    }

    return $formatted;
}

/**
 * Extract Telegram username without @. Empty string when missing.
 */
function telegram_username(?string $value): string
{
    $raw = trim((string) $value);
    if ($raw === '' || $raw === '@') {
        return '';
    }

    $raw = preg_replace('#^https?://(t\.me|telegram\.me)/#i', '', $raw) ?? $raw;
    $raw = preg_replace('#^t\.me/#i', '', $raw) ?? $raw;
    $raw = ltrim(trim($raw), '@');
    $raw = explode('?', $raw, 2)[0];
    $raw = explode('/', $raw, 2)[0];
    $raw = preg_replace('/[^a-zA-Z0-9_]/', '', $raw) ?? '';

    return $raw;
}

/**
 * Normalize Telegram to username without @, or null when empty/invalid.
 */
function normalize_telegram(?string $value): ?string
{
    $username = telegram_username($value);
    if ($username === '') {
        return null;
    }

    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{4,31}$/', $username)) {
        return null;
    }

    return $username;
}

/**
 * Display Telegram as @username. Empty string when missing.
 */
function format_telegram(?string $value): string
{
    $username = telegram_username($value);
    return $username === '' ? '' : '@' . $username;
}

/**
 * Normalize email or null when empty/invalid.
 */
function normalize_email(?string $value): ?string
{
    $email = trim((string) $value);
    if ($email === '') {
        return null;
    }

    $email = mb_strtolower($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    return $email;
}

/**
 * Resolve address and coordinates for a home group from a typed address.
 *
 * Coordinates are derived server-side via forward geocoding; they are never
 * accepted from manual form input.
 *
 * @param callable(string): ?array $forwardGeocode
 * @return array{address:?string,latitude:?float,longitude:?float}
 */
function resolve_group_location(?string $address, callable $forwardGeocode): array
{
    $address = trim((string) $address) ?: null;
    if ($address === null) {
        return ['address' => null, 'latitude' => null, 'longitude' => null];
    }

    $forward = $forwardGeocode($address);
    if ($forward) {
        return [
            'address' => trim((string) ($forward['address'] ?? $address)) ?: $address,
            'latitude' => isset($forward['latitude']) ? (float) $forward['latitude'] : null,
            'longitude' => isset($forward['longitude']) ? (float) $forward['longitude'] : null,
        ];
    }

    return ['address' => $address, 'latitude' => null, 'longitude' => null];
}

/**
 * Whether a participant join request has enough location data.
 */
function participant_location_is_present(?string $address, ?float $latitude, ?float $longitude): bool
{
    if (trim((string) $address) !== '') {
        return true;
    }

    return $latitude !== null && $longitude !== null;
}

/**
 * Preferred address line from a participant request row (new address field or legacy district).
 */
function participant_request_address_line(?array $request): ?string
{
    if (!$request) {
        return null;
    }

    $address = trim((string) ($request['address'] ?? ''));
    if ($address !== '') {
        return $address;
    }

    $district = trim((string) ($request['district'] ?? ''));

    return $district !== '' ? $district : null;
}
