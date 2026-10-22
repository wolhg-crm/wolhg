<?php

declare(strict_types=1);

/**
 * Church districts: persistence, access helpers, CRM page and POST handlers.
 */

function districts_table_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        fetch_one('SELECT 1 FROM districts LIMIT 1');
        $ready = true;
    } catch (Throwable) {
        $ready = false;
    }

    return $ready;
}

function church_has_active_districts(int $churchId): bool
{
    if ($churchId <= 0 || !districts_table_ready()) {
        return false;
    }
    $row = fetch_one(
        'SELECT 1 AS ok FROM districts WHERE church_id = ? AND is_active = 1 LIMIT 1',
        [$churchId]
    );

    return (bool) $row;
}

/**
 * @return list<int>
 */
function user_district_ids(int $userId): array
{
    if ($userId <= 0 || !districts_table_ready()) {
        return [];
    }
    $rows = fetch_all(
        'SELECT district_id FROM district_leaders WHERE user_id = ?',
        [$userId]
    );

    return array_map(static fn (array $r): int => (int) $r['district_id'], $rows);
}

function can_manage_districts(array $user): bool
{
    return in_array($user['role'] ?? '', ['pastor', 'admin', 'super_admin'], true);
}

function can_view_districts_page(array $user): bool
{
    return can_manage_districts($user)
        || ($user['role'] ?? '') === 'district_leader';
}

/**
 * @return list<string>
 */
function district_color_palette(): array
{
    return ['#E87722', '#2F6FED', '#1A9B6C', '#C43C3C', '#7A4FBF', '#0F8A8A', '#B8860B', '#5A6A7A'];
}

function district_color_for_index(int $index): string
{
    $palette = district_color_palette();

    return $palette[$index % count($palette)];
}

/**
 * @return list<array<string, mixed>>
 */
function districts_for_church(int $churchId, bool $activeOnly = true): array
{
    if ($churchId <= 0 || !districts_table_ready()) {
        return [];
    }
    $sql = 'SELECT * FROM districts WHERE church_id = ?';
    $params = [$churchId];
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, name ASC';

    return fetch_all($sql, $params);
}

/**
 * @return array<string, mixed>|null
 */
function district_by_id(int $districtId, ?int $churchId = null): ?array
{
    if ($districtId <= 0 || !districts_table_ready()) {
        return null;
    }
    if ($churchId !== null && $churchId > 0) {
        return fetch_one('SELECT * FROM districts WHERE id = ? AND church_id = ? LIMIT 1', [$districtId, $churchId]);
    }

    return fetch_one('SELECT * FROM districts WHERE id = ? LIMIT 1', [$districtId]);
}

/**
 * @param list<int> $userIds
 */
function district_set_leaders(int $districtId, array $userIds): void
{
    execute('DELETE FROM district_leaders WHERE district_id = ?', [$districtId]);
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
    foreach ($userIds as $userId) {
        execute(
            'INSERT INTO district_leaders (district_id, user_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)',
            [$districtId, $userId]
        );
    }
}

/**
 * @return list<array<string, mixed>>
 */
function district_leaders_for_district(int $districtId): array
{
    return fetch_all(
        'SELECT u.id, u.name, u.email, u.role
         FROM district_leaders dl
         INNER JOIN users u ON u.id = dl.user_id
         WHERE dl.district_id = ?
         ORDER BY u.name',
        [$districtId]
    );
}

/**
 * @return list<int>
 */
function district_leader_ids(int $districtId): array
{
    return array_map(
        static fn (array $r): int => (int) $r['id'],
        district_leaders_for_district($districtId)
    );
}

/**
 * Create a district from GeoJSON geometry.
 *
 * @param array<string, mixed> $geojson
 */
