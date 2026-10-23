<?php

declare(strict_types=1);

function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    if (strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https') {
        return true;
    }

    $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwarded !== '') {
        $proto = trim(explode(',', $forwarded)[0]);
        if ($proto === 'https') {
            return true;
        }
    }

    return false;
}

function request_should_force_https(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = explode(':', $host)[0];

    if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
        return false;
    }
    if (str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.test')) {
        return false;
    }

    return filter_var($host, FILTER_VALIDATE_IP) === false;
}

$isCli = PHP_SAPI === 'cli';

if (!$isCli && !request_is_https() && request_should_force_https()) {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: https://' . $host . $uri, true, 301);
    exit;
}

if (!$isCli) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(self)');
}

require_once __DIR__ . '/load_modules.php';
load_application_modules();

$config = require __DIR__ . '/../config/app.php';
date_default_timezone_set($config['timezone']);

function app_config(?string $key = null): mixed
{
    global $config;
    if ($key === null) {
        return $config;
    }
    $value = $config;
    foreach (explode('.', $key) as $part) {
        $value = $value[$part] ?? null;
    }
    return $value;
}

function app_name(): string
{
    return (string) app_config('name');
}

function app_short_name(): string
{
    $shortName = app_config('short_name');
    return is_string($shortName) && $shortName !== '' ? $shortName : app_name();
}

function app_description(): string
{
    $description = app_config('description');
    return is_string($description) && $description !== '' ? $description : app_name();
}

function html_pwa_meta(): void
{
    $manifestHref = function_exists('pwa_manifest_href')
        ? pwa_manifest_href(function_exists('current_church_slug') ? current_church_slug() : null)
        : '/manifest.webmanifest';

    echo '<meta name="theme-color" content="#1d1d1b">';
    echo '<meta name="apple-mobile-web-app-capable" content="yes">';
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">';
    echo '<meta name="apple-mobile-web-app-title" content="' . e(app_short_name()) . '">';
    echo '<link rel="manifest" href="' . e($manifestHref) . '">';
    echo '<link rel="icon" href="/assets/icon.svg" type="image/svg+xml">';
    echo '<link rel="apple-touch-icon" href="/assets/icon-192.png">';
}

