<?php

declare(strict_types=1);

/**
 * Multi-church URL tenancy: /{slug}/crm-path
 *
 * Logical CRM paths (e.g. /groups) are rewritten to /{slug}/groups via app_path().
 * Platform-wide public surfaces (/homegroup, …) stay on the root;
 * /{slug}/homegroup is the church-scoped public catalog (tenant binds, logical /homegroup).
 * Prayer/miracle forms are church-scoped: /{slug}/prayer-request, /{slug}/miracle-share
 * (bare /prayer-request redirects to the default church slug).
 */

/** @var array<string, mixed>|null */
$GLOBALS['__church_tenant'] = $GLOBALS['__church_tenant'] ?? null;

/**
 * Slug used for bare CRM bookmarks (/groups → /{slug}/groups) and similar redirects.
 *
 * Resolution order:
 * 1. Explicit config legacy_default_slug (config/local.php on production)
 * 2. First non-demo, non-deleted church in DB (keeps existing installs stable)
 * 3. Fallback "example" matching database/init demo seed
 */
function legacy_default_slug(): string
{
    $configured = trim((string) (app_config('legacy_default_slug') ?? ''));
    if ($configured !== '') {
        return $configured;
    }

    try {
        if (function_exists('db_table_exists') && db_table_exists('churches') && function_exists('fetch_one')) {
            $sql = 'SELECT slug FROM churches WHERE deleted_at IS NULL';
            if (function_exists('db_column_exists') && db_column_exists('churches', 'is_demo')) {
                $sql .= ' AND COALESCE(is_demo, 0) = 0';
            }
            $sql .= ' ORDER BY id ASC LIMIT 1';
            $row = fetch_one($sql);
            $fromDb = trim((string) ($row['slug'] ?? ''));
            if ($fromDb !== '') {
                return $fromDb;
            }
        }
    } catch (Throwable) {
        // Fall through when DB is unavailable (unit tests, early bootstrap).
    }

    return 'example';
}

/**
 * First path segments that must never be treated as a church slug.
 *
 * @return list<string>
 */
function church_slug_reserved_segments(): array
{
    return [
        'login',
        'logout',
        'forgot-password',
        'reset-password',
        'account-invite',
        'role-invite',
        'regchurch',
        'church-invite', // legacy alias → /regchurch
        'homegroup',
        'about',
        'l',
        'join',
        'prayer-request',
        'miracle-share',
        'api',
        'social',
        'bot',
        'bots',
        'mcp',
        'geocode',
        'assets',
        'uploads',
        'admin',
        'push',
        'manifest.webmanifest',
        'service-worker.js',
        'favicon.ico',
        // CRM hubs / common first segments — cannot be church slugs
        'groups',
        'group',
        'care',
        'analytics',
        'users',
        'user',
        'profile',
        'settings',
        'church-settings',
        'church-page',
        'meeting',
        'person',
        'growth',
        'stats',
        'knowledge',
        'calendar',
        'services',
        'match',
        'map',
        'prayers',
        'miracles',
        'miracle',
        'newcomers',
        'regional',
        'church-analytics',
        'courses',
        'permissions',
        'backup', // retired whole-database export; segment stays reserved
        'privacy',
        'import',
        'meeting-report',
        'security',
        'role-preview',
        'participant-requests',
        'role-requests',
        'export-person',
        'preza',
        'demo',
    ];
}

function church_slug_is_valid(string $slug): bool
{
    if ($slug === '' || strlen($slug) > 64) {
        return false;
    }
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        return false;
    }
    return !in_array($slug, church_slug_reserved_segments(), true);
}

function normalize_church_slug(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?: '';
    $slug = trim($slug, '-');
    return mb_substr($slug, 0, 64);
}

function church_is_deleted(?array $church): bool
{
    if (!$church) {
        return true;
    }
    $deletedAt = $church['deleted_at'] ?? null;
    return $deletedAt !== null && $deletedAt !== '';
}

/**
 * @return array<string, mixed>|null
 */
function find_church_by_slug(string $slug, bool $includeDeleted = false): ?array
{
    $slug = strtolower(trim($slug));
    if ($slug === '' || !church_slug_is_valid($slug)) {
        return null;
    }
    $sql = 'SELECT * FROM churches WHERE slug = ?';
    if (!$includeDeleted) {
        $sql .= ' AND deleted_at IS NULL';
    }
    return fetch_one($sql . ' LIMIT 1', [$slug]);
}

