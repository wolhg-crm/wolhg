<?php

declare(strict_types=1);

namespace ChurchCare;

/**
 * @return array<string, string>
 */
function home_group_age_categories(): array
{
    return [
        'adults' => 'Взрослые',
        'youth_under25' => 'Молодёжные (до 25)',
        'youth_over25' => 'Молодёжные (после 25)',
        'teens' => 'Подростки',
    ];
}

/**
 * @return array<string, string>
 */
function home_group_types(): array
{
    return [
        'female' => 'Женская',
        'male' => 'Мужская',
        'general' => 'Общая',
        'family' => 'Семейная',
        'thematic' => 'Тематическая',
        'english' => 'Англоязычная',
    ];
}

/**
 * @return array<int, string> 1 = Monday .. 7 = Sunday (ISO)
 */
function home_group_weekdays(): array
{
    return [
        1 => 'Понедельник',
        2 => 'Вторник',
        3 => 'Среда',
        4 => 'Четверг',
        5 => 'Пятница',
        6 => 'Суббота',
        7 => 'Воскресенье',
    ];
}

/**
 * @return list<string>
 */
function home_group_time_filter_options(): array
{
    return ['10:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00', '22:00'];
}

/**
 * @param mixed $value
 * @return list<string>
 */
function home_group_types_from_value(mixed $value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($value)) {
        return [];
    }

    $allowed = array_keys(home_group_types());
    $result = [];
    foreach ($value as $item) {
        $item = (string) $item;
        if (in_array($item, $allowed, true) && !in_array($item, $result, true)) {
            $result[] = $item;
        }
    }

    return $result;
}

/**
 * @param list<string>|null $selected
 */
function home_group_types_label(?array $selected): string
{
    $selected = $selected ?? [];
    if ($selected === []) {
        return 'Не указано';
    }

    $labels = home_group_types();
    $parts = [];
    foreach ($selected as $type) {
        if (isset($labels[$type])) {
            $parts[] = $labels[$type];
        }
    }

    return $parts === [] ? 'Не указано' : implode(', ', $parts);
}

function home_group_age_label(?string $ageCategory): string
{
    if ($ageCategory === null || $ageCategory === '') {
        return '';
    }

    return home_group_age_categories()[$ageCategory] ?? '';
}

function home_group_weekday_label(?int $weekday): string
{
    if ($weekday === null || $weekday < 1 || $weekday > 7) {
        return '';
    }

    return home_group_weekdays()[$weekday] ?? '';
}

function home_group_short_address(?array $group): string
{
    if (!$group) {
        return '';
    }

    $short = trim((string) ($group['short_address'] ?? ''));
    if ($short !== '') {
        return $short;
    }

    // Legacy fallback for rows that still have district filled before short_address existed.
    $district = trim((string) ($group['district'] ?? ''));
    if ($district !== '') {
        return $district;
    }

    $address = trim((string) ($group['address'] ?? ''));
    if ($address === '') {
        return '';
    }

    $parts = array_map('trim', explode(',', $address));
    $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));

    return implode(' / ', array_slice($parts, 0, 3));
}

/**
 * Match a free-text location query against short address, full address, or legacy district.
 */
function home_group_matches_location_query(array $group, string $query): bool
{
    $query = trim($query);
    if ($query === '') {
        return false;
    }

    foreach (['short_address', 'address', 'district'] as $field) {
        $value = trim((string) ($group[$field] ?? ''));
        if ($value !== '' && mb_stripos($value, $query) !== false) {
            return true;
        }
    }

    return false;
}

function home_group_meeting_time_label(?string $start, ?string $end): string
{
    $start = home_group_time_hm($start);
    $end = home_group_time_hm($end);
    if ($start === '' && $end === '') {
        return '';
    }
    if ($start !== '' && $end !== '' && $start !== $end) {
        return $start . '–' . $end;
    }

    return $start !== '' ? $start : $end;
}