function district_create(
    int $churchId,
    string $name,
    array $geojson,
    string $source = 'manual',
    ?string $externalRef = null,
    ?string $color = null,
    int $sortOrder = 0
): int {
    $normalized = ChurchCare\normalize_district_geojson($geojson);
    if ($normalized === null) {
        throw new InvalidArgumentException('Некорректный полигон округа');
    }
    $bbox = ChurchCare\geojson_bbox($normalized);
    if ($bbox === null) {
        throw new InvalidArgumentException('Не удалось вычислить границы округа');
    }
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Укажите название округа');
    }
    if ($color === null || $color === '') {
        $count = (int) (fetch_one('SELECT COUNT(*) AS c FROM districts WHERE church_id = ?', [$churchId])['c'] ?? 0);
        $color = district_color_for_index($count);
    }
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new InvalidArgumentException('Не удалось сохранить полигон');
    }
    execute(
        'INSERT INTO districts
         (church_id, name, source, external_ref, polygon_geojson, bbox_min_lat, bbox_min_lon, bbox_max_lat, bbox_max_lon, color, is_active, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
        [
            $churchId,
            $name,
            $source === 'osm' ? 'osm' : 'manual',
            $externalRef,
            $json,
            $bbox['min_lat'],
            $bbox['min_lon'],
            $bbox['max_lat'],
            $bbox['max_lon'],
            $color,
            $sortOrder,
        ]
    );

    return (int) db()->lastInsertId();
}

/**
 * @param array<string, mixed> $geojson
 */
function district_update_geometry(int $districtId, array $geojson, ?string $name = null): void
{
    $normalized = ChurchCare\normalize_district_geojson($geojson);
    if ($normalized === null) {
        throw new InvalidArgumentException('Некорректный полигон округа');
    }
    $bbox = ChurchCare\geojson_bbox($normalized);
    if ($bbox === null) {
        throw new InvalidArgumentException('Не удалось вычислить границы округа');
    }
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new InvalidArgumentException('Не удалось сохранить полигон');
    }
    if ($name !== null) {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Укажите название округа');
        }
        execute(
            'UPDATE districts SET name = ?, polygon_geojson = ?, bbox_min_lat = ?, bbox_min_lon = ?, bbox_max_lat = ?, bbox_max_lon = ? WHERE id = ?',
            [$name, $json, $bbox['min_lat'], $bbox['min_lon'], $bbox['max_lat'], $bbox['max_lon'], $districtId]
        );
    } else {
        execute(
            'UPDATE districts SET polygon_geojson = ?, bbox_min_lat = ?, bbox_min_lon = ?, bbox_max_lat = ?, bbox_max_lon = ? WHERE id = ?',
            [$json, $bbox['min_lat'], $bbox['min_lon'], $bbox['max_lat'], $bbox['max_lon'], $districtId]
        );
    }
}

function district_deactivate(int $districtId): void
{
    execute('UPDATE districts SET is_active = 0 WHERE id = ?', [$districtId]);
    execute('UPDATE home_groups SET district_id = NULL WHERE district_id = ?', [$districtId]);
}

/**
 * Resolve district for coordinates within a church.
 */
function resolve_church_district_id(int $churchId, ?float $lat, ?float $lon): ?int
{
    if ($churchId <= 0 || $lat === null || $lon === null || !districts_table_ready()) {
        return null;
    }
    $districts = districts_for_church($churchId, true);
    if ($districts === []) {
        return null;
    }

    return ChurchCare\resolve_district_for_point($districts, $lat, $lon);
}

/**
 * Assign district_id on a home group and notify leaders when it changes to a new district.
 *
 * @param array<string, mixed>|null $previousGroup
 */
