<?php

declare(strict_types=1);

/**
 * Per-browser demo church workspaces under public slug /demo.
 *
 * Template church (slug=demo, demo_token NULL) is never entered.
 * Each visitor gets a clone identified by HttpOnly cookie wolhg_demo (= demo_token).
 */

const DEMO_COOKIE_NAME = 'wolhg_demo';
const DEMO_PUBLIC_SLUG = 'demo';
const DEMO_WORKSPACE_SLUG_PREFIX = 'demo-ws-';
const DEMO_EMAIL_DOMAIN = 'demo.invalid';
const DEMO_WORKSPACE_TTL_DAYS = 14;
const DEMO_PEOPLE_LIMIT = 200;
const DEMO_ENTER_IP_LIMIT = 8;
const DEMO_ENTER_IP_WINDOW = 3600;

/**
 * @return list<string>
 */
function demo_blocked_paths(): array
{
    return [
        '/security',
        '/permissions',
        '/settings-security',
        '/vapid-generate',
        '/permissions-save',
        '/mcp-api-key-create',
        '/mcp-api-key-revoke',
        '/bot-link-token',
        '/bot-unlink',
        '/social-unlink',
        '/knowledge-save',
        '/knowledge-image-upload',
        '/church-page-save',
        '/church-page-image-upload',
        '/person-account-invite',
        '/user-password-reset',
        '/password-change',
        '/push/subscribe',
        '/homegroup/join',
        '/prayer-request',
        '/miracle-share',
        '/role-invite',
        '/account-invite',
        '/forgot-password',
        '/reset-password',
    ];
}

function demo_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $ready = db_table_exists('churches') && db_column_exists('churches', 'is_demo');
    } catch (Throwable) {
        $ready = false;
    }

    return $ready;
}

function db_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return $cache[$key] = false;
    }
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    $cache[$key] = (int) $stmt->fetchColumn() > 0;

    return $cache[$key];
}

function is_demo_church(?array $church): bool
{
    if (!$church || !demo_schema_ready()) {
        return false;
    }

    return (int) ($church['is_demo'] ?? 0) === 1;
}

function is_demo_template_church(?array $church): bool
{
    return is_demo_church($church) && trim((string) ($church['demo_token'] ?? '')) === '';
}

function is_demo_workspace_church(?array $church): bool
{
    return is_demo_church($church) && trim((string) ($church['demo_token'] ?? '')) !== '';
}

function church_is_demo_by_id(int $churchId): bool
{
    if ($churchId <= 0 || !demo_schema_ready()) {
        return false;
    }
    $row = fetch_one('SELECT is_demo FROM churches WHERE id = ? LIMIT 1', [$churchId]);

    return $row !== null && (int) ($row['is_demo'] ?? 0) === 1;
}

/**
 * SQL fragment that keeps demo churches out of platform-wide public surfaces.
 * Empty when the is_demo column is not available yet.
 */
function public_sql_exclude_demo_churches(string $alias = 'c'): string
{
    if (!demo_schema_ready()) {
        return '';
    }
    $alias = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) === 1 ? $alias : 'c';

    return ' AND ' . $alias . '.is_demo = 0';
}

/**
 * Whether a church may appear on public catalog/join/API outside /demo.
 * Demo churches are allowed only inside their own active demo workspace session.
 */
function church_visible_on_public_catalog(?array $church): bool
{
    if (!$church || (function_exists('church_is_deleted') && church_is_deleted($church))) {
        return false;
    }
    // Prefer the row flag so unit tests (and partial schemas) still hide demos.
    $isDemo = (int) ($church['is_demo'] ?? 0) === 1
        || (function_exists('is_demo_church') && is_demo_church($church));
    if (!$isDemo) {
        return true;
    }
    if (!is_demo_session()) {
        return false;
    }
    $current = function_exists('current_church') ? current_church() : null;

    return is_array($current) && (int) ($current['id'] ?? 0) === (int) ($church['id'] ?? 0);
}

function is_demo_user(?array $user): bool
{
    if (!$user) {
        return false;
    }
    $churchId = (int) ($user['church_id'] ?? 0);

    return church_is_demo_by_id($churchId);
}

function is_demo_session(): bool
{
    $church = current_church();
    if (is_demo_workspace_church($church)) {
        return true;
    }
    $user = function_exists('current_user') ? current_user() : null;

    return is_demo_user($user);
}

function demo_public_slug(): string
{
    return DEMO_PUBLIC_SLUG;
}

function demo_cookie_token(): ?string
{
    $raw = (string) ($_COOKIE[DEMO_COOKIE_NAME] ?? '');
    if ($raw === '' || !preg_match('/^[a-f0-9]{64}$/', $raw)) {
        return null;
    }

    return $raw;
}