function home_group_time_hm(?string $time): string
{
    if ($time === null || $time === '') {
        return '';
    }

    if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches)) {
        return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
    }

    return '';
}

function home_group_schedule_label(?array $group): string
{
    if (!$group) {
        return '';
    }

    $weekday = home_group_weekday_label(isset($group['meeting_weekday']) ? (int) $group['meeting_weekday'] : null);
    if ($weekday === '' && !empty($group['weekday'])) {
        $weekday = trim((string) $group['weekday']);
    }
    $time = home_group_meeting_time_label(
        isset($group['meeting_time_start']) ? (string) $group['meeting_time_start'] : null,
        isset($group['meeting_time_end']) ? (string) $group['meeting_time_end'] : null
    );

    if ($weekday !== '' && $time !== '') {
        return $weekday . ', ' . $time;
    }

    return $weekday !== '' ? $weekday : $time;
}

/**
 * @param array<string, mixed> $input
 * @return array{
 *   ages: list<string>,
 *   weekdays: list<int>,
 *   types: list<string>,
 *   times: list<string>,
 *   church_ids: list<int>,
 *   time_from: ?string,
 *   time_to: ?string
 * }
 */
function parse_home_group_catalog_filters(array $input): array
{
    $allowedAges = array_keys(home_group_age_categories());
    $allowedTypes = array_keys(home_group_types());
    $allowedTimes = home_group_time_filter_options();

    $ages = [];
    foreach ((array) ($input['age'] ?? []) as $age) {
        $age = (string) $age;
        if ($age !== '' && in_array($age, $allowedAges, true) && !in_array($age, $ages, true)) {
            $ages[] = $age;
        }
    }

    $weekdays = [];
    foreach ((array) ($input['weekday'] ?? []) as $weekday) {
        $weekday = (int) $weekday;
        if ($weekday >= 1 && $weekday <= 7 && !in_array($weekday, $weekdays, true)) {
            $weekdays[] = $weekday;
        }
    }

    $types = [];
    foreach ((array) ($input['type'] ?? []) as $type) {
        $type = (string) $type;
        if ($type !== '' && in_array($type, $allowedTypes, true) && !in_array($type, $types, true)) {
            $types[] = $type;
        }
    }

    $times = [];
    foreach ((array) ($input['time'] ?? []) as $time) {
        $time = home_group_time_hm((string) $time);
        if ($time !== '' && in_array($time, $allowedTimes, true) && !in_array($time, $times, true)) {
            $times[] = $time;
        }
    }

    $churchIds = [];
    foreach ((array) ($input['churches'] ?? []) as $churchId) {
        $churchId = (int) $churchId;
        if ($churchId > 0 && !in_array($churchId, $churchIds, true)) {
            $churchIds[] = $churchId;
        }
    }

    $timeFrom = home_group_time_hm(isset($input['time_from']) ? (string) $input['time_from'] : null);
    $timeTo = home_group_time_hm(isset($input['time_to']) ? (string) $input['time_to'] : null);
    if ($timeFrom === '') {
        $timeFrom = null;
    }
    if ($timeTo === '') {
        $timeTo = null;
    }
    if ($timeFrom !== null && $timeTo !== null && $timeFrom > $timeTo) {
        [$timeFrom, $timeTo] = [$timeTo, $timeFrom];
    }

    return home_group_catalog_normalize_filters([
        'ages' => $ages,
        'weekdays' => $weekdays,
        'types' => $types,
        'times' => $times,
        'church_ids' => $churchIds,
        'time_from' => $timeFrom,
        'time_to' => $timeTo,
    ]);
}

/**
 * @return array{
 *   ages: list<string>,
 *   weekdays: list<int>,
 *   types: list<string>,
 *   times: list<string>,
 *   church_ids: list<int>,
 *   time_from: ?string,
 *   time_to: ?string
 * }
 */
function home_group_catalog_default_filters(): array
{
    return [
        'ages' => [],
        'weekdays' => [],
        'types' => [],
        'times' => [],
        'church_ids' => [],
        'time_from' => null,
        'time_to' => null,
    ];
}