function home_group_apply_district(
    int $groupId,
    int $churchId,
    ?float $lat,
    ?float $lon,
    ?int $manualDistrictId = null,
    bool $notify = true,
    ?array $previousGroup = null
): ?int {
    if (!districts_table_ready()) {
        return null;
    }

    $districtId = null;
    if ($manualDistrictId !== null && $manualDistrictId > 0) {
        $row = district_by_id($manualDistrictId, $churchId);
        $districtId = $row ? (int) $row['id'] : null;
    } else {
        $districtId = resolve_church_district_id($churchId, $lat, $lon);
    }

    execute('UPDATE home_groups SET district_id = ? WHERE id = ?', [$districtId, $groupId]);

    $prevId = $previousGroup !== null ? (int) ($previousGroup['district_id'] ?? 0) : 0;
    if ($notify && $districtId !== null && $districtId !== $prevId) {
        notify_district_leaders_new_group($districtId, $groupId);
    }

    return $districtId;
}

function notify_district_leaders_new_group(int $districtId, int $groupId): void
{
    $district = district_by_id($districtId);
    $group = fetch_one('SELECT id, name FROM home_groups WHERE id = ? LIMIT 1', [$groupId]);
    if (!$district || !$group) {
        return;
    }
    $leaders = district_leaders_for_district($districtId);
    $title = 'Новая домашняя группа в округе';
    $body = 'В округе «' . $district['name'] . '» появилась группа «' . $group['name'] . '».';
    $path = '/group?id=' . $groupId;
    foreach ($leaders as $leader) {
        queue_email_notification(
            (int) $leader['id'],
            (string) $leader['email'],
            $title,
            $body . ' Откройте: ' . (function_exists('public_url') ? public_url(app_path($path)) : $path),
            'district_group_assigned'
        );
        if (function_exists('queue_bot_notification')) {
            queue_bot_notification((int) $leader['id'], $title, $body, $path);
        }
    }
}

/**
 * @return list<array<string, mixed>>
 */
function district_candidate_users(int $churchId): array
{
    return fetch_all(
        "SELECT id, name, email, role FROM users
         WHERE church_id = ? AND role IN ('district_leader','pastor','admin')
         ORDER BY FIELD(role, 'district_leader','pastor','admin'), name",
        [$churchId]
    );
}