function demo_set_workspace_cookie(string $token): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
    setcookie(DEMO_COOKIE_NAME, $token, [
        'expires' => time() + DEMO_WORKSPACE_TTL_DAYS * 86400,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[DEMO_COOKIE_NAME] = $token;
}

function demo_clear_workspace_cookie(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
    setcookie(DEMO_COOKIE_NAME, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[DEMO_COOKIE_NAME]);
}

/**
 * @return array<string, mixed>|null
 */
function demo_find_template_church(): ?array
{
    if (!demo_schema_ready()) {
        return null;
    }

    return fetch_one(
        'SELECT * FROM churches WHERE slug = ? AND is_demo = 1 AND (demo_token IS NULL OR demo_token = "") AND deleted_at IS NULL LIMIT 1',
        [DEMO_PUBLIC_SLUG]
    );
}

/**
 * @return array<string, mixed>|null
 */
function demo_find_workspace_by_token(string $token): ?array
{
    if (!demo_schema_ready() || $token === '') {
        return null;
    }

    return fetch_one(
        'SELECT * FROM churches WHERE demo_token = ? AND is_demo = 1 AND deleted_at IS NULL LIMIT 1',
        [$token]
    );
}

/**
 * @return array<string, mixed>|null
 */
function demo_workspace_from_cookie(): ?array
{
    $token = demo_cookie_token();
    if ($token === null) {
        return null;
    }

    return demo_find_workspace_by_token($token);
}

function demo_touch_workspace(int $churchId): void
{
    if ($churchId <= 0 || !demo_schema_ready()) {
        return;
    }
    db()->prepare('UPDATE churches SET demo_seen_at = NOW() WHERE id = ? AND is_demo = 1')->execute([$churchId]);
}

function demo_can_action(array $user, string $action): ?bool
{
    if (!is_demo_user($user) && !is_demo_session()) {
        return null;
    }
    if ($action === 'manage_structure') {
        return true;
    }
    if ($action === 'manage_security') {
        return false;
    }

    return null;
}

function demo_path_is_blocked(string $path): bool
{
    $path = '/' . trim($path, '/');
    if ($path === '//') {
        $path = '/';
    }
    foreach (demo_blocked_paths() as $blocked) {
        if ($path === $blocked || str_starts_with($path, $blocked . '/')) {
            return true;
        }
    }
    if ($path === '/districts' && isset($_GET['load_catalog'])) {
        return true;
    }

    return false;
}

function demo_deny_blocked_request(string $path): never
{
    if (function_exists('wants_ajax') && wants_ajax()) {
        json_response(['ok' => false, 'error' => 'В демо это действие недоступно.'], 403);
    }
    http_response_code(403);
    echo 'В демо это действие недоступно.';
    exit;
}

function demo_guard_request(string $path, string $method = 'GET'): void
{
    if (!is_demo_session()) {
        return;
    }
    if ($method === 'GET' || $method === 'HEAD') {
        if (in_array($path, ['/security', '/permissions'], true)) {
            demo_deny_blocked_request($path);
        }
        if ($path === '/districts' && isset($_GET['load_catalog'])) {
            demo_deny_blocked_request($path);
        }
        if (in_array($path, ['/homegroup/join', '/prayer-request', '/miracle-share'], true)) {
            demo_deny_blocked_request($path);
        }

        return;
    }
    if (demo_path_is_blocked($path)) {
        demo_deny_blocked_request($path);
    }
}

function demo_sanitize_account_email(string $email, int $workspaceChurchId): string
{
    $email = trim($email);
    if ($email !== '' && str_ends_with(strtolower($email), '@' . DEMO_EMAIL_DOMAIN)) {
        return strtolower($email);
    }

    return 'user-' . $workspaceChurchId . '-' . bin2hex(random_bytes(4)) . '@' . DEMO_EMAIL_DOMAIN;
}

function demo_people_count(int $churchId): int
{
    $row = fetch_one(
        'SELECT COUNT(*) AS c FROM people p
         INNER JOIN home_groups g ON g.id = p.group_id
         WHERE g.church_id = ? AND p.deleted_at IS NULL',
        [$churchId]
    );

    return (int) ($row['c'] ?? 0);
}

function demo_assert_people_limit(int $churchId): void
{
    if ($churchId <= 0 || !church_is_demo_by_id($churchId)) {
        return;
    }
    if (demo_people_count($churchId) >= DEMO_PEOPLE_LIMIT) {
        throw new RuntimeException('В демо не больше ' . DEMO_PEOPLE_LIMIT . ' карточек. Сбросьте демо, чтобы начать заново.');
    }
}

function demo_banner_markup(): string
{
    if (!is_demo_session()) {
        return '';
    }

    return '<section class="panel demo-banner" data-demo-banner>'
        . '<div><strong>Демо-режим</strong>'
        . '<p class="muted">Изменения видны только в этом браузере. Не вводите реальные персональные данные — копия хранится в общей базе до удаления.</p></div>'
        . '<form method="post" action="' . e('/demo/reset') . '" class="compact">' . csrf_field()
        . '<button class="ghost" type="submit" data-confirm="Сбросить демо и начать заново с учебными данными?">Сбросить демо</button></form>'
        . '</section>';
}

function demo_html_robots_meta(): void
{
    if (!is_demo_session() && !(current_church_slug() === DEMO_PUBLIC_SLUG)) {
        return;
    }
    echo '<meta name="robots" content="noindex, nofollow">';
}

/**
 * Square-ish demo district polygons around a fictional city center.
 *
 * @return array{0: string, 1: float, 2: float, 3: float, 4: float}
 */
function demo_district_polygon(float $minLat, float $minLon, float $maxLat, float $maxLon): array
{
    $geo = json_encode([
        'type' => 'Polygon',
        'coordinates' => [[
            [$minLon, $minLat],
            [$maxLon, $minLat],
            [$maxLon, $maxLat],
            [$minLon, $maxLat],
            [$minLon, $minLat],
        ]],
    ], JSON_UNESCAPED_UNICODE);

    return [(string) $geo, $minLat, $minLon, $maxLat, $maxLon];
}

/**
 * Ensure template church + seed data exist. Idempotent.
 *
 * @return array<string, mixed>
 */
function demo_ensure_template_seeded(): array
{
    if (!demo_schema_ready()) {
        throw new RuntimeException('Демо недоступно: примените миграцию 028_demo_church.sql');
    }

    $existing = demo_find_template_church();
    if ($existing) {
        $groups = (int) (fetch_one('SELECT COUNT(*) AS c FROM home_groups WHERE church_id = ?', [(int) $existing['id']])['c'] ?? 0);
        if ($groups > 0) {
            return $existing;
        }
        demo_seed_template_contents((int) $existing['id']);

        return demo_find_template_church() ?? $existing;
    }

    $consent = 'Это демонстрационная церковь с учебными данными. Не вводите реальные персональные данные.';
    db()->prepare(
        'INSERT INTO churches (name, city, slug, timezone, personal_data_consent_text, is_demo, demo_token, demo_seen_at)
         VALUES (?, ?, ?, ?, ?, 1, NULL, NULL)'
    )->execute([
        'Демонстрационная церковь',
        'Демогород',
        DEMO_PUBLIC_SLUG,
        'Europe/Moscow',
        $consent,
    ]);
    $churchId = (int) db()->lastInsertId();
    demo_seed_template_contents($churchId);

    $church = demo_find_template_church();
    if (!$church) {
        throw new RuntimeException('Не удалось создать шаблон демо-церкви');
    }

    return $church;
}

function demo_seed_template_contents(int $churchId): void
{
    $pdo = db();

    [$polyNorth, $nMinLat, $nMinLon, $nMaxLat, $nMaxLon] = demo_district_polygon(56.0200, 37.8400, 56.0350, 37.8700);
    [$polySouth, $sMinLat, $sMinLon, $sMaxLat, $sMaxLon] = demo_district_polygon(56.0000, 37.8350, 56.0150, 37.8650);

    $pdo->prepare(
        'INSERT INTO districts (church_id, name, source, external_ref, polygon_geojson, bbox_min_lat, bbox_min_lon, bbox_max_lat, bbox_max_lon, color, is_active, sort_order)
         VALUES (?, ?, "manual", ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    )->execute([$churchId, 'Северный округ', 'demo-north', $polyNorth, $nMinLat, $nMinLon, $nMaxLat, $nMaxLon, '#E87722', 10]);
    $districtNorth = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO districts (church_id, name, source, external_ref, polygon_geojson, bbox_min_lat, bbox_min_lon, bbox_max_lat, bbox_max_lon, color, is_active, sort_order)
         VALUES (?, ?, "manual", ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    )->execute([$churchId, 'Южный округ', 'demo-south', $polySouth, $sMinLat, $sMinLon, $sMaxLat, $sMaxLon, '#2F6FED', 20]);
    $districtSouth = (int) $pdo->lastInsertId();

    $stages = [
        [1, 'Новичок', 10, 0],
        [2, 'Первый месяц', 20, 0],
        [3, 'Членство', 30, 0],
        [4, 'Служение', 40, 0],
        [5, 'Лидер', 50, 1],
    ];
    $stageIds = [];
    foreach ($stages as [$key, $name, $sort, $final]) {
        $pdo->prepare('INSERT INTO spiritual_stages (church_id, name, sort_order, is_final) VALUES (?, ?, ?, ?)')
            ->execute([$churchId, $name, $sort, $final]);
        $stageIds[$key] = (int) $pdo->lastInsertId();
    }

    $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $suffix = 't' . $churchId;
    $users = [
        'pastor' => ['Пастор Демо', 'demo-pastor-' . $suffix, 'pastor-' . $suffix . '@' . DEMO_EMAIL_DOMAIN, 'pastor', null],
        'leader' => ['Лидер Демо', 'demo-leader-' . $suffix, 'leader-' . $suffix . '@' . DEMO_EMAIL_DOMAIN, 'leader', null],
        'helper' => ['Помощник Демо', 'demo-helper-' . $suffix, 'helper-' . $suffix . '@' . DEMO_EMAIL_DOMAIN, 'assistant', null],
        'member' => ['Участник Демо', 'demo-member-' . $suffix, 'member-' . $suffix . '@' . DEMO_EMAIL_DOMAIN, 'member', null],
        'district' => ['Окружной Демо', 'demo-district-' . $suffix, 'district-' . $suffix . '@' . DEMO_EMAIL_DOMAIN, 'district_leader', null],
    ];
    $userIds = [];
    foreach ($users as $key => [$name, $login, $email, $role, $groupId]) {
        $pdo->prepare(
            'INSERT INTO users (name, login, email, password_hash, role, church_id, group_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$name, $login, $email, $passwordHash, $role, $churchId, $groupId]);
        $userIds[$key] = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT IGNORE INTO user_notification_settings (user_id, email_enabled, webpush_enabled) VALUES (?, 0, 0)')
            ->execute([$userIds[$key]]);
    }

    $pdo->prepare(
        'INSERT INTO home_groups (church_id, name, district, district_id, address, short_address, latitude, longitude, weekday, age_category, group_types, meeting_weekday, meeting_time_start, meeting_time_end, max_people, opened_at, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    )->execute([
        $churchId, 'Домашняя группа Север', 'Северный', $districtNorth,
        'Демогород, ул. Учебная, 1', 'Демогород / Север',
        56.0280000, 37.8550000, 'вторник', 'adults', '["general"]', 2, '19:00:00', '21:00:00', 16, date('Y-m-d', strtotime('-180 days')),
    ]);
    $groupNorth = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO home_groups (church_id, name, district, district_id, address, short_address, latitude, longitude, weekday, age_category, group_types, meeting_weekday, meeting_time_start, meeting_time_end, max_people, opened_at, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    )->execute([
        $churchId, 'Домашняя группа Юг', 'Южный', $districtSouth,
        'Демогород, пр. Примерный, 8', 'Демогород / Юг',
        56.0080000, 37.8500000, 'четверг', 'youth_under25', '["general"]', 4, '19:30:00', '21:30:00', 14, date('Y-m-d', strtotime('-90 days')),
    ]);
    $groupSouth = (int) $pdo->lastInsertId();

    $pdo->prepare('UPDATE users SET group_id = ? WHERE id = ?')->execute([$groupNorth, $userIds['leader']]);
    $pdo->prepare('UPDATE users SET group_id = ? WHERE id = ?')->execute([$groupNorth, $userIds['helper']]);
    $pdo->prepare('UPDATE users SET group_id = ? WHERE id = ?')->execute([$groupNorth, $userIds['member']]);

    $peopleSeed = [
        ['leader', $groupNorth, 'Андрей Лидер', '+79000000001', 'demo_leader', $stageIds[5], 1, 0, 'лидер', null],
        ['helper', $groupNorth, 'Мария Помощник', '+79000000002', 'demo_helper', $stageIds[4], 0, 1, 'помощник', null],
        ['member', $groupNorth, 'Иван Участник', '+79000000003', 'demo_member', $stageIds[3], 0, 0, 'членство', null],
        [null, $groupNorth, 'Ольга Новичок', '+79000000004', 'demo_olga', $stageIds[1], 0, 0, 'новичок', date('Y-m-d', strtotime('-14 days'))],
        [null, $groupNorth, 'Николай Давно', '+79000000005', 'demo_nick', $stageIds[2], 0, 0, 'наблюдение', date('Y-m-d', strtotime('-120 days'))],
        [null, $groupSouth, 'Павел Юг', '+79000000006', 'demo_pavel', $stageIds[5], 1, 0, 'лидер', date('Y-m-d', strtotime('-200 days'))],
        [null, $groupSouth, 'Анна Юг', '+79000000007', 'demo_anna', $stageIds[1], 0, 0, 'новичок', date('Y-m-d', strtotime('-10 days'))],
    ];
    $personIds = [];
    foreach ($peopleSeed as $i => [$userKey, $groupId, $fullName, $phone, $tg, $stageId, $isLeader, $potential, $training, $firstVisit]) {
        $userId = $userKey !== null ? $userIds[$userKey] : null;
        $pdo->prepare(
            'INSERT INTO people (user_id, group_id, full_name, phone, telegram, first_visit_date, spiritual_stage_id, is_leader, is_potential_leader, training_stage, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, $groupId, $fullName, $phone, $tg, $firstVisit, $stageId, $isLeader, $potential, $training,
            $fullName === 'Николай Давно' ? 'Давно не был на встречах — для радара заботы.' : null,
        ]);
        $personIds[$i] = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO person_group_memberships (person_id, group_id, is_primary) VALUES (?, ?, 1)')
            ->execute([$personIds[$i], $groupId]);
    }

    $pdo->prepare('UPDATE home_groups SET leader_person_id = ?, assistant_person_id = ? WHERE id = ?')
        ->execute([$personIds[0], $personIds[1], $groupNorth]);
    $pdo->prepare('UPDATE home_groups SET leader_person_id = ? WHERE id = ?')
        ->execute([$personIds[5], $groupSouth]);
    $pdo->prepare('INSERT INTO district_leaders (district_id, user_id) VALUES (?, ?)')
        ->execute([$districtNorth, $userIds['district']]);

    $meetingDates = [
        date('Y-m-d', strtotime('-42 days')),
        date('Y-m-d', strtotime('-28 days')),
        date('Y-m-d', strtotime('-14 days')),
        date('Y-m-d', strtotime('-7 days')),
    ];
    $meetingIds = [];
    foreach ($meetingDates as $idx => $date) {
        $pdo->prepare(
            'INSERT INTO meetings (group_id, meeting_date, topic, host_person_id, atmosphere, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$groupNorth, $date, 'Учебная встреча ' . ($idx + 1), $personIds[0], 'good', $userIds['leader']]);
        $meetingIds[] = (int) $pdo->lastInsertId();
    }
    // Николай absent on recent meetings → care radar
    foreach ($meetingIds as $mi => $meetingId) {
        foreach ([0, 1, 2, 3] as $pi) {
            $status = ($pi === 4) ? 'absent' : 'present';
            if ($pi === 3 && $mi === count($meetingIds) - 1) {
                $status = 'new';
            }
            $pdo->prepare('INSERT INTO attendance (meeting_id, person_id, status) VALUES (?, ?, ?)')
                ->execute([$meetingId, $personIds[$pi], $status]);
        }
        $pdo->prepare('INSERT INTO attendance (meeting_id, person_id, status) VALUES (?, ?, "absent")')
            ->execute([$meetingId, $personIds[4]]);
    }

    $pdo->prepare(
        'INSERT INTO contact_logs (person_id, user_id, contact_date, type, summary, next_contact_at)
         VALUES (?, ?, ?, "telegram", ?, ?)'
    )->execute([$personIds[3], $userIds['leader'], date('Y-m-d', strtotime('-3 days')), 'Поздравили новичка после первой встречи.', date('Y-m-d', strtotime('+4 days'))]);
    $pdo->prepare(
        'INSERT INTO contact_logs (person_id, user_id, contact_date, type, summary, next_contact_at)
         VALUES (?, ?, ?, "call", ?, ?)'
    )->execute([$personIds[4], $userIds['helper'], date('Y-m-d', strtotime('-55 days')), 'Давно не было контакта.', date('Y-m-d', strtotime('-30 days'))]);

    $pdo->prepare(
        'INSERT INTO prayer_requests (person_id, meeting_id, group_id, church_id, title, description, status, next_reminder_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?, "praying", ?, ?)'
    )->execute([
        $personIds[3], $meetingIds[count($meetingIds) - 1], $groupNorth, $churchId,
        'Адаптация на новой работе', 'Просит молиться за мир в сердце.',
        date('Y-m-d', strtotime('+7 days')), $userIds['leader'],
    ]);
    $pdo->prepare(
        'INSERT INTO prayer_requests (person_id, meeting_id, group_id, church_id, title, description, status, created_by)
         VALUES (?, NULL, ?, ?, ?, ?, "new", ?)'
    )->execute([
        $personIds[4], $groupNorth, $churchId,
        'Вернуться в общение', 'Давно не был — нужна мягкая связь.',
        $userIds['helper'],
    ]);

    $pdo->prepare(
        'INSERT INTO tasks (person_id, group_id, assigned_to, title, due_date, type, status)
         VALUES (?, ?, ?, ?, ?, "newcomer", "open")'
    )->execute([$personIds[3], $groupNorth, $userIds['leader'], 'Написать новичку Ольге после первого посещения', date('Y-m-d')]);
    $pdo->prepare(
        'INSERT INTO tasks (person_id, group_id, assigned_to, title, due_date, type, status)
         VALUES (?, ?, ?, ?, ?, "absence", "open")'
    )->execute([$personIds[4], $groupNorth, $userIds['leader'], 'Связаться с Николаем: не был больше месяца', date('Y-m-d')]);

    $pdo->prepare('INSERT INTO courses (church_id, title, required_stage_id, sort_order) VALUES (?, ?, ?, ?)')
        ->execute([$churchId, 'Первые шаги в домашней группе', $stageIds[1], 10]);
    $courseId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO course_lessons (course_id, title, body, quiz_question, quiz_answer, sort_order)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$courseId, 'Как устроена домашняя группа', 'Короткий урок о цели группы и заботе.', 'Что важно после первого визита?', 'контакт', 10]);
}