/**
 * @return array<string, mixed>|null
 */
function find_church_by_id(int $churchId, bool $includeDeleted = false): ?array
{
    if ($churchId <= 0) {
        return null;
    }
    $sql = 'SELECT * FROM churches WHERE id = ?';
    if (!$includeDeleted) {
        $sql .= ' AND deleted_at IS NULL';
    }
    return fetch_one($sql . ' LIMIT 1', [$churchId]);
}

/**
 * @return list<array<string, mixed>>
 */
function list_churches_for_picker(): array
{
    $sql = 'SELECT id, name, city, slug FROM churches WHERE deleted_at IS NULL';
    if (function_exists('demo_schema_ready') && demo_schema_ready()) {
        $sql .= ' AND is_demo = 0';
    }
    $sql .= ' ORDER BY city IS NULL, city, name';

    return fetch_all($sql);
}

/**
 * Active churches for platform admin screens (with usage counts).
 *
 * @return list<array<string, mixed>>
 */
function list_churches_for_admin(): array
{
    $sql = 'SELECT c.*,
                (SELECT COUNT(*) FROM users u WHERE u.church_id = c.id) AS users_count,
                (SELECT COUNT(*) FROM home_groups g WHERE g.church_id = c.id AND g.is_active = 1) AS active_groups_count,
                (SELECT COUNT(*) FROM home_groups g WHERE g.church_id = c.id) AS groups_count
         FROM churches c
         WHERE c.deleted_at IS NULL';
    if (function_exists('demo_schema_ready') && demo_schema_ready()) {
        $sql .= ' AND c.is_demo = 0';
    }
    $sql .= ' ORDER BY c.name';

    return fetch_all($sql);
}

function normalize_church_timezone(string $timezone): string
{
    $timezone = trim($timezone);
    if ($timezone === '') {
        return 'Europe/Moscow';
    }
    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        return 'Europe/Moscow';
    }
    return $timezone;
}

/**
 * @return array{
 *   users:int,
 *   groups:int,
 *   active_groups:int,
 *   people:int,
 *   prayers:int,
 *   miracles:int
 * }
 */
function church_dependency_counts(int $churchId): array
{
    if ($churchId <= 0) {
        return [
            'users' => 0,
            'groups' => 0,
            'active_groups' => 0,
            'people' => 0,
            'prayers' => 0,
            'miracles' => 0,
        ];
    }

    $users = (int) (fetch_one('SELECT COUNT(*) AS c FROM users WHERE church_id = ?', [$churchId])['c'] ?? 0);
    $groups = (int) (fetch_one('SELECT COUNT(*) AS c FROM home_groups WHERE church_id = ?', [$churchId])['c'] ?? 0);
    $activeGroups = (int) (fetch_one('SELECT COUNT(*) AS c FROM home_groups WHERE church_id = ? AND is_active = 1', [$churchId])['c'] ?? 0);
    $people = (int) (fetch_one(
        'SELECT COUNT(*) AS c FROM people p
         INNER JOIN home_groups g ON g.id = p.group_id
         WHERE g.church_id = ? AND p.deleted_at IS NULL',
        [$churchId]
    )['c'] ?? 0);
    $prayers = (int) (fetch_one('SELECT COUNT(*) AS c FROM prayer_requests WHERE church_id = ?', [$churchId])['c'] ?? 0);
    $miracles = (int) (fetch_one('SELECT COUNT(*) AS c FROM miracles WHERE church_id = ?', [$churchId])['c'] ?? 0);

    return [
        'users' => $users,
        'groups' => $groups,
        'active_groups' => $activeGroups,
        'people' => $people,
        'prayers' => $prayers,
        'miracles' => $miracles,
    ];
}

/**
 * Why a church cannot be soft-deleted, or null when delete is allowed.
 */