function districts_page(array $user): void
{
    redirect_member_to_own_card($user);
    if (!can_view_districts_page($user)) {
        http_response_code(403);
        exit('Недостаточно прав');
    }

    $churchId = (int) (effective_scope_church_id($user) ?? ($user['church_id'] ?? 0));
    $church = $churchId > 0 ? fetch_one('SELECT id, name, city FROM churches WHERE id = ? LIMIT 1', [$churchId]) : null;
    $city = trim((string) ($church['city'] ?? ''));
    $manage = can_manage_districts($user);

    $districts = districts_for_church($churchId, true);
    if (($user['role'] ?? '') === 'district_leader' && !can_manage_districts($user)) {
        $allowed = user_district_ids((int) $user['id']);
        $districts = array_values(array_filter(
            $districts,
            static fn (array $d): bool => in_array((int) $d['id'], $allowed, true)
        ));
    }

    $groups = fetch_all(
        'SELECT id, name, latitude, longitude, district_id FROM home_groups WHERE church_id = ? AND is_active = 1',
        [$churchId]
    );

    $leadersByDistrict = [];
    foreach ($districts as $district) {
        $leadersByDistrict[(int) $district['id']] = district_leaders_for_district((int) $district['id']);
    }
    $candidateUsers = $manage ? district_candidate_users($churchId) : [];

    $catalog = [];
    $catalogError = '';
    if ($manage && isset($_GET['load_catalog'])) {
        if (function_exists('is_demo_session') && is_demo_session()) {
            $catalogError = 'Загрузка районов из OpenStreetMap в демо отключена.';
        } else {
        try {
            $catalog = $city !== '' ? osm_district_catalog_fetch($city) : [];
            if ($catalog === []) {
                $catalogError = 'Каталог районов пуст. Можно нарисовать округ вручную на карте.';
            }
        } catch (Throwable $e) {
            $catalogError = 'Не удалось загрузить районы: ' . $e->getMessage();
        }
        }
    }

    $mapKey = (string) (app_config('yandex.maps_api_key') ?: app_config('yandex.geocoder_key') ?: '');
    $mapDistricts = array_map(static function (array $d) use ($leadersByDistrict): array {
        $geo = json_decode((string) $d['polygon_geojson'], true);

        return [
            'id' => (int) $d['id'],
            'name' => (string) $d['name'],
            'color' => (string) ($d['color'] ?: '#E87722'),
            'geojson' => is_array($geo) ? $geo : null,
            'leader_ids' => array_map(static fn (array $l): int => (int) $l['id'], $leadersByDistrict[(int) $d['id']] ?? []),
            'leaders' => array_map(static fn (array $l): string => (string) $l['name'], $leadersByDistrict[(int) $d['id']] ?? []),
        ];
    }, $districts);

    $mapGroups = array_values(array_filter(array_map(static function (array $g): ?array {
        if ($g['latitude'] === null || $g['longitude'] === null) {
            return null;
        }

        return [
            'id' => (int) $g['id'],
            'name' => (string) $g['name'],
            'latitude' => (float) $g['latitude'],
            'longitude' => (float) $g['longitude'],
            'district_id' => $g['district_id'] !== null ? (int) $g['district_id'] : null,
        ];
    }, $groups)));

    page_header('Округа города', $user);
    echo '<section class="panel stack">';
    echo '<p class="muted">Округа задают зоны ответственности окружных лидеров. Границы — полигоны на карте; домашние группы с координатами привязываются автоматически.</p>';
    if ($city !== '') {
        echo '<p>Город церкви: <strong>' . e($city) . '</strong></p>';
    } else {
        echo '<p class="muted">Укажите город в настройках церкви, чтобы подтянуть районы из OpenStreetMap.</p>';
    }

    if ($manage) {
        echo '<div class="row gap">';
        if (!(function_exists('is_demo_session') && is_demo_session())) {
            echo '<a class="button" href="' . e(app_path('/districts?load_catalog=1')) . '">Загрузить районы</a>';
        }
        echo '</div>';
    }

    if ($catalogError !== '') {
        echo '<p class="flash">' . e($catalogError) . '</p>';
    }

    if ($manage && $catalog !== []) {
        echo '<form method="post" action="' . e(app_path('/districts/import')) . '" class="stack" data-districts-catalog>';
        echo csrf_field();
        echo '<h2>Выберите районы</h2>';
        echo '<p class="muted">Отметьте районы — они появятся на карте. После сохранения их можно поправить или дорисовать.</p>';
        echo '<div class="district-catalog-list">';
        foreach ($catalog as $candidate) {
            $cid = (string) $candidate['id'];
            $geoJson = json_encode($candidate['geojson'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            $geoB64 = base64_encode($geoJson);
            echo '<label class="district-catalog-item">';
            echo '<input type="checkbox" name="catalog_ids[]" value="' . e($cid) . '" data-catalog-id="' . e($cid) . '" data-catalog-name="' . e($candidate['name']) . '" data-catalog-geojson-b64="' . e($geoB64) . '"> ';
            echo e($candidate['name']);
            echo '<input type="hidden" name="catalog_name[' . e($cid) . ']" value="' . e($candidate['name']) . '">';
            echo '<input type="hidden" name="catalog_geojson_b64[' . e($cid) . ']" value="' . e($geoB64) . '">';
            echo '<input type="hidden" name="catalog_ref[' . e($cid) . ']" value="' . e($cid) . '">';
            echo '</label>';
        }
        echo '</div>';
        echo '<button class="primary" type="submit">Сохранить выбранные</button>';
        echo '</form>';
    }

    echo '<div class="districts-layout">';
    echo '<div class="districts-sidebar stack">';
    echo '<h2>Сохранённые округа</h2>';
    if ($districts === []) {
        echo '<p class="muted">Пока нет округов.</p>';
    }
    foreach ($districts as $district) {
        $id = (int) $district['id'];
        $leaders = $leadersByDistrict[$id] ?? [];
        echo '<article class="panel district-card" data-district-id="' . $id . '">';
        echo '<h3>' . e((string) $district['name']) . '</h3>';
        echo '<p class="muted">Лидеры: ' . e($leaders === [] ? 'не назначены' : implode(', ', array_map(static fn (array $l): string => (string) $l['name'], $leaders))) . '</p>';
        if ($manage) {
            echo '<form method="post" action="' . e(app_path('/districts/update')) . '" class="stack">';
            echo csrf_field();
            echo '<input type="hidden" name="id" value="' . $id . '">';
            echo '<label>Название<input name="name" value="' . e((string) $district['name']) . '" required></label>';
            echo '<label>Полигон (GeoJSON)<textarea name="polygon_geojson" rows="4" data-district-geojson-field="' . $id . '">' . e((string) $district['polygon_geojson']) . '</textarea></label>';
            echo '<label>Окружные лидеры<select name="leader_ids[]" multiple size="4">';
            $selected = district_leader_ids($id);
            foreach ($candidateUsers as $cu) {
                $sel = in_array((int) $cu['id'], $selected, true) ? ' selected' : '';
                echo '<option value="' . (int) $cu['id'] . '"' . $sel . '>' . e((string) $cu['name']) . ' (' . e(role_label((string) $cu['role'])) . ')</option>';
            }
            echo '</select></label>';
            echo '<div class="row gap"><button class="primary" type="submit">Сохранить</button></div>';
            echo '</form>';
            echo '<form method="post" action="' . e(app_path('/districts/delete')) . '" onsubmit="return confirm(\'Деактивировать округ?\');">';
            echo csrf_field();
            echo '<input type="hidden" name="id" value="' . $id . '">';
            echo '<button type="submit" class="danger">Деактивировать</button>';
            echo '</form>';
        }
        echo '</article>';
    }

    if ($manage) {
        echo '<article class="panel stack">';
        echo '<h2>Нарисовать свой округ</h2>';
        echo '<p class="muted">Нарисуйте полигон на карте или вставьте GeoJSON вручную.</p>';
        echo '<form method="post" action="' . e(app_path('/districts/create')) . '" class="stack" data-district-draw-form>';
        echo csrf_field();
        echo '<label>Название<input name="name" required placeholder="Например, Серебрянка"></label>';
        echo '<label>Полигон (GeoJSON)<textarea name="polygon_geojson" rows="5" required data-district-draw-geojson></textarea></label>';
        echo '<button class="primary" type="submit">Создать округ</button>';
        echo '</form>';
        echo '</article>';
    }
    echo '</div>';

    echo '<div class="districts-map-wrap panel" data-districts-map'
        . ' data-map-key="' . e($mapKey) . '"'
        . ' data-can-edit="' . ($manage ? '1' : '0') . '"'
        . ' data-districts="' . e(json_encode($mapDistricts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]') . '"'
        . ' data-groups="' . e(json_encode($mapGroups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]') . '">';
    if ($mapKey === '') {
        echo '<p class="muted">Карта недоступна: не задан ключ Яндекс.Карт.</p>';
    } else {
        echo '<div id="districts-map" class="districts-map" style="min-height:420px;width:100%"></div>';
        echo '<p class="muted">Сохранённые округа, превью выбранных районов и домашние группы.</p>';
        if ($manage) {
            echo '<div class="row gap"><button type="button" class="button" data-district-draw-start>Режим рисования</button>';
            echo '<button type="button" class="button" data-district-draw-finish hidden>Завершить контур</button></div>';
        }
    }
    echo '</div>';
    echo '</div>';
    echo '</section>';
    page_footer();
}

function handle_districts_post(string $path, string $method, array $user): void
{
    if ($method !== 'POST') {
        return;
    }
    if (!str_starts_with($path, '/districts/')) {
        return;
    }
    verify_csrf();
    if (!can_manage_districts($user)) {
        ajax_redirect('/districts', 'Недостаточно прав');
    }
    $churchId = (int) (effective_scope_church_id($user) ?? ($user['church_id'] ?? 0));
    if ($churchId <= 0) {
        ajax_redirect('/districts', 'Церковь не назначена');
    }

    if ($path === '/districts/import') {
        $ids = $_POST['catalog_ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            ajax_redirect('/districts?load_catalog=1', 'Выберите хотя бы один район');
        }
        $created = 0;
        foreach ($ids as $cid) {
            $cid = (string) $cid;
            $name = trim((string) ($_POST['catalog_name'][$cid] ?? ''));
            $geoB64 = (string) ($_POST['catalog_geojson_b64'][$cid] ?? '');
            $ref = trim((string) ($_POST['catalog_ref'][$cid] ?? $cid));
            $geoJson = $geoB64 !== '' ? base64_decode($geoB64, true) : false;
            $geo = is_string($geoJson) ? json_decode($geoJson, true) : null;
            if ($name === '' || !is_array($geo)) {
                continue;
            }
            $existing = fetch_one(
                'SELECT id FROM districts WHERE church_id = ? AND external_ref = ? AND is_active = 1 LIMIT 1',
                [$churchId, $ref]
            );
            if ($existing) {
                continue;
            }
            try {
                district_create($churchId, $name, $geo, 'osm', $ref);
                $created++;
            } catch (InvalidArgumentException) {
                continue;
            }
        }
        audit('districts_import', 'church', $churchId, ['created' => $created]);
        ajax_redirect('/districts', 'Добавлено округов: ' . $created);
    }

    if ($path === '/districts/create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $geo = json_decode((string) ($_POST['polygon_geojson'] ?? ''), true);
        try {
            if (!is_array($geo)) {
                throw new InvalidArgumentException('Укажите полигон');
            }
            $id = district_create($churchId, $name, $geo, 'manual');
            audit('district_create', 'district', $id);
            ajax_redirect('/districts', 'Округ создан');
        } catch (InvalidArgumentException $e) {
            ajax_redirect('/districts', $e->getMessage());
        }
    }

    if ($path === '/districts/update') {
        $id = (int) ($_POST['id'] ?? 0);
        $district = district_by_id($id, $churchId);
        if (!$district) {
            ajax_redirect('/districts', 'Округ не найден');
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $geo = json_decode((string) ($_POST['polygon_geojson'] ?? ''), true);
        try {
            if (!is_array($geo)) {
                throw new InvalidArgumentException('Укажите полигон');
            }
            district_update_geometry($id, $geo, $name);
            $leaderIds = $_POST['leader_ids'] ?? [];
            if (!is_array($leaderIds)) {
                $leaderIds = [];
            }
            district_set_leaders($id, array_map('intval', $leaderIds));
            // Promote assigned users to district_leader when they are below that rank.
            foreach (array_map('intval', $leaderIds) as $leaderId) {
                $target = fetch_one('SELECT * FROM users WHERE id = ? AND church_id = ? LIMIT 1', [$leaderId, $churchId]);
                if ($target && role_rank((string) $target['role']) < role_rank('district_leader') && can_transition_role((string) $user['role'], (string) $target['role'], 'district_leader')) {
                    execute('UPDATE users SET role = ? WHERE id = ?', ['district_leader', $leaderId]);
                    notify_role_change($target, 'district_leader');
                }
            }
            audit('district_update', 'district', $id);
            ajax_redirect('/districts', 'Округ сохранён');
        } catch (InvalidArgumentException $e) {
            ajax_redirect('/districts', $e->getMessage());
        }
    }

    if ($path === '/districts/delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $district = district_by_id($id, $churchId);
        if (!$district) {
            ajax_redirect('/districts', 'Округ не найден');
        }
        district_deactivate($id);
        audit('district_delete', 'district', $id);
        ajax_redirect('/districts', 'Округ деактивирован');
    }
}