function html_font_assets(): void
{
    // Self-hosted Montserrat (public/assets/fonts) — no Google Fonts CDN.
    $weights = [400, 500, 600, 700, 800];
    foreach ($weights as $weight) {
        $href = e(asset_url('/assets/fonts/montserrat-' . $weight . '.woff2'));
        echo '<link rel="preload" href="' . $href . '" as="font" type="font/woff2" crossorigin>';
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    require_once __DIR__ . '/../config/db_connect.php';

    $db = app_config('db');
    if (!is_array($db)) {
        throw new RuntimeException('Database configuration is missing. Set db.* in config/local.php.');
    }

    try {
        $pdo = pdo_connect_with_retry($db);
    } catch (Throwable $e) {
        $localConfig = __DIR__ . '/../config/local.php';
        $message = 'Database connection failed.';
        if (!is_file($localConfig)) {
            $message .= ' Create config/local.php on the server from config/local.example.php with your hosting DB credentials.';
        } else {
            $message .= ' Check db.host, db.database, db.username and db.password in config/local.php.';
        }
        throw new RuntimeException($message . ' [' . $e->getMessage() . ']', 0, $e);
    }

    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function geocoder_ready(): bool
{
    return (string) (app_config('yandex.geocoder_key') ?: '') !== '';
}

function address_field_markup(string $name, ?string $value = null, array $attrs = []): string
{
    $attributes = array_merge([
        'name' => $name,
        'type' => 'text',
        'value' => $value ?? '',
        'maxlength' => '255',
        'autocomplete' => 'street-address',
    ], $attrs);
    $input = '<input' . html_attrs($attributes) . '>';
    if (!geocoder_ready()) {
        return $input;
    }

    return '<div class="address-field">'
        . $input
        . '<div class="address-geolocation" data-address-geolocation>'
        . '<button type="button" class="ghost address-geolocation__button" data-address-geolocation-button>Получить адрес по геопозиции</button>'
        . '<span class="address-geolocation__status" role="status" aria-live="polite"></span>'
        . '</div></div>';
}

function phone_input(string $name, ?string $value = null, array $attrs = []): string
{
    $display = ChurchCare\format_phone($value);
    if ($display === '') {
        $display = '+7 ';
    }

    $attributes = array_merge([
        'name' => $name,
        'type' => 'text',
        'inputmode' => 'tel',
        'autocomplete' => 'tel',
        'placeholder' => '+7 977 000-00-00',
        'value' => $display,
        'data-mask' => 'phone',
        'data-prefix' => '+7 ',
        'spellcheck' => 'false',
    ], $attrs);

    return '<input' . html_attrs($attributes) . '>';
}

function telegram_input(string $name, ?string $value = null, array $attrs = []): string
{
    $display = ChurchCare\format_telegram($value);
    if ($display === '') {
        $display = '@';
    }

    $attributes = array_merge([
        'name' => $name,
        'type' => 'text',
        'inputmode' => 'text',
        'autocomplete' => 'username',
        'placeholder' => '@username',
        'value' => $display,
        'data-mask' => 'telegram',
        'data-prefix' => '@',
        'spellcheck' => 'false',
        'autocapitalize' => 'off',
        'autocorrect' => 'off',
    ], $attrs);

    return '<input' . html_attrs($attributes) . '>';
}

function html_attrs(array $attrs): string
{
    $parts = [];
    foreach ($attrs as $key => $value) {
        if ($value === null || $value === false) {
            continue;
        }
        if ($value === true) {
            $parts[] = ' ' . $key;
            continue;
        }
        $parts[] = ' ' . $key . '="' . e((string) $value) . '"';
    }

    return implode('', $parts);
}

function phone_href(?string $value): string
{
    $normalized = ChurchCare\normalize_phone($value);
    return $normalized ?? trim((string) $value);
}

function telegram_href(?string $value): string
{
    $username = ChurchCare\telegram_username($value);
    return $username === '' ? '' : 'https://t.me/' . $username;
}

/** Monochrome Telegram paper-plane icon (inherits currentColor). */
function telegram_icon_svg(int $size = 18): string
{
    $s = max(12, $size);

    return '<svg class="icon-telegram" xmlns="http://www.w3.org/2000/svg" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">'
        . '<path d="M21.94 3.87c.34-1.46-.53-2.03-1.53-1.67L1.7 9.14c-1.41.55-1.39 1.33-.25 1.68l5.23 1.63 12.14-7.65c.57-.38 1.1-.17.67.21L9.78 14.59l-.4 4.3c.57 0 .82-.25 1.12-.55l2.7-2.58 5.6 4.12c1.03.57 1.76.27 2.03-.95z"/>'
        . '</svg>';
}

function email_href(?string $value): string
{
    $email = ChurchCare\normalize_email($value) ?? trim((string) $value);
    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? 'mailto:' . $email : '';
}

/**
 * Presentation helpers for inline person-card fields.
 *
 * @return array{display:string,edit_value:string,href:string,input:string}
 */
function person_field_presentation(string $field, ?string $value): array
{
    $raw = trim((string) $value);
    if ($field === 'phone') {
        $phoneHref = phone_href($value);
        return [
            'display' => ChurchCare\format_phone($value) ?: '—',
            'edit_value' => ChurchCare\format_phone($value) ?: '+7 ',
            'href' => $phoneHref !== '' ? 'tel:' . $phoneHref : '',
            'input' => 'tel',
        ];
    }
    if ($field === 'telegram') {
        return [
            'display' => ChurchCare\format_telegram($value) ?: '—',
            'edit_value' => ChurchCare\format_telegram($value) ?: '@',
            'href' => telegram_href($value),
            'input' => 'text',
        ];
    }

    return match ($field) {
        'email' => [
            'display' => $raw !== '' ? $raw : '—',
            'edit_value' => $raw,
            'href' => email_href($value),
            'input' => 'email',
        ],
        'address' => [
            'display' => $raw !== '' ? $raw : '—',
            'edit_value' => $raw,
            'href' => '',
            'input' => 'text',
        ],
        'birth_date', 'first_visit_date', 'repentance_date', 'baptism_date', 'membership_date' => [
            'display' => $raw !== '' ? $raw : '—',
            'edit_value' => $raw,
            'href' => '',
            'input' => 'date',
        ],
        'marital_status' => [
            'display' => $raw !== '' ? $raw : '—',
            'edit_value' => $raw,
            'href' => '',
            'input' => 'select',
            'options' => marital_status_choices($raw),
        ],
        'ministry' => [
            'display' => $raw !== '' ? $raw : '—',
            'edit_value' => $raw,
            'href' => '',
            'input' => 'text',
        ],
        default => [
            'display' => $raw !== '' ? $raw : '—',
            'edit_value' => $raw,
            'href' => '',
            'input' => 'text',
        ],
    };
}

function render_person_detail_field(string $label, string $field, ?string $value, bool $canEdit, int $personId): void
{
    $meta = person_field_presentation($field, $value);
    echo '<dt>' . e($label) . '</dt>';
    echo '<dd class="detail-field" data-field="' . e($field) . '" data-person-id="' . $personId . '" data-input="' . e($meta['input']) . '" data-value="' . e($meta['edit_value']) . '"';
    if (($meta['input'] ?? '') === 'select' && !empty($meta['options']) && is_array($meta['options'])) {
        echo ' data-options="' . e(json_encode(array_values($meta['options']), JSON_UNESCAPED_UNICODE)) . '"';
    }
    echo '>';
    echo '<div class="detail-read"><span class="detail-value">';
    if ($meta['href'] !== '' && $meta['display'] !== '—') {
        $extra = str_starts_with($meta['href'], 'http') ? ' target="_blank" rel="noopener noreferrer"' : '';
        echo '<a href="' . e($meta['href']) . '"' . $extra . '>' . e($meta['display']) . '</a>';
    } else {
        echo e($meta['display']);
    }
    echo '</span>';
    if ($canEdit) {
        echo '<button type="button" class="detail-edit-btn" title="Редактировать" aria-label="Редактировать: ' . e($label) . '">✎</button>';
    }
    echo '</div><p class="detail-error" hidden></p></dd>';
}

function render_person_static_field(string $label, ?string $value): void
{
    echo '<dt>' . e($label) . '</dt><dd>' . e($value !== null && trim($value) !== '' ? trim($value) : '—') . '</dd>';
}

function render_person_account_summary_fields(array $person, array $viewer): void
{
    $userId = (int) ($person['user_id'] ?? 0);
    if ($userId === 0) {
        return;
    }

    $isSelf = (int) ($viewer['id'] ?? 0) === $userId;
    if ($isSelf || can_manage_users($viewer)) {
        $label = $isSelf ? 'Мой профиль' : 'Открыть учётную запись';
        echo '<div class="quick-actions"><a class="ghost" href="' . e(user_card_href($userId, $viewer)) . '">' . e($label) . '</a></div>';
    }
    echo '<dl class="details">';
    render_person_static_field('Имя в CRM', $person['user_name'] ?? null);
    render_person_static_field('Логин', $person['user_login'] ?? null);
    render_person_static_field('Почта', $person['user_email'] ?? null);
    render_person_static_field('Роль', isset($person['user_role']) ? role_label((string) $person['user_role']) : null);
    render_person_static_field('2FA', !empty($person['user_two_factor_enabled']) ? 'включена' : 'выключена');
    echo '</dl>';
}

function render_person_account_summary(array $person, array $viewer): void
{
    $userId = (int) ($person['user_id'] ?? 0);
    if ($userId === 0) {
        return;
    }

    echo '<article class="panel account-summary"><div class="panel-head"><h2>Учётная запись</h2></div>';
    render_person_account_summary_fields($person, $viewer);
    echo '<p class="muted">Карточка хранит заботу и историю; учётка даёт вход в CRM.</p></article>';
}

function redirect(string $path): never
{
    header('Location: ' . (function_exists('app_path') ? app_path($path) : $path));
    exit;
}

function wants_ajax(): bool
{
    $requestedWith = strtolower((string) ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? ""));
    return in_array($requestedWith, ["fetch", "xmlhttprequest"], true);
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function is_absolute_http_url(string $url): bool
{
    return (bool) preg_match('#^https?://#i', $url);
}

function ajax_redirect(string $path, ?string $message = null, array $payload = []): never
{
    if ($message !== null && $message !== '') {
        flash($message);
    }

    // Absolute http(s) targets (bot deep links, OAuth) must stay intact.
    $target = is_absolute_http_url($path)
        ? $path
        : (function_exists('app_path') ? app_path($path) : $path);

    if (wants_ajax()) {
        json_response(["ok" => true, "redirect" => $target, "message" => $message] + $payload);
    }

    header('Location: ' . $target);
    exit;
}

function flash(?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION["flash"] = $message;
        return null;
    }
    $message = $_SESSION["flash"] ?? null;
    unset($_SESSION["flash"]);
    return $message;
}

function csrf_token(): string
{
    if (empty($_SESSION["csrf"])) {
        $_SESSION["csrf"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf"];
}

function refresh_csrf_token(): string
{
    $_SESSION["csrf"] = bin2hex(random_bytes(32));
    return $_SESSION["csrf"];
}

function csrf_is_valid(): bool
{
    $token = $_POST["_csrf"] ?? $_SERVER["HTTP_X_CSRF_TOKEN"] ?? "";
    return is_string($token) && $token !== "" && hash_equals($_SESSION["csrf"] ?? "", $token);
}

function login_csrf_token(): string
{
    $issuedAt = time();
    $nonce = bin2hex(random_bytes(16));
    $payload = $issuedAt . ":" . $nonce;
    $mac = hash_hmac("sha256", $payload, (string) app_config("security.encryption_key"));
    return $payload . ":" . $mac;
}

function login_csrf_field(): string
{
    return "<input type=\"hidden\" name=\"_csrf\" value=\"" . e(login_csrf_token()) . "\">";
}

function login_csrf_is_valid(): bool
{
    if (csrf_is_valid()) {
        return true;
    }

    $token = (string) ($_POST["_csrf"] ?? $_SERVER["HTTP_X_CSRF_TOKEN"] ?? "");
    $parts = explode(":", $token);
    if (count($parts) !== 3) {
        return false;
    }

    [$issuedAt, $nonce, $mac] = $parts;
    if (!ctype_digit($issuedAt) || !ctype_xdigit($nonce) || strlen($nonce) !== 32 || !ctype_xdigit($mac)) {
        return false;
    }

    $issued = (int) $issuedAt;
    if ($issued < time() - 3600 || $issued > time() + 60) {
        return false;
    }

    $payload = $issuedAt . ":" . $nonce;
    $expected = hash_hmac("sha256", $payload, (string) app_config("security.encryption_key"));
    return hash_equals($expected, $mac);
}

function verify_csrf(): void
{
    if (!csrf_is_valid()) {
        if (wants_ajax()) {
            json_response(["ok" => false, "error" => "CSRF token mismatch"], 419);
        }

        http_response_code(419);
        exit("CSRF token mismatch");
    }
}


function throttle_action(string $key, int $limit, int $windowSeconds): bool
{
    $now = time();
    $_SESSION['throttle'] ??= [];
    $bucket = $_SESSION['throttle'][$key] ?? [];
    $bucket = array_values(array_filter($bucket, static fn($timestamp) => is_int($timestamp) && $timestamp > $now - $windowSeconds));
    if (count($bucket) >= $limit) {
        $_SESSION['throttle'][$key] = $bucket;
        return false;
    }
    $bucket[] = $now;
    $_SESSION['throttle'][$key] = $bucket;
    return true;
}

function csrf_field(): string
{
    return "<input type=\"hidden\" name=\"_csrf\" value=\"" . e(csrf_token()) . "\">";
}

function fetch_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetch_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Run a mutating SQL statement and return the number of affected rows.
 *
 * Used by districts / catalog cache writers alongside fetch_one / fetch_all.
 *
 * @param list<mixed> $params
 */
function execute(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount();
}

/**
 * @var array<string, bool>
 */
function &db_table_exists_cache(): array
{
    static $cache = [];

    return $cache;
}

function db_table_exists(string $table): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    $cache = &db_table_exists_cache();
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    $exists = (int) $stmt->fetchColumn() > 0;
    // Only cache positives: a missing table may appear mid-request after migrate self-heal.
    if ($exists) {
        $cache[$table] = true;
    }

    return $exists;
}

function db_table_exists_clear_cache(): void
{
    $cache = &db_table_exists_cache();
    $cache = [];
}

function fetch_user_by_id(int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT u.*, c.name AS church_name, c.city AS church_city, c.slug AS church_slug
         FROM users u
         LEFT JOIN churches c ON c.id = u.church_id
         WHERE u.id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function real_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $user = null;
    static $cachedUserId = null;
    $sessionUserId = (int) $_SESSION['user_id'];
    if ($user && $cachedUserId === $sessionUserId) {
        return $user;
    }

    $user = fetch_user_by_id($sessionUserId);
    $cachedUserId = $user ? $sessionUserId : null;

    return $user;
}

function current_user(): ?array
{
    $real = real_user();
    if (!$real) {
        return null;
    }

    static $effective = null;
    static $cacheKey = null;
    $previewRole = active_role_preview($real);
    $key = $real['id'] . ':' . ($previewRole ?? '');
    if ($effective && $cacheKey === $key) {
        return $effective;
    }

    $effective = user_with_preview_role($real, $previewRole);
    $cacheKey = $key;

    return $effective;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('/login');
    }
    return $user;
}

function role_label(string $role): string
{
    return [
        'member' => 'Участник',
        'assistant' => 'Помощник лидера',
        'leader' => 'Лидер домашней группы',
        'district_leader' => 'Окружной лидер',
        'pastor' => 'Пастор',
        'admin' => 'Администратор',
        'super_admin' => 'Суперадминистратор',
    ][$role] ?? $role;
}

function can_delete(array $user): bool
{
    return in_array($user['role'], ['leader', 'district_leader', 'pastor', 'admin', 'super_admin'], true);
}

function can_edit_group_data(array $user): bool
{
    return in_array($user['role'], ['assistant', 'leader', 'district_leader', 'pastor', 'admin', 'super_admin'], true);
}

function accessible_group_sql(array $user, string $alias = 'g'): array
{
    return ChurchCare\accessible_group_filter($user, $alias);
}

function accessible_person_sql(array $user, string $alias = 'p'): array
{
    return ChurchCare\accessible_person_filter($user, $alias);
}

function scoped_group(array $user, int $groupId): ?array
{
    [$sql, $params] = accessible_group_sql($user, 'g');
    $params[] = $groupId;
    return fetch_one("SELECT g.* FROM home_groups g WHERE $sql AND g.is_active = 1 AND g.id = ? LIMIT 1", $params);
}

function scoped_person(array $user, int $personId): ?array
{
    [$sql, $params] = accessible_person_sql($user, 'p');
    $params[] = $personId;
    return fetch_one("SELECT p.* FROM people p JOIN home_groups g ON g.id = p.group_id WHERE $sql AND p.id = ? LIMIT 1", $params);
}

function scoped_meeting(array $user, int $meetingId): ?array
{
    [$sql, $params] = accessible_group_sql($user, 'g');
    $params[] = $meetingId;
    return fetch_one("SELECT m.*, g.church_id, g.name AS group_name FROM meetings m JOIN home_groups g ON g.id = m.group_id WHERE $sql AND m.id = ? LIMIT 1", $params);
}

function scoped_prayer(array $user, int $prayerId): ?array
{
    if ($prayerId <= 0) {
        return null;
    }

    [$sql, $params] = accessible_group_sql($user, 'g');
    $params[] = $prayerId;
    $row = fetch_one(
        "SELECT pr.*
         FROM prayer_requests pr
         LEFT JOIN people p ON p.id = pr.person_id
         JOIN home_groups g ON g.id = COALESCE(p.group_id, pr.group_id)
         WHERE $sql AND pr.id = ? LIMIT 1",
        $params
    );
    if ($row) {
        return $row;
    }

    $churchId = (int) (effective_scope_church_id($user) ?? ($user['church_id'] ?? 0));
    if ($churchId <= 0) {
        return null;
    }
    [$churchSql, $churchParams] = church_id_scope_sql('pr.church_id', $churchId);
    return fetch_one(
        "SELECT pr.*
         FROM prayer_requests pr
         WHERE pr.id = ?
           AND pr.person_id IS NULL
           AND pr.group_id IS NULL
           AND $churchSql
         LIMIT 1",
        array_merge([$prayerId], $churchParams)
    );
}

function scoped_deletion_request(array $user, int $requestId): ?array
{
    [$sql, $params] = accessible_person_sql($user, 'p');
    $params[] = $requestId;
    return fetch_one(
        "SELECT d.*
         FROM data_deletion_requests d
         JOIN people p ON p.id = d.person_id
         JOIN home_groups g ON g.id = p.group_id
         WHERE $sql AND d.id = ? LIMIT 1",
        $params
    );
}

function audit(string $action, ?string $entityType = null, ?int $entityId = null, array $details = []): void
{
    if (function_exists('is_demo_session') && is_demo_session()) {
        return;
    }
    $user = real_user() ?? current_user();
    if ($user && function_exists('is_demo_user') && is_demo_user($user)) {
        return;
    }
    if (is_role_preview_active()) {
        $details['role_preview'] = active_role_preview($user);
    }
    $stmt = db()->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $user['id'] ?? null,
        $action,
        $entityType,
        $entityId,
        $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

function care_status(?string $lastAttendance, ?string $lastContact): array
{
    return ChurchCare\care_status($lastAttendance, $lastContact);
}

function ensure_automations(array $user): void
{
    if (is_role_preview_active()) {
        return;
    }
    if (function_exists('is_demo_user') && is_demo_user($user)) {
        return;
    }

    if ($user['role'] === 'member') {
        return;
    }

    [$personSql, $params] = accessible_person_sql($user);
    $people = fetch_all(
        "SELECT p.id, p.group_id, p.full_name, p.first_visit_date, g.leader_person_id,
                MAX(CASE WHEN a.status IN ('present','new') THEN m.meeting_date END) AS last_attendance,
                MAX(cl.contact_date) AS last_contact,
                leader.user_id AS leader_user_id
         FROM people p
         JOIN home_groups g ON g.id = p.group_id
         LEFT JOIN meetings m ON m.group_id = p.group_id
         LEFT JOIN attendance a ON a.meeting_id = m.id AND a.person_id = p.id
         LEFT JOIN contact_logs cl ON cl.person_id = p.id
         LEFT JOIN people leader ON leader.id = g.leader_person_id
         WHERE $personSql
         GROUP BY p.id, p.group_id, p.full_name, p.first_visit_date, g.leader_person_id, leader.user_id",
        $params
    );

    $findActiveTask = db()->prepare('SELECT id FROM tasks WHERE person_id <=> ? AND assigned_to <=> ? AND type = ? AND due_date = ? AND status IN ("open", "done") LIMIT 1');
    $insertTask = db()->prepare('INSERT IGNORE INTO tasks (person_id, group_id, assigned_to, title, due_date, type, status) VALUES (?, ?, ?, ?, ?, ?, "open")');
    $insertNotice = db()->prepare('INSERT INTO notification_events (user_id, title, body, url, channel, status) VALUES (?, ?, ?, ?, "webpush", "pending")');
    $today = new DateTimeImmutable('today');
    $createTask = static function (int $personId, int $groupId, int $assignedTo, string $title, string $dueDate, string $type) use ($findActiveTask, $insertTask): void {
        $findActiveTask->execute([$personId, $assignedTo, $type, $dueDate]);
        if ($findActiveTask->fetch()) {
            return;
        }

        $insertTask->execute([$personId, $groupId, $assignedTo, $title, $dueDate, $type]);
    };

    foreach ($people as $person) {
        $assignedTo = (int) ($person['leader_user_id'] ?: $user['id']);
        $personId = (int) $person['id'];
        $groupId = (int) $person['group_id'];
        $fullName = (string) $person['full_name'];

        if ($person['first_visit_date']) {
            foreach (ChurchCare\newcomer_followup_tasks($personId, $groupId, $assignedTo, $fullName, (string) $person['first_visit_date'], $today->format('Y-m-d')) as $task) {
                $createTask(
                    (int) $task['person_id'],
                    (int) $task['group_id'],
                    (int) $task['assigned_to'],
                    (string) $task['title'],
                    (string) $task['due_date'],
                    (string) $task['type']
                );
            }
        }

        $firstVisit = isset($person['first_visit_date']) ? (string) $person['first_visit_date'] : null;
        $lastAttendance = ChurchCare\effective_last_attendance(
            isset($person['last_attendance']) ? (string) $person['last_attendance'] : null,
            $firstVisit
        );
        $lastContact = ChurchCare\effective_last_contact(
            isset($person['last_contact']) ? (string) $person['last_contact'] : null,
            $firstVisit
        );
        $gapTask = ChurchCare\care_gap_task(
            $lastAttendance,
            $lastContact,
            $personId,
            $groupId,
            $assignedTo,
            $fullName,
            $today->format('Y-m-d')
        );
        if ($gapTask !== null) {
            $createTask(
                (int) $gapTask['person_id'],
                (int) $gapTask['group_id'],
                (int) $gapTask['assigned_to'],
                (string) $gapTask['title'],
                (string) $gapTask['due_date'],
                (string) $gapTask['type']
            );
        }
        if (ChurchCare\needs_radar_notification($lastAttendance, $today->format('Y-m-d'))) {
            $personUrl = '/person?id=' . $personId;
            $exists = fetch_one('SELECT id FROM notification_events WHERE user_id = ? AND url = ? AND created_at > NOW() - INTERVAL 7 DAY LIMIT 1', [$assignedTo, $personUrl]);
            if (!$exists) {
                $insertNotice->execute([$assignedTo, 'Радар заботы', $fullName . ' не был больше 6 недель. Нужен пастырский контакт.', $personUrl]);
                queue_pastoral_bot_notification($assignedTo, $personId, 'Радар заботы', $fullName . ' не был больше 6 недель. Нужен пастырский контакт.', $personUrl);
            }
        }
    }
}


function can_show_pwa_install(array $user): bool
{
    if (function_exists('demo_can_show_pwa_install') && !demo_can_show_pwa_install()) {
        return false;
    }
    return role_rank((string) $user['role']) >= role_rank('leader');
}

function asset_path(string $webPath): string
{
    return dirname(__DIR__) . '/public' . (str_starts_with($webPath, '/') ? $webPath : '/' . $webPath);
}

function asset_url(string $webPath): string
{
    $fullPath = asset_path($webPath);
    $version = is_readable($fullPath) ? (string) filemtime($fullPath) : '0';

    return $webPath . '?v=' . $version;
}

function page_header(string $title, array $user): void
{
    $flash = flash();
    $webpushKey = webpush_config()['public_key'];
    $church = current_church();
    $brandCity = trim((string) ($church['city'] ?? $user['church_city'] ?? ''));
    if ($brandCity === '') {
        $brandCity = trim((string) ($user['church_name'] ?? ''));
    }
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    if (function_exists('demo_html_robots_meta')) {
        demo_html_robots_meta();
    }
    html_pwa_meta();
    html_font_assets();
    echo '<link rel="stylesheet" href="' . e(asset_url('/assets/app.css')) . '"><title>' . e($title) . '</title></head>';
    echo '<body data-vapid-public-key="' . e($webpushKey) . '"' . (geocoder_ready() && !(function_exists('demo_geocoder_allowed') && !demo_geocoder_allowed()) ? ' data-geocoder-ready="1"' : '') . '><div class="app-shell" data-ajax-shell>';
    echo '<aside class="sidebar">' . render_brand_markup([
        'href' => app_path('/'),
        'subtitle' => $brandCity !== '' ? $brandCity : null,
    ]);
    echo '<nav>';
    foreach (app_sidebar_nav($user) as $item) {
        if ($item['visible']) echo '<a href="' . e($item['href']) . '">' . e($item['label']) . '</a>';
    }
    echo '</nav>';
    echo '<div class="sidebar-footer"><a class="ghost full" href="' . e(app_path('/profile')) . '">Моя учетная запись</a><form method="post" action="' . e(app_path('/logout')) . '" data-no-ajax>' . csrf_field() . '<button class="ghost full">Выйти</button></form></div></aside>';
    $eyebrowChurch = trim((string) ($church['name'] ?? $user['church_name'] ?? app_config('default_church_name')));
    echo '<main class="content" data-ajax-content><header class="topbar"><div><p class="eyebrow">' . e($eyebrowChurch) . '</p><h1>' . e($title) . '</h1></div><a class="user-chip" href="' . e(app_path('/profile')) . '">' . e($user['name']) . '<span>' . e(role_label($user['role'])) . '</span></a></header>';
    if ($flash) {
        echo '<div class="flash">' . e($flash) . '</div>';
    }
    if (function_exists('demo_banner_markup')) {
        echo demo_banner_markup();
    }
    echo role_preview_banner_markup($user);
    if (can_show_pwa_install($user)) {
        echo '<section class="panel pwa-install-panel" data-pwa-install-panel><div><h2>Добавьте CRM на домашний экран</h2><p class="muted">После установки приложение откроется как отдельное PWA: без вкладок браузера, с иконкой на домашнем экране и быстрым доступом к задачам заботы.</p><p class="muted pwa-ios-help" data-pwa-ios-help hidden>На iPhone или iPad нажмите Поделиться и выберите На экран Домой.</p><p class="muted" data-pwa-ready-note hidden>Браузер готов открыть установку приложения.</p></div><div class="pwa-install-actions"><button class="primary" type="button" data-install-pwa>Как установить</button><button class="pwa-install-dismiss" type="button" data-dismiss-pwa-install aria-label="Скрыть подсказку установки">×</button></div></section>';
    }
}

function page_footer(): void
{
    echo '</main></div><script src="' . e(asset_url('/assets/app.js')) . '"></script></body></html>';
}