/**
 * @return array{church: array<string, mixed>, pastor_user_id: int, token: string}
 */
function demo_clone_workspace_from_template(): array
{
    $template = demo_ensure_template_seeded();
    $sourceId = (int) $template['id'];
    $token = bin2hex(random_bytes(32));
    $slug = DEMO_WORKSPACE_SLUG_PREFIX . substr($token, 0, 12);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO churches (name, city, slug, timezone, personal_data_consent_text, is_demo, demo_token, demo_seen_at)
             SELECT name, city, ?, timezone, personal_data_consent_text, 1, ?, NOW()
             FROM churches WHERE id = ?'
        )->execute([$slug, $token, $sourceId]);
        $newChurchId = (int) $pdo->lastInsertId();

        $idMaps = [
            'districts' => [],
            'stages' => [],
            'groups' => [],
            'users' => [],
            'people' => [],
            'meetings' => [],
            'prayers' => [],
            'courses' => [],
            'lessons' => [],
        ];

        foreach (fetch_all('SELECT * FROM districts WHERE church_id = ? ORDER BY id', [$sourceId]) as $row) {
            $oldId = (int) $row['id'];
            $pdo->prepare(
                'INSERT INTO districts (church_id, name, source, external_ref, polygon_geojson, bbox_min_lat, bbox_min_lon, bbox_max_lat, bbox_max_lon, color, is_active, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $newChurchId, $row['name'], $row['source'], $row['external_ref'], $row['polygon_geojson'],
                $row['bbox_min_lat'], $row['bbox_min_lon'], $row['bbox_max_lat'], $row['bbox_max_lon'],
                $row['color'], $row['is_active'], $row['sort_order'],
            ]);
            $idMaps['districts'][$oldId] = (int) $pdo->lastInsertId();
        }

        foreach (fetch_all('SELECT * FROM spiritual_stages WHERE church_id = ? ORDER BY id', [$sourceId]) as $row) {
            $oldId = (int) $row['id'];
            $pdo->prepare('INSERT INTO spiritual_stages (church_id, name, sort_order, is_final) VALUES (?, ?, ?, ?)')
                ->execute([$newChurchId, $row['name'], $row['sort_order'], $row['is_final']]);
            $idMaps['stages'][$oldId] = (int) $pdo->lastInsertId();
        }

        foreach (fetch_all('SELECT * FROM home_groups WHERE church_id = ? ORDER BY id', [$sourceId]) as $row) {
            $oldId = (int) $row['id'];
            $districtId = $row['district_id'] !== null ? ($idMaps['districts'][(int) $row['district_id']] ?? null) : null;
            $pdo->prepare(
                'INSERT INTO home_groups (church_id, name, leader_person_id, assistant_person_id, district, district_id, address, short_address, latitude, longitude, weekday, age_category, group_types, meeting_weekday, meeting_time_start, meeting_time_end, max_people, opened_at, closed_at, is_active)
                 VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $newChurchId, $row['name'], $row['district'], $districtId, $row['address'], $row['short_address'],
                $row['latitude'], $row['longitude'], $row['weekday'], $row['age_category'], $row['group_types'],
                $row['meeting_weekday'], $row['meeting_time_start'], $row['meeting_time_end'], $row['max_people'],
                $row['opened_at'], $row['closed_at'], $row['is_active'],
            ]);
            $idMaps['groups'][$oldId] = (int) $pdo->lastInsertId();
        }

        $wsSuffix = substr($token, 0, 10);
        foreach (fetch_all('SELECT * FROM users WHERE church_id = ? ORDER BY id', [$sourceId]) as $row) {
            $oldId = (int) $row['id'];
            $login = 'd' . $wsSuffix . '-' . preg_replace('/[^a-z0-9]+/', '', strtolower((string) $row['login']));
            $login = mb_substr($login, 0, 80);
            $email = 'u' . $oldId . '-' . $wsSuffix . '@' . DEMO_EMAIL_DOMAIN;
            $groupId = $row['group_id'] !== null ? ($idMaps['groups'][(int) $row['group_id']] ?? null) : null;
            $role = (string) $row['role'];
            if (in_array($role, ['admin', 'super_admin'], true)) {
                $role = 'pastor';
            }
            $pdo->prepare(
                'INSERT INTO users (name, login, email, password_hash, role, church_id, group_id, two_factor_enabled, two_factor_secret)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL)'
            )->execute([
                $row['name'], $login, $email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                $role, $newChurchId, $groupId,
            ]);
            $idMaps['users'][$oldId] = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT IGNORE INTO user_notification_settings (user_id, email_enabled, webpush_enabled) VALUES (?, 0, 0)')
                ->execute([$idMaps['users'][$oldId]]);
        }

        $sourcePeople = fetch_all(
            'SELECT p.* FROM people p
             INNER JOIN home_groups g ON g.id = p.group_id
             WHERE g.church_id = ? ORDER BY p.id',
            [$sourceId]
        );
        foreach ($sourcePeople as $row) {
            $oldId = (int) $row['id'];
            $groupId = $idMaps['groups'][(int) $row['group_id']] ?? null;
            if ($groupId === null) {
                continue;
            }
            $userId = $row['user_id'] !== null ? ($idMaps['users'][(int) $row['user_id']] ?? null) : null;
            $stageId = $row['spiritual_stage_id'] !== null ? ($idMaps['stages'][(int) $row['spiritual_stage_id']] ?? null) : null;
            $pdo->prepare(
                'INSERT INTO people (user_id, group_id, full_name, phone, email, telegram, birth_date, address, marital_status, first_visit_date, repentance_date, baptism_date, membership_date, ministry, spiritual_stage_id, is_leader, is_potential_leader, training_stage, notes, latitude, longitude, deleted_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $userId, $groupId, $row['full_name'], $row['phone'], $row['email'], $row['telegram'],
                $row['birth_date'], $row['address'], $row['marital_status'], $row['first_visit_date'],
                $row['repentance_date'], $row['baptism_date'], $row['membership_date'], $row['ministry'],
                $stageId, $row['is_leader'], $row['is_potential_leader'], $row['training_stage'], $row['notes'],
                $row['latitude'], $row['longitude'], $row['deleted_at'],
            ]);
            $idMaps['people'][$oldId] = (int) $pdo->lastInsertId();
        }

        foreach (fetch_all('SELECT * FROM home_groups WHERE church_id = ?', [$sourceId]) as $row) {
            $newGroupId = $idMaps['groups'][(int) $row['id']] ?? null;
            if ($newGroupId === null) {
                continue;
            }
            $leader = $row['leader_person_id'] !== null ? ($idMaps['people'][(int) $row['leader_person_id']] ?? null) : null;
            $assistant = $row['assistant_person_id'] !== null ? ($idMaps['people'][(int) $row['assistant_person_id']] ?? null) : null;
            $pdo->prepare('UPDATE home_groups SET leader_person_id = ?, assistant_person_id = ? WHERE id = ?')
                ->execute([$leader, $assistant, $newGroupId]);
        }

        foreach (fetch_all(
            'SELECT m.* FROM person_group_memberships m
             INNER JOIN people p ON p.id = m.person_id
             INNER JOIN home_groups g ON g.id = p.group_id
             WHERE g.church_id = ?',
            [$sourceId]
        ) as $row) {
            $personId = $idMaps['people'][(int) $row['person_id']] ?? null;
            $groupId = $idMaps['groups'][(int) $row['group_id']] ?? null;
            if ($personId === null || $groupId === null) {
                continue;
            }
            $pdo->prepare('INSERT IGNORE INTO person_group_memberships (person_id, group_id, is_primary) VALUES (?, ?, ?)')
                ->execute([$personId, $groupId, $row['is_primary']]);
        }

        foreach (fetch_all(
            'SELECT dl.* FROM district_leaders dl
             INNER JOIN districts d ON d.id = dl.district_id
             WHERE d.church_id = ?',
            [$sourceId]
        ) as $row) {
            $districtId = $idMaps['districts'][(int) $row['district_id']] ?? null;
            $userId = $idMaps['users'][(int) $row['user_id']] ?? null;
            if ($districtId === null || $userId === null) {
                continue;
            }
            $pdo->prepare('INSERT IGNORE INTO district_leaders (district_id, user_id) VALUES (?, ?)')
                ->execute([$districtId, $userId]);
        }

        foreach (fetch_all(
            'SELECT m.* FROM meetings m
             INNER JOIN home_groups g ON g.id = m.group_id
             WHERE g.church_id = ? ORDER BY m.id',
            [$sourceId]
        ) as $row) {
            $oldId = (int) $row['id'];
            $groupId = $idMaps['groups'][(int) $row['group_id']] ?? null;
            if ($groupId === null) {
                continue;
            }
            $host = $row['host_person_id'] !== null ? ($idMaps['people'][(int) $row['host_person_id']] ?? null) : null;
            $createdBy = $row['created_by'] !== null ? ($idMaps['users'][(int) $row['created_by']] ?? null) : null;
            $pdo->prepare(
                'INSERT INTO meetings (group_id, meeting_date, topic, host_person_id, atmosphere, had_conflicts, pastor_help_needed, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $groupId, $row['meeting_date'], $row['topic'], $host, $row['atmosphere'],
                $row['had_conflicts'], $row['pastor_help_needed'], $row['notes'], $createdBy,
            ]);
            $idMaps['meetings'][$oldId] = (int) $pdo->lastInsertId();
        }

        foreach (fetch_all(
            'SELECT a.* FROM attendance a
             INNER JOIN meetings m ON m.id = a.meeting_id
             INNER JOIN home_groups g ON g.id = m.group_id
             WHERE g.church_id = ?',
            [$sourceId]
        ) as $row) {
            $meetingId = $idMaps['meetings'][(int) $row['meeting_id']] ?? null;
            $personId = $idMaps['people'][(int) $row['person_id']] ?? null;
            if ($meetingId === null || $personId === null) {
                continue;
            }
            $pdo->prepare('INSERT IGNORE INTO attendance (meeting_id, person_id, status) VALUES (?, ?, ?)')
                ->execute([$meetingId, $personId, $row['status']]);
        }

        foreach (fetch_all(
            'SELECT * FROM prayer_requests WHERE church_id = ? ORDER BY id',
            [$sourceId]
        ) as $row) {
            $oldId = (int) $row['id'];
            $personId = $row['person_id'] !== null ? ($idMaps['people'][(int) $row['person_id']] ?? null) : null;
            $meetingId = $row['meeting_id'] !== null ? ($idMaps['meetings'][(int) $row['meeting_id']] ?? null) : null;
            $groupId = $row['group_id'] !== null ? ($idMaps['groups'][(int) $row['group_id']] ?? null) : null;
            $createdBy = $row['created_by'] !== null ? ($idMaps['users'][(int) $row['created_by']] ?? null) : null;
            $pdo->prepare(
                'INSERT INTO prayer_requests (person_id, meeting_id, group_id, church_id, title, description, submitter_name, is_anonymous, status, next_reminder_at, created_by, closed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $personId, $meetingId, $groupId, $newChurchId, $row['title'], $row['description'],
                $row['submitter_name'], $row['is_anonymous'], $row['status'], $row['next_reminder_at'],
                $createdBy, $row['closed_at'],
            ]);
            $idMaps['prayers'][$oldId] = (int) $pdo->lastInsertId();
        }

        foreach (fetch_all('SELECT * FROM miracles WHERE church_id = ? ORDER BY id', [$sourceId]) as $row) {
            $personId = $row['person_id'] !== null ? ($idMaps['people'][(int) $row['person_id']] ?? null) : null;
            $groupId = $row['group_id'] !== null ? ($idMaps['groups'][(int) $row['group_id']] ?? null) : null;
            $prayerId = $row['prayer_request_id'] !== null ? ($idMaps['prayers'][(int) $row['prayer_request_id']] ?? null) : null;
            $createdBy = $row['created_by'] !== null ? ($idMaps['users'][(int) $row['created_by']] ?? null) : null;
            $pdo->prepare(
                'INSERT INTO miracles (church_id, group_id, person_id, prayer_request_id, title, body, submitter_name, is_anonymous, status, created_by, published_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $newChurchId, $groupId, $personId, $prayerId, $row['title'], $row['body'],
                $row['submitter_name'], $row['is_anonymous'], $row['status'], $createdBy, $row['published_at'],
            ]);
        }

        foreach (fetch_all(
            'SELECT cl.* FROM contact_logs cl
             INNER JOIN people p ON p.id = cl.person_id
             INNER JOIN home_groups g ON g.id = p.group_id
             WHERE g.church_id = ?',
            [$sourceId]
        ) as $row) {
            $personId = $idMaps['people'][(int) $row['person_id']] ?? null;
            $userId = $row['user_id'] !== null ? ($idMaps['users'][(int) $row['user_id']] ?? null) : null;
            if ($personId === null) {
                continue;
            }
            $pdo->prepare(
                'INSERT INTO contact_logs (person_id, user_id, contact_date, type, summary, absence_reason, next_contact_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $personId, $userId, $row['contact_date'], $row['type'], $row['summary'],
                $row['absence_reason'], $row['next_contact_at'],
            ]);
        }

        foreach (fetch_all(
            'SELECT t.* FROM tasks t
             INNER JOIN home_groups g ON g.id = t.group_id
             WHERE g.church_id = ?',
            [$sourceId]
        ) as $row) {
            $personId = $row['person_id'] !== null ? ($idMaps['people'][(int) $row['person_id']] ?? null) : null;
            $groupId = $idMaps['groups'][(int) $row['group_id']] ?? null;
            $assigned = $row['assigned_to'] !== null ? ($idMaps['users'][(int) $row['assigned_to']] ?? null) : null;
            if ($groupId === null) {
                continue;
            }
            $pdo->prepare(
                'INSERT INTO tasks (person_id, group_id, assigned_to, title, due_date, type, status, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $personId, $groupId, $assigned, $row['title'], $row['due_date'], $row['type'], $row['status'], $row['completed_at'] ?? null,
            ]);
        }

        foreach (fetch_all('SELECT * FROM courses WHERE church_id = ? ORDER BY id', [$sourceId]) as $row) {
            $oldId = (int) $row['id'];
            $stageId = $row['required_stage_id'] !== null ? ($idMaps['stages'][(int) $row['required_stage_id']] ?? null) : null;
            $pdo->prepare('INSERT INTO courses (church_id, title, required_stage_id, sort_order) VALUES (?, ?, ?, ?)')
                ->execute([$newChurchId, $row['title'], $stageId, $row['sort_order']]);
            $idMaps['courses'][$oldId] = (int) $pdo->lastInsertId();
        }
        foreach (fetch_all(
            'SELECT l.* FROM course_lessons l INNER JOIN courses c ON c.id = l.course_id WHERE c.church_id = ? ORDER BY l.id',
            [$sourceId]
        ) as $row) {
            $courseId = $idMaps['courses'][(int) $row['course_id']] ?? null;
            if ($courseId === null) {
                continue;
            }
            $pdo->prepare(
                'INSERT INTO course_lessons (course_id, title, body, quiz_question, quiz_answer, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$courseId, $row['title'], $row['body'], $row['quiz_question'], $row['quiz_answer'], $row['sort_order']]);
            $idMaps['lessons'][(int) $row['id']] = (int) $pdo->lastInsertId();
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $pastor = fetch_one(
        'SELECT id FROM users WHERE church_id = ? AND role = "pastor" ORDER BY id LIMIT 1',
        [$newChurchId]
    );
    if (!$pastor) {
        throw new RuntimeException('В демо-копии не найден пастор');
    }

    $church = find_church_by_id($newChurchId, true);
    if (!$church) {
        throw new RuntimeException('Демо-копия не найдена после клонирования');
    }

    return [
        'church' => $church,
        'pastor_user_id' => (int) $pastor['id'],
        'token' => $token,
    ];
}

function demo_purge_expired_workspaces(): void
{
    if (!demo_schema_ready()) {
        return;
    }
    $rows = fetch_all(
        'SELECT id FROM churches
         WHERE is_demo = 1 AND demo_token IS NOT NULL AND demo_token <> ""
           AND demo_seen_at IS NOT NULL
           AND demo_seen_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
        [DEMO_WORKSPACE_TTL_DAYS]
    );
    foreach ($rows as $row) {
        demo_hard_delete_workspace((int) $row['id']);
    }
}

function demo_hard_delete_workspace(int $churchId): void
{
    if ($churchId <= 0 || !demo_schema_ready()) {
        return;
    }
    $church = find_church_by_id($churchId, true);
    if (!$church || !is_demo_workspace_church($church)) {
        return;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $groupIds = array_map(
            static fn(array $r): int => (int) $r['id'],
            fetch_all('SELECT id FROM home_groups WHERE church_id = ?', [$churchId])
        );
        $personIds = [];
        if ($groupIds !== []) {
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $personIds = array_map(
                static fn(array $r): int => (int) $r['id'],
                fetch_all("SELECT id FROM people WHERE group_id IN ($placeholders)", $groupIds)
            );
            $meetingIds = array_map(
                static fn(array $r): int => (int) $r['id'],
                fetch_all("SELECT id FROM meetings WHERE group_id IN ($placeholders)", $groupIds)
            );
            if ($meetingIds !== []) {
                $mPh = implode(',', array_fill(0, count($meetingIds), '?'));
                $pdo->prepare("DELETE FROM attendance WHERE meeting_id IN ($mPh)")->execute($meetingIds);
                $pdo->prepare("DELETE FROM service_schedule WHERE meeting_id IN ($mPh)")->execute($meetingIds);
                $pdo->prepare("DELETE FROM meeting_reports WHERE meeting_id IN ($mPh)")->execute($meetingIds);
                $pdo->prepare("DELETE FROM meetings WHERE id IN ($mPh)")->execute($meetingIds);
            }
            $pdo->prepare("DELETE FROM tasks WHERE group_id IN ($placeholders)")->execute($groupIds);
            $pdo->prepare("DELETE FROM prayer_requests WHERE group_id IN ($placeholders) OR church_id = ?")
                ->execute([...$groupIds, $churchId]);
        } else {
            $pdo->prepare('DELETE FROM prayer_requests WHERE church_id = ?')->execute([$churchId]);
        }

        if ($personIds !== []) {
            $pPh = implode(',', array_fill(0, count($personIds), '?'));
            $pdo->prepare("DELETE FROM contact_logs WHERE person_id IN ($pPh)")->execute($personIds);
            $pdo->prepare("DELETE FROM person_group_memberships WHERE person_id IN ($pPh)")->execute($personIds);
            $pdo->prepare("DELETE FROM course_progress WHERE person_id IN ($pPh)")->execute($personIds);
            $pdo->prepare("DELETE FROM person_change_logs WHERE person_id IN ($pPh)")->execute($personIds);
            $pdo->prepare("DELETE FROM data_deletion_requests WHERE person_id IN ($pPh)")->execute($personIds);
            $pdo->prepare("DELETE FROM people WHERE id IN ($pPh)")->execute($personIds);
        }

        $pdo->prepare('DELETE FROM miracles WHERE church_id = ?')->execute([$churchId]);
        $courseIds = array_map(
            static fn(array $r): int => (int) $r['id'],
            fetch_all('SELECT id FROM courses WHERE church_id = ?', [$churchId])
        );
        if ($courseIds !== []) {
            $cPh = implode(',', array_fill(0, count($courseIds), '?'));
            $pdo->prepare("DELETE FROM course_lessons WHERE course_id IN ($cPh)")->execute($courseIds);
            $pdo->prepare("DELETE FROM courses WHERE id IN ($cPh)")->execute($courseIds);
        }
        $pdo->prepare('DELETE FROM spiritual_stages WHERE church_id = ?')->execute([$churchId]);

        $districtIds = array_map(
            static fn(array $r): int => (int) $r['id'],
            fetch_all('SELECT id FROM districts WHERE church_id = ?', [$churchId])
        );
        if ($districtIds !== []) {
            $dPh = implode(',', array_fill(0, count($districtIds), '?'));
            $pdo->prepare("DELETE FROM district_leaders WHERE district_id IN ($dPh)")->execute($districtIds);
            $pdo->prepare("DELETE FROM districts WHERE id IN ($dPh)")->execute($districtIds);
        }

        if ($groupIds !== []) {
            $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
            $pdo->prepare("DELETE FROM home_groups WHERE id IN ($placeholders)")->execute($groupIds);
        }

        $userIds = array_map(
            static fn(array $r): int => (int) $r['id'],
            fetch_all('SELECT id FROM users WHERE church_id = ?', [$churchId])
        );
        if ($userIds !== []) {
            $uPh = implode(',', array_fill(0, count($userIds), '?'));
            $pdo->prepare("DELETE FROM user_notification_settings WHERE user_id IN ($uPh)")->execute($userIds);
            $pdo->prepare("DELETE FROM web_push_subscriptions WHERE user_id IN ($uPh)")->execute($userIds);
            $pdo->prepare("DELETE FROM notification_events WHERE user_id IN ($uPh)")->execute($userIds);
            $pdo->prepare("DELETE FROM password_resets WHERE user_id IN ($uPh)")->execute($userIds);
            $pdo->prepare("DELETE FROM email_notifications WHERE user_id IN ($uPh)")->execute($userIds);
            if (db_table_exists('user_api_keys')) {
                $pdo->prepare("DELETE FROM user_api_keys WHERE user_id IN ($uPh)")->execute($userIds);
            }
            if (db_table_exists('social_identities')) {
                $pdo->prepare("DELETE FROM social_identities WHERE user_id IN ($uPh)")->execute($userIds);
            }
            if (db_table_exists('bot_accounts')) {
                $pdo->prepare("DELETE FROM bot_accounts WHERE user_id IN ($uPh)")->execute($userIds);
            }
            $pdo->prepare("DELETE FROM users WHERE id IN ($uPh)")->execute($userIds);
        }

        $pdo->prepare('DELETE FROM churches WHERE id = ? AND is_demo = 1')->execute([$churchId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * @return array{church: array<string, mixed>, pastor_user_id: int, token: string}
 */
function demo_obtain_workspace(bool $forceNew): array
{
    demo_purge_expired_workspaces();
    demo_ensure_template_seeded();

    if (!$forceNew) {
        $existing = demo_workspace_from_cookie();
        if ($existing) {
            $pastor = fetch_one(
                'SELECT id FROM users WHERE church_id = ? AND role = "pastor" ORDER BY id LIMIT 1',
                [(int) $existing['id']]
            );
            if ($pastor) {
                demo_touch_workspace((int) $existing['id']);

                return [
                    'church' => $existing,
                    'pastor_user_id' => (int) $pastor['id'],
                    'token' => (string) $existing['demo_token'],
                ];
            }
        }
    } else {
        $old = demo_workspace_from_cookie();
        if ($old) {
            demo_hard_delete_workspace((int) $old['id']);
        }
        demo_clear_workspace_cookie();
    }

    $remoteKey = 'demo-enter:' . ($_SERVER['REMOTE_ADDR'] ?? 'local');
    if (!throttle_action($remoteKey, DEMO_ENTER_IP_LIMIT, DEMO_ENTER_IP_WINDOW)) {
        throw new RuntimeException('Слишком много попыток открыть демо. Попробуйте позже.');
    }

    $cloned = demo_clone_workspace_from_template();
    demo_set_workspace_cookie($cloned['token']);

    return $cloned;
}

function demo_login_as_pastor(int $pastorUserId): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    session_regenerate_id(true);
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_started_at'], $_SESSION['role_preview']);
    $_SESSION['user_id'] = $pastorUserId;
}

function demo_enter_and_redirect(): never
{
    try {
        $ws = demo_obtain_workspace(false);
        demo_login_as_pastor($ws['pastor_user_id']);
        set_current_church($ws['church']);
        header('Location: /demo/', true, 302);
        exit;
    } catch (Throwable $e) {
        http_response_code(503);
        public_page_header('Демо временно недоступно');
        echo '<section class="login-card stack"><h1>Демо временно недоступно</h1>';
        echo '<p class="muted">' . e($e->getMessage()) . '</p>';
        echo '<p><a class="primary" href="/preza">Вернуться к презентации</a></p></section>';
        public_page_footer();
        exit;
    }
}

function demo_reset_and_redirect(): never
{
    verify_csrf();
    try {
        $ws = demo_obtain_workspace(true);
        demo_login_as_pastor($ws['pastor_user_id']);
        set_current_church($ws['church']);
        header('Location: /demo/', true, 302);
        exit;
    } catch (Throwable $e) {
        http_response_code(503);
        echo e($e->getMessage());
        exit;
    }
}

function demo_logout_redirect_target(): string
{
    return '/preza';
}

function demo_password_login_allowed(array $user): bool
{
    return !is_demo_user($user);
}

function demo_queue_email_allowed(?int $userId, ?int $churchId = null): bool
{
    if ($churchId !== null && $churchId > 0 && church_is_demo_by_id($churchId)) {
        return false;
    }
    if ($userId !== null && $userId > 0) {
        $user = fetch_one('SELECT church_id FROM users WHERE id = ? LIMIT 1', [$userId]);
        if ($user && church_is_demo_by_id((int) ($user['church_id'] ?? 0))) {
            return false;
        }
    }

    return true;
}

function demo_geocoder_allowed(): bool
{
    return !is_demo_session();
}

function demo_knowledge_editable(): bool
{
    return !is_demo_session();
}

function demo_can_show_pwa_install(): bool
{
    return !is_demo_session();
}

function demo_slug_is_workspace_internal(string $slug): bool
{
    return str_starts_with(strtolower($slug), DEMO_WORKSPACE_SLUG_PREFIX);
}

function demo_slug_is_reserved_public(string $slug): bool
{
    return strtolower($slug) === DEMO_PUBLIC_SLUG;
}

/**
 * Bind /demo/... to the cookie workspace. Returns null when the path is not under /demo.
 *
 * @return array{0: string, 1: bool}|null [logicalPath, tenantBound]
 */
function resolve_demo_tenant_from_request_path(string $requestPath): ?array
{
    $requestPath = '/' . trim($requestPath, '/');
    if ($requestPath === '/demo') {
        $logical = '/';
    } elseif (str_starts_with($requestPath, '/demo/')) {
        $rest = substr($requestPath, strlen('/demo'));
        $logical = $rest === '' ? '/' : $rest;
        if ($logical[0] !== '/') {
            $logical = '/' . $logical;
        }
    } else {
        return null;
    }

    if ($logical === '/enter') {
        return ['/demo/enter', false];
    }
    if ($logical === '/reset') {
        return ['/demo/reset', false];
    }
    if ($logical === '/login') {
        return ['/demo/enter', false];
    }

    if (!demo_schema_ready()) {
        return ['/demo/enter', false];
    }

    $workspace = demo_workspace_from_cookie();
    if (!$workspace) {
        return ['/demo/enter', false];
    }

    set_current_church($workspace);
    demo_touch_workspace((int) $workspace['id']);

    return [$logical, true];
}