function church_delete_block_reason(int $churchId): ?string
{
    $church = find_church_by_id($churchId, true);
    if (!$church) {
        return 'Церковь не найдена';
    }
    if (church_is_deleted($church)) {
        return 'Церковь уже удалена';
    }
    if (function_exists('is_demo_church') && is_demo_church($church)) {
        return 'Демо-церкви удаляются только через сброс демо или очистку просроченных копий';
    }

    $activeCountSql = 'SELECT COUNT(*) AS c FROM churches WHERE deleted_at IS NULL';
    if (function_exists('demo_schema_ready') && demo_schema_ready()) {
        $activeCountSql .= ' AND is_demo = 0';
    }
    $activeCount = (int) (fetch_one($activeCountSql)['c'] ?? 0);
    if ($activeCount <= 1) {
        return 'Нельзя удалить последнюю активную церковь';
    }

    $defaultId = (int) (app_config('default_church_id') ?: 1);
    if ($churchId === $defaultId) {
        return 'Нельзя удалить церковь по умолчанию платформы';
    }

    $slug = (string) ($church['slug'] ?? '');
    if ($slug !== '' && $slug === legacy_default_slug()) {
        return 'Нельзя удалить церковь с legacy slug (' . $slug . ')';
    }

    return null;
}

/**
 * Persist church settings. Slug changes require $canEditSlug (super_admin).
 *
 * @param array<string, mixed> $input
 * @return array{ok:true,church:array<string,mixed>}|array{ok:false,error:string}
 */
function save_church_settings(int $churchId, array $input, bool $canEditSlug): array
{
    $existing = find_church_by_id($churchId, true);
    if (!$existing || church_is_deleted($existing)) {
        return ['ok' => false, 'error' => 'Церковь не найдена'];
    }

    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'error' => 'Укажите название церкви'];
    }

    $city = trim((string) ($input['city'] ?? ''));
    $city = $city !== '' ? $city : null;
    $timezone = normalize_church_timezone((string) ($input['timezone'] ?? ($existing['timezone'] ?? 'Europe/Moscow')));

    $consent = array_key_exists('personal_data_consent_text', $input)
        ? trim((string) $input['personal_data_consent_text'])
        : trim((string) ($existing['personal_data_consent_text'] ?? ''));
    $consentValue = $consent !== '' ? $consent : null;

    if (function_exists('is_demo_template_church') && is_demo_template_church($existing)) {
        return ['ok' => false, 'error' => 'Шаблон демо-церкви нельзя изменять'];
    }

    $slug = (string) ($existing['slug'] ?? '');
    if ($canEditSlug && array_key_exists('slug', $input)) {
        $candidate = normalize_church_slug((string) $input['slug']);
        if (function_exists('demo_slug_is_reserved_public') && demo_slug_is_reserved_public($candidate)) {
            return ['ok' => false, 'error' => 'Slug demo зарезервирован для демонстрационного доступа'];
        }
        if (function_exists('demo_slug_is_workspace_internal') && demo_slug_is_workspace_internal($candidate)) {
            return ['ok' => false, 'error' => 'Этот префикс slug зарезервирован'];
        }
        $slug = unique_church_slug((string) $input['slug'], $churchId);
        if (!church_slug_is_valid($slug)) {
            return ['ok' => false, 'error' => 'Некорректный slug'];
        }
    }

    db()->prepare(
        'UPDATE churches
         SET name = ?, city = ?, slug = ?, timezone = ?, personal_data_consent_text = ?
         WHERE id = ? AND deleted_at IS NULL'
    )->execute([$name, $city, $slug, $timezone, $consentValue, $churchId]);

    $church = find_church_by_id($churchId);
    if (!$church) {
        return ['ok' => false, 'error' => 'Не удалось сохранить настройки церкви'];
    }

    if (current_church_id() === $churchId) {
        set_current_church($church);
    }

    return ['ok' => true, 'church' => $church];
}

/**
 * Soft-delete a church: deactivate groups, soft-delete people, hide tenant.
 *
 * @return array{ok:true,counts:array<string,int>}|array{ok:false,error:string}
 */
function soft_delete_church(int $churchId): array
{
    $block = church_delete_block_reason($churchId);
    if ($block !== null) {
        return ['ok' => false, 'error' => $block];
    }

    $counts = church_dependency_counts($churchId);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE home_groups
             SET is_active = 0, closed_at = COALESCE(closed_at, CURDATE())
             WHERE church_id = ? AND is_active = 1'
        )->execute([$churchId]);

        $pdo->prepare(
            'UPDATE people p
             INNER JOIN home_groups g ON g.id = p.group_id
             SET p.deleted_at = COALESCE(p.deleted_at, NOW())
             WHERE g.church_id = ? AND p.deleted_at IS NULL'
        )->execute([$churchId]);

        $pdo->prepare('UPDATE users SET group_id = NULL WHERE church_id = ?')->execute([$churchId]);

        $stmt = $pdo->prepare(
            'UPDATE churches SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$churchId]);
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('Не удалось пометить церковь удалённой');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if (current_church_id() === $churchId) {
        set_current_church(null);
    }

    return ['ok' => true, 'counts' => $counts];
}