/**
 * @param ?array<string, mixed> $filters
 * @return array{
 *   ages: list<string>,
 *   weekdays: list<int>,
 *   types: list<string>,
 *   times: list<string>,
 *   church_ids: list<int>,
 *   time_from: ?string,
 *   time_to: ?string
 * }
 */
function home_group_catalog_normalize_filters(?array $filters): array
{
    $normalized = home_group_catalog_default_filters();
    if ($filters === null) {
        return $normalized;
    }

    foreach (['ages', 'weekdays', 'types', 'times', 'church_ids'] as $listKey) {
        $value = $filters[$listKey] ?? [];
        if (!is_array($value)) {
            $normalized[$listKey] = [];
            continue;
        }
        if ($listKey === 'church_ids' || $listKey === 'weekdays') {
            $ids = [];
            foreach ($value as $item) {
                $id = (int) $item;
                if ($id > 0 && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
            $normalized[$listKey] = $ids;
            continue;
        }
        $normalized[$listKey] = array_values($value);
    }

    foreach (['time_from', 'time_to'] as $timeKey) {
        $value = $filters[$timeKey] ?? null;
        if (!is_string($value) || $value === '') {
            $normalized[$timeKey] = null;
            continue;
        }
        $normalized[$timeKey] = $value;
    }

    return $normalized;
}

/**
 * @param ?array<string, mixed> $filters
 * @return array{0:string,1:list<mixed>}
 */
function home_group_catalog_filter_sql(?array $filters, string $alias = 'g'): array
{
    $filters = home_group_catalog_normalize_filters($filters);
    $sql = [];
    $params = [];

    if ($filters['ages'] !== []) {
        $placeholders = implode(', ', array_fill(0, count($filters['ages']), '?'));
        $sql[] = "$alias.age_category IN ($placeholders)";
        foreach ($filters['ages'] as $age) {
            $params[] = $age;
        }
    }

    if ($filters['weekdays'] !== []) {
        $placeholders = implode(', ', array_fill(0, count($filters['weekdays']), '?'));
        $sql[] = "$alias.meeting_weekday IN ($placeholders)";
        foreach ($filters['weekdays'] as $weekday) {
            $params[] = $weekday;
        }
    }

    if ($filters['types'] !== []) {
        $typeSql = [];
        foreach ($filters['types'] as $type) {
            $typeSql[] = "JSON_CONTAINS($alias.group_types, JSON_QUOTE(?), '$')";
            $params[] = $type;
        }
        $sql[] = '(' . implode(' OR ', $typeSql) . ')';
    }

    if ($filters['church_ids'] !== []) {
        $placeholders = implode(', ', array_fill(0, count($filters['church_ids']), '?'));
        $sql[] = "$alias.church_id IN ($placeholders)";
        foreach ($filters['church_ids'] as $churchId) {
            $params[] = $churchId;
        }
    }

    $timeSql = [];
    if ($filters['times'] !== []) {
        foreach ($filters['times'] as $time) {
            $timeSql[] = "TIME_FORMAT($alias.meeting_time_start, '%H:%i') = ?";
            $params[] = $time;
        }
    }

    if ($filters['time_from'] !== null || $filters['time_to'] !== null) {
        $from = $filters['time_from'] ?? '00:00';
        $to = $filters['time_to'] ?? '23:59';
        $timeSql[] = "($alias.meeting_time_start IS NOT NULL AND $alias.meeting_time_start <= ? AND COALESCE($alias.meeting_time_end, $alias.meeting_time_start) >= ?)";
        $params[] = $to . ':00';
        $params[] = $from . ':00';
    }

    if ($timeSql !== []) {
        $sql[] = '(' . implode(' OR ', $timeSql) . ')';
    }

    if ($sql === []) {
        return ['', []];
    }

    return [implode(' AND ', $sql), $params];
}

/**
 * Public catalog church label (city preferred).
 *
 * @param array<string, mixed> $row
 */
function home_group_catalog_church_label(array $row): string
{
    $city = trim((string) ($row['church_city'] ?? ''));
    if ($city !== '') {
        return $city;
    }
    $name = trim((string) ($row['church_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $slug = trim((string) ($row['church_slug'] ?? ''));

    return $slug;
}

/**
 * @return array<string, mixed>
 */
function home_group_catalog_row(array $row): array
{
    $types = home_group_types_from_value($row['group_types'] ?? null);
    $leaderName = trim((string) ($row['leader_name'] ?? ''));
    if ($leaderName === '' && !empty($row['name'])) {
        $leaderName = (string) $row['name'];
    }
    $churchLabel = home_group_catalog_church_label($row);

    return [
        'id' => (int) $row['id'],
        'church_id' => (int) ($row['church_id'] ?? 0),
        'church_name' => (string) ($row['church_name'] ?? ''),
        'church_city' => (string) ($row['church_city'] ?? ''),
        'church_slug' => (string) ($row['church_slug'] ?? ''),
        'church_label' => $churchLabel,
        'name' => (string) ($row['name'] ?? ''),
        'leader_name' => $leaderName,
        'types_label' => home_group_types_label($types),
        'types' => $types,
        'age_category' => (string) ($row['age_category'] ?? ''),
        'age_label' => home_group_age_label(isset($row['age_category']) ? (string) $row['age_category'] : null),
        'schedule_label' => home_group_schedule_label($row),
        'short_address' => home_group_short_address($row),
        'address' => (string) ($row['address'] ?? ''),
        'latitude' => isset($row['latitude']) ? (float) $row['latitude'] : null,
        'longitude' => isset($row['longitude']) ? (float) $row['longitude'] : null,
        'join_url' => '/homegroup/join?group=' . (int) $row['id'],
        'people_count' => (int) ($row['people_count'] ?? 0),
        'max_people' => (int) ($row['max_people'] ?? 0),
    ];
}

/**
 * @param array<string, mixed> $post
 * @return array{
 *   age_category: ?string,
 *   group_types: list<string>,
 *   meeting_weekday: ?int,
 *   meeting_time_start: ?string,
 *   meeting_time_end: ?string,
 *   short_address: ?string
 * }
 */
function home_group_settings_from_post(array $post): array
{
    $age = trim((string) ($post['age_category'] ?? ''));
    $allowedAges = array_keys(home_group_age_categories());
    $ageCategory = in_array($age, $allowedAges, true) ? $age : null;

    $weekday = (int) ($post['meeting_weekday'] ?? 0);
    $meetingWeekday = ($weekday >= 1 && $weekday <= 7) ? $weekday : null;

    $types = [];
    foreach ((array) ($post['group_types'] ?? []) as $type) {
        $type = (string) $type;
        if ($type !== '' && !in_array($type, $types, true)) {
            $types[] = $type;
        }
    }
    $types = home_group_types_from_value($types);

    $timeStart = home_group_time_hm(isset($post['meeting_time_start']) ? (string) $post['meeting_time_start'] : null);
    $timeEnd = home_group_time_hm(isset($post['meeting_time_end']) ? (string) $post['meeting_time_end'] : null);
    $shortAddress = trim((string) ($post['short_address'] ?? '')) ?: null;

    return [
        'age_category' => $ageCategory,
        'group_types' => $types,
        'meeting_weekday' => $meetingWeekday,
        'meeting_time_start' => $timeStart !== '' ? $timeStart . ':00' : null,
        'meeting_time_end' => $timeEnd !== '' ? $timeEnd . ':00' : null,
        'short_address' => $shortAddress,
        'weekday' => $meetingWeekday ? home_group_weekday_label($meetingWeekday) : null,
    ];
}

function home_group_types_json(array $types): string
{
    return json_encode(home_group_types_from_value($types), JSON_UNESCAPED_UNICODE) ?: '[]';
}