/**
 * @param array<string, mixed>|null $church
 */
function set_current_church(?array $church): void
{
    $GLOBALS['__church_tenant'] = $church;
}

/**
 * @return array<string, mixed>|null
 */
function current_church(): ?array
{
    $church = $GLOBALS['__church_tenant'] ?? null;
    return is_array($church) ? $church : null;
}

function current_church_slug(): ?string
{
    $church = current_church();
    if (!$church) {
        return null;
    }
    if (function_exists('is_demo_church') && is_demo_church($church)) {
        return function_exists('demo_public_slug') ? demo_public_slug() : 'demo';
    }
    $slug = trim((string) ($church['slug'] ?? ''));
    return $slug !== '' ? $slug : null;
}

function current_church_id(): ?int
{
    $church = current_church();
    if (!$church) {
        return null;
    }
    $id = (int) ($church['id'] ?? 0);
    return $id > 0 ? $id : null;
}

function is_super_admin(array $user): bool
{
    return ($user['role'] ?? '') === 'super_admin';
}

function require_super_admin(array $user): void
{
    if (!is_super_admin($user)) {
        http_response_code(403);
        exit('Доступ только для суперадминистратора.');
    }
}

function is_church_admin(array $user): bool
{
    return ($user['role'] ?? '') === 'admin';
}

function is_platform_admin_role(string $role): bool
{
    return $role === 'super_admin';
}

/**
 * Whether the account may authenticate into the given church tenant.
 */
function user_can_access_church(array $user, int $churchId): bool
{
    if ($churchId <= 0) {
        return false;
    }
    if (is_super_admin($user)) {
        return true;
    }
    return (int) ($user['church_id'] ?? 0) === $churchId;
}

/**
 * Effective church id for data scoping inside a tenant request.
 * Prefer the URL tenant (already access-checked); fall back to membership.
 */
function effective_scope_church_id(array $user): ?int
{
    $tenantId = current_church_id();
    if ($tenantId !== null && $tenantId > 0) {
        return $tenantId;
    }
    if (is_super_admin($user)) {
        return null;
    }
    $id = (int) ($user['church_id'] ?? 0);
    return $id > 0 ? $id : null;
}

/**
 * SQL fragment for church-scoped rows that may still have legacy NULL church_id.
 * NULL is attributed only to the platform default church, never leaked to others.
 *
 * @return array{0:string,1:list<mixed>}
 */
function church_id_scope_sql(string $column, ?int $churchId): array
{
    if ($churchId === null || $churchId <= 0) {
        return ['1=1', []];
    }

    $defaultId = (int) (app_config('default_church_id') ?: 1);
    if ($churchId === $defaultId) {
        return ["($column = ? OR $column IS NULL)", [$churchId]];
    }

    return ["$column = ?", [$churchId]];
}

/**
 * Paths that stay on the platform root (never prefixed with /{slug}).
 */
function is_platform_root_path(string $path): bool
{
    $path = '/' . trim($path, '/');
    if ($path === '/') {
        // Logical "/" means church home when generating CRM URLs via app_path().
        // Platform picker uses platform_path('/') explicitly.
        return false;
    }

    $roots = [
        '/forgot-password',
        '/reset-password',
        '/account-invite',
        '/role-invite',
        '/regchurch',
        '/church-invite', // legacy alias → /regchurch
        '/homegroup',
        '/homegroup/join',
        '/join',
        '/api/homegroups',
        '/social/start',
        '/social/callback',
        '/mcp',
        '/admin',
        '/geocode/reverse',
        '/preza',
        '/demo/enter',
        '/demo/reset',
    ];
    foreach ($roots as $root) {
        if ($path === $root) {
            return true;
        }
    }

    foreach ([
        '/l/', '/social/', '/bot/', '/bots/', '/api/', '/geocode/', '/assets/', '/uploads/', '/admin/', '/preza/',
        '/mcp/', '/.well-known/',
    ] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return false;
}

/**
 * CRM/auth paths that existed before slug routing — redirect to /{legacy_slug}...
 *
 * @return list<string>
 */
function legacy_crm_paths(): array
{
    return [
        '/login',
        '/logout',
        '/',
        '/groups',
        '/group',
        '/care',
        '/analytics',
        '/users',
        '/user',
        '/participant-requests',
        '/role-requests',
        '/profile',
        '/settings',
        '/church-settings',
        '/church-page',
        '/meeting',
        '/person',
        '/growth',
        '/stats',
        '/knowledge',
        '/knowledge/system-guide',
        '/knowledge/home-group-leader-course',
        '/calendar',
        '/services',
        '/match',
        '/map',
        '/prayers',
        '/miracles',
        '/miracle',
        '/newcomers',
        '/regional',
        '/districts',
        '/church-analytics',
        '/courses',
        '/permissions',
        '/privacy',
        '/import',
        '/meeting-report',
        '/security',
        '/role-preview',
        '/export-person',
    ];
}

function is_legacy_crm_path(string $path): bool
{
    $path = '/' . trim($path, '/');
    if ($path === '/') {
        return false; // "/" is platform picker now
    }
    if (in_array($path, legacy_crm_paths(), true)) {
        return true;
    }
    // POST action endpoints (/-create, /-save, etc.) and other CRM verbs
    foreach (legacy_crm_paths() as $crm) {
        if ($crm !== '/' && str_starts_with($path, $crm . '/')) {
            return true;
        }
    }
    // Mutating endpoints historically at root (group-create, person-update, …).
    // Do NOT treat unknown church-like slugs (/church-3, /korolev-typo) as legacy CRM —
    // that used to redirect them under /pushkino and hide a 404.
    if (preg_match('#^/[a-z0-9]+(?:-[a-z0-9]+)*$#', $path)) {
        if (is_platform_root_path($path)) {
            return false;
        }
        $segment = ltrim($path, '/');
        if (in_array($segment, church_slug_reserved_segments(), true)) {
            return false;
        }

        return in_array($segment, crm_logical_root_segments(), true);
    }

    return false;
}

/**
 * Absolute path that must stay on the platform root (picker, public forms, /admin).
 */
function platform_path(string $path = '/'): string
{
    [$pathname, $query, $fragment] = app_path_parts($path);
    if ($pathname === '' || $pathname === '/') {
        return '/' . $query . $fragment;
    }
    return $pathname . $query . $fragment;
}

/**
 * Human-readable church label for public UI (city preferred, else name).
 *
 * @param array<string, mixed> $church
 */
function church_public_label(array $church): string
{
    $city = trim((string) ($church['city'] ?? $church['church_city'] ?? ''));
    if ($city !== '') {
        return $city;
    }
    $name = trim((string) ($church['name'] ?? $church['church_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $slug = trim((string) ($church['slug'] ?? $church['church_slug'] ?? ''));

    return $slug !== '' ? $slug : 'Церковь';
}

/**
 * Public home-group catalog URL.
 * Platform-wide map: /homegroup
 * Church-scoped map: /{slug}/homegroup
 *
 * @param array<string, mixed>|null $church null → platform-wide catalog
 */
function public_homegroup_catalog_path(?array $church = null): string
{
    if ($church === null) {
        return '/homegroup';
    }
    $slug = trim((string) ($church['slug'] ?? ''));
    if ($slug !== '' && church_slug_is_valid($slug)) {
        return '/' . $slug . '/homegroup';
    }

    return '/homegroup';
}

/**
 * Public church mini-landing URL: /l/{slug}
 *
 * @param array<string, mixed> $church
 */
function public_church_about_path(array $church): string
{
    $slug = trim((string) ($church['slug'] ?? ''));
    if ($slug === '' || !church_slug_is_valid($slug)) {
        return '/';
    }

    return '/l/' . $slug;
}

/**
 * Public prayer form URL for a church: /{slug}/prayer-request
 *
 * @param array<string, mixed>|null $church null → current tenant, else default church
 */
function public_prayer_request_path(?array $church = null): string
{
    $church = public_church_form_church($church);
    $slug = trim((string) ($church['slug'] ?? ''));
    if ($slug === '' || !church_slug_is_valid($slug)) {
        return '/prayer-request';
    }

    return '/' . $slug . '/prayer-request';
}

/**
 * Public miracle form URL for a church: /{slug}/miracle-share
 *
 * @param array<string, mixed>|null $church null → current tenant, else default church
 */
function public_miracle_share_path(?array $church = null): string
{
    $church = public_church_form_church($church);
    $slug = trim((string) ($church['slug'] ?? ''));
    if ($slug === '' || !church_slug_is_valid($slug)) {
        return '/miracle-share';
    }

    return '/' . $slug . '/miracle-share';
}

/**
 * @param array<string, mixed>|null $church
 * @return array<string, mixed>
 */
function public_church_form_church(?array $church = null): array
{
    if ($church !== null && (int) ($church['id'] ?? 0) > 0) {
        return $church;
    }
    $tenant = function_exists('current_church') ? current_church() : null;
    if ($tenant && (int) ($tenant['id'] ?? 0) > 0 && !church_is_deleted($tenant)) {
        return $tenant;
    }
    if (function_exists('default_church')) {
        $default = default_church();
        if ($default) {
            return $default;
        }
    }

    return [];
}

/**
 * @return array{0: string, 1: string, 2: string} pathname, query incl. ?, fragment incl. #
 */
function app_path_parts(string $path): array
{
    if (preg_match('#^https?://#i', $path)) {
        return [$path, '', ''];
    }

    $fragment = '';
    if (str_contains($path, '#')) {
        [$path, $frag] = explode('#', $path, 2);
        $fragment = '#' . $frag;
    }
    $query = '';
    if (str_contains($path, '?')) {
        [$path, $q] = explode('?', $path, 2);
        $query = '?' . $q;
    }

    $path = trim($path);
    if ($path === '' || $path === '/') {
        return ['/', $query, $fragment];
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    // collapse duplicate slashes
    $path = preg_replace('#/+#', '/', $path) ?: '/';
    if (strlen($path) > 1) {
        $path = rtrim($path, '/');
    }

    return [$path, $query, $fragment];
}

/**
 * Build a URL for a logical app path. CRM paths get /{slug} prefix.
 */
function app_path(string $path): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    [$pathname, $query, $fragment] = app_path_parts($path);

    if (path_has_church_slug_prefix($pathname)) {
        return $pathname . $query . $fragment;
    }

    if (is_platform_root_path($pathname)) {
        return $pathname . $query . $fragment;
    }

    $slug = current_church_slug() ?? legacy_default_slug();
    if ($pathname === '/') {
        return '/' . $slug . $query . $fragment;
    }

    return '/' . $slug . $pathname . $query . $fragment;
}

/**
 * First path segment of logical CRM routes (not church slugs).
 *
 * @return list<string>
 */
function crm_logical_root_segments(): array
{
    static $segments = null;
    if ($segments !== null) {
        return $segments;
    }

    $segments = church_slug_reserved_segments();
    foreach (legacy_crm_paths() as $path) {
        if ($path === '/') {
            continue;
        }
        $parts = explode('/', trim($path, '/'));
        if ($parts[0] !== '') {
            $segments[] = $parts[0];
        }
    }
    foreach ([
        'person-create', 'person-update', 'person-delete', 'person-set-group',
        'person-group-add', 'person-group-remove', 'person-group-primary',
        'person-account-invite', 'person-field-update',
        'group-create', 'group-update', 'group-delete',
        'meeting-create', 'meeting-save', 'meeting-report-save',
        'contact-add', 'prayer-add', 'prayer-update',
        'miracle-add', 'miracle-archive', 'miracle-from-prayer',
        'task-done', 'church-settings-save', 'church-page-save', 'church-page-image-upload', 'profile-update', 'password-change',
        'notification-settings', 'user-update', 'user-access-update',
        'user-password-reset', 'user-delete', 'participant-approve', 'participant-reject',
        'role-invite-approve', 'role-invite-reject', 'knowledge-save', 'knowledge-image-upload',
        'permissions-save', 'settings-security', 'vapid-generate', 'two-factor-toggle',
        'service-add', 'course-pass', 'import-people', 'deletion-request', 'deletion-complete',
        'backup-restore', 'backup-export', 'social-unlink', 'bot-link-token', 'bot-unlink',
        'mcp-api-key-create', 'mcp-api-key-revoke', 'role-preview-start', 'role-preview-stop',
        'push', 'export-person', 'default-logo-save',
    ] as $segment) {
        $segments[] = $segment;
    }

    $segments = array_values(array_unique($segments));
    return $segments;
}

function path_has_church_slug_prefix(string $pathname): bool
{
    $pathname = '/' . trim($pathname, '/');
    if ($pathname === '/') {
        return false;
    }
    $parts = explode('/', trim($pathname, '/'));
    $first = $parts[0] ?? '';
    if ($first === '' || in_array($first, crm_logical_root_segments(), true)) {
        return false;
    }
    if ($first === current_church_slug()) {
        return true;
    }
    if ($first === legacy_default_slug()) {
        return true;
    }
    // /{slug}/{crm...} already rewritten
    if (isset($parts[1]) && in_array($parts[1], crm_logical_root_segments(), true) && church_slug_is_valid($first)) {
        return true;
    }
    // bare /{slug} church home
    if (!isset($parts[1]) && church_slug_is_valid($first)) {
        return true;
    }

    return false;
}

/**
 * Strip /{slug} from request path and bind tenant. Returns logical path.
 *
 * @return array{0: string, 1: bool} [logicalPath, tenantBound]
 */
function resolve_church_tenant_from_request_path(string $requestPath): array
{
    $requestPath = '/' . trim($requestPath, '/');
    if ($requestPath === '/') {
        return ['/', false];
    }

    $parts = explode('/', trim($requestPath, '/'));
    $first = strtolower($parts[0] ?? '');
    if ($first === '' || in_array($first, church_slug_reserved_segments(), true)) {
        return [$requestPath === '//' ? '/' : $requestPath, false];
    }

    if (!church_slug_is_valid($first)) {
        return [$requestPath, false];
    }

    if (function_exists('demo_slug_is_workspace_internal') && demo_slug_is_workspace_internal($first)) {
        return [$requestPath, false];
    }

    $church = find_church_by_slug($first);
    if (!$church) {
        return [$requestPath, false];
    }
    if (function_exists('is_demo_church') && is_demo_church($church)) {
        return [$requestPath, false];
    }

    set_current_church($church);
    array_shift($parts);
    $logical = $parts === [] ? '/' : '/' . implode('/', $parts);

    return [$logical, true];
}

function legacy_redirect_target_slug(): string
{
    $current = current_church_slug();
    if ($current !== null && $current !== '') {
        return $current;
    }

    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($referer !== '') {
        $refPath = parse_url($referer, PHP_URL_PATH) ?: '';
        $parts = explode('/', trim($refPath, '/'));
        $first = strtolower((string) ($parts[0] ?? ''));
        if ($first !== '' && church_slug_is_valid($first)) {
            $church = find_church_by_slug($first);
            if ($church) {
                return (string) $church['slug'];
            }
        }
    }

    $user = function_exists('current_user') ? current_user() : null;
    if (is_array($user)) {
        $slug = trim((string) ($user['church_slug'] ?? ''));
        if ($slug !== '') {
            return $slug;
        }
        $churchId = (int) ($user['church_id'] ?? 0);
        if ($churchId > 0) {
            $church = find_church_by_id($churchId);
            $slug = trim((string) ($church['slug'] ?? ''));
            if ($slug !== '') {
                return $slug;
            }
        }
    }

    return legacy_default_slug();
}

function redirect_legacy_crm_to_default_church(string $path, string $queryString = ''): never
{
    $slug = legacy_redirect_target_slug();
    $path = '/' . trim($path, '/');
    if ($path === '/') {
        $target = '/' . $slug;
    } else {
        $target = '/' . $slug . $path;
    }
    if ($queryString !== '') {
        $target .= '?' . $queryString;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $code = $method === 'GET' || $method === 'HEAD' ? 302 : 307;
    header('Location: ' . $target, true, $code);
    exit;
}

function unique_church_slug(string $base, ?int $excludeChurchId = null): string
{
    $base = normalize_church_slug($base);
    if ($base === '' || !church_slug_is_valid($base)) {
        $base = 'church';
    }
    if (function_exists('demo_slug_is_reserved_public') && demo_slug_is_reserved_public($base)) {
        $base = 'church';
    }
    if (function_exists('demo_slug_is_workspace_internal') && demo_slug_is_workspace_internal($base)) {
        $base = 'church';
    }
    if (!church_slug_is_valid($base)) {
        $base = 'church';
    }

    $slug = $base;
    $suffix = 2;
    while (true) {
        $params = [$slug];
        $sql = 'SELECT id FROM churches WHERE slug = ?';
        if ($excludeChurchId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeChurchId;
        }
        if (!fetch_one($sql . ' LIMIT 1', $params)) {
            return $slug;
        }
        $tail = '-' . $suffix++;
        $slug = mb_substr($base, 0, 64 - strlen($tail)) . $tail;
        if (!church_slug_is_valid($slug)) {
            $slug = 'church' . $tail;
        }
    }
}
