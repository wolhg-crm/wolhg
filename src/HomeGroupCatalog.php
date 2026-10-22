<?php

declare(strict_types=1);

function default_church(): ?array
{
    static $church = null;
    if ($church !== null) {
        return $church;
    }

    $churchId = (int) (app_config('default_church_id') ?: 1);
    $church = find_church_by_id($churchId);
    if ($church && !(function_exists('is_demo_church') && is_demo_church($church))) {
        return $church;
    }

    $sql = 'SELECT * FROM churches WHERE deleted_at IS NULL';
    if (function_exists('demo_schema_ready') && demo_schema_ready()) {
        $sql .= ' AND is_demo = 0';
    }
    $sql .= ' ORDER BY id ASC LIMIT 1';
    $church = fetch_one($sql);

    return $church ?: null;
}

function maps_api_key(): string
{
    return (string) (app_config('yandex.maps_api_key') ?: app_config('yandex.geocoder_key') ?: '');
}

function maps_api_ready(): bool
{
    return maps_api_key() !== '';
}

/**
 * @param list<string> $selectedLabels
 */
function filter_multiselect_summary(array $selectedLabels): string
{
    $count = count($selectedLabels);
    if ($count === 0) {
        return 'Любой';
    }
    if ($count === 1) {
        return $selectedLabels[0];
    }
    if ($count === 2) {
        return $selectedLabels[0] . ', ' . $selectedLabels[1];
    }

    return $count . ' выбрано';
}

/**
 * @param array<string, string> $options
 * @param list<string|int> $selected
 */
function render_filter_multiselect(string $name, string $label, array $options, array $selected = []): string
{
    $selectedMap = array_fill_keys(array_map('strval', $selected), true);
    $selectedLabels = [];
    foreach ($options as $value => $optionLabel) {
        if (isset($selectedMap[(string) $value])) {
            $selectedLabels[] = (string) $optionLabel;
        }
    }

    $summary = filter_multiselect_summary($selectedLabels);
    $fieldId = 'filter-' . preg_replace('/[^a-z0-9_-]+/i', '-', $name);
    $placeholderClass = $selectedLabels === [] ? ' is-placeholder' : '';

    $html = '<div class="filter-dropdown" data-filter-dropdown>';
    $html .= '<span class="filter-dropdown__title" id="' . e($fieldId) . '-label">' . e($label) . '</span>';
    $html .= '<button type="button" class="filter-dropdown__trigger" aria-expanded="false" aria-haspopup="listbox" aria-labelledby="' . e($fieldId) . '-label" aria-controls="' . e($fieldId) . '-panel" id="' . e($fieldId) . '-trigger">';
    $html .= '<span class="filter-dropdown__value' . $placeholderClass . '" data-filter-summary>' . e($summary) . '</span>';
    $html .= '<span class="filter-dropdown__chevron" aria-hidden="true"></span>';
    $html .= '</button>';
    $html .= '<div class="filter-dropdown__panel" id="' . e($fieldId) . '-panel" role="listbox" aria-multiselectable="true" aria-labelledby="' . e($fieldId) . '-label" hidden>';
    $html .= '<ul class="filter-dropdown__options">';
    foreach ($options as $value => $optionLabel) {
        $valueStr = (string) $value;
        $checked = isset($selectedMap[$valueStr]) ? ' checked' : '';
        $html .= '<li><label class="filter-dropdown__option">';
        $html .= '<input type="checkbox" name="' . e($name) . '[]" value="' . e($valueStr) . '"' . $checked . '>';
        $html .= '<span>' . e((string) $optionLabel) . '</span>';
        $html .= '</label></li>';
    }
    $html .= '</ul>';
    $html .= '<div class="filter-dropdown__footer">';
    $html .= '<button type="button" class="ghost filter-dropdown__clear" data-filter-clear>Сбросить</button>';
    $html .= '<button type="button" class="primary filter-dropdown__apply" data-filter-apply>Готово</button>';
    $html .= '</div>';
    $html .= '</div></div>';

    return $html;
}

/**
 * @param array<string, string> $options
 * @param list<string|int> $selected
 */
function render_multiselect(string $name, array $options, array $selected = [], array $attrs = []): string
{
    $attributes = html_attrs(array_merge([
        'name' => $name . '[]',
        'multiple' => 'multiple',
        'class' => 'multi-select',
    ], $attrs));
    $selectedMap = array_fill_keys(array_map('strval', $selected), true);
    $html = '<select' . $attributes . '>';
    foreach ($options as $value => $label) {
        $valueStr = (string) $value;
        $isSelected = isset($selectedMap[$valueStr]) ? ' selected' : '';
        $html .= '<option value="' . e($valueStr) . '"' . $isSelected . '>' . e($label) . '</option>';
    }
    $html .= '</select>';

    return $html;
}

/**
 * @param array<string, string> $options
 */
function render_select(string $name, array $options, ?string $selected = null, bool $allowEmpty = true, string $emptyLabel = '—', array $attrs = []): string
{
    $attributes = html_attrs(array_merge(['name' => $name, 'class' => 'select-field'], $attrs));
    $html = '<select' . $attributes . '>';
    if ($allowEmpty) {
        $html .= '<option value="">' . e($emptyLabel) . '</option>';
    }
    foreach ($options as $value => $label) {
        $valueStr = (string) $value;
        $isSelected = ((string) $selected === $valueStr) ? ' selected' : '';
        $html .= '<option value="' . e($valueStr) . '"' . $isSelected . '>' . e($label) . '</option>';
    }
    $html .= '</select>';

    return $html;
}

function render_home_group_settings_fields(array $group): string
{
    $types = ChurchCare\home_group_types_from_value($group['group_types'] ?? null);
    $timeStart = ChurchCare\home_group_time_hm(isset($group['meeting_time_start']) ? (string) $group['meeting_time_start'] : null);
    $timeEnd = ChurchCare\home_group_time_hm(isset($group['meeting_time_end']) ? (string) $group['meeting_time_end'] : null);

    return '<div class="form-grid">'
        . '<label>Возрастная категория' . render_select('age_category', ChurchCare\home_group_age_categories(), isset($group['age_category']) ? (string) $group['age_category'] : null) . '</label>'
        . '<label>День недели' . render_select('meeting_weekday', ChurchCare\home_group_weekdays(), isset($group['meeting_weekday']) ? (string) $group['meeting_weekday'] : null) . '</label>'
        . '</div>'
        . render_filter_multiselect('group_types', 'Тип группы', ChurchCare\home_group_types(), $types)
        . '<div class="form-grid">'
        . '<label>Время начала<input name="meeting_time_start" type="time" value="' . e($timeStart) . '"></label>'
        . '<label>Время окончания<input name="meeting_time_end" type="time" value="' . e($timeEnd) . '"></label>'
        . '</div>'
        . '<label>Короткий адрес (метро / город)<input name="short_address" maxlength="255" placeholder="Митино / Москва" value="' . e((string) ($group['short_address'] ?? '')) . '"></label>';
}

/**
 * Shared visibility for public catalog/join/API.
 * null $churchId → all non-deleted, non-demo churches.
 * A concrete demo churchId is allowed only inside that demo workspace session.
 *
 * @return array{0: string, 1: list<mixed>}
 */
function public_home_groups_visibility_sql(?int $churchId = null): array
{
    $where = 'g.is_active = 1 AND c.deleted_at IS NULL';
    $params = [];
    if ($churchId !== null && $churchId > 0) {
        $where .= ' AND g.church_id = ?';
        $params[] = $churchId;
        if (function_exists('church_is_demo_by_id') && church_is_demo_by_id($churchId)) {
            $currentId = (int) ((function_exists('current_church') ? current_church() : null)['id'] ?? 0);
            if (!(function_exists('is_demo_session') && is_demo_session()) || $currentId !== $churchId) {
                $where .= ' AND 1 = 0';
            }
        }
    } elseif (function_exists('public_sql_exclude_demo_churches')) {
        $where .= public_sql_exclude_demo_churches('c');
    }

    return [$where, $params];
}

/**
 * Scope clause for the public home-group catalog (requires coordinates).
 *
 * @return array{0: string, 1: list<mixed>}
 */
function public_home_groups_scope_sql(?int $churchId = null): array
{
    [$where, $params] = public_home_groups_visibility_sql($churchId);
    $where .= ' AND g.latitude IS NOT NULL AND g.longitude IS NOT NULL';

    return [$where, $params];
}

/**
 * Church bound by /{slug}/… for public catalog/join, or null for platform-wide /homegroup.
 *
 * @return array<string, mixed>|null
 */
function homegroup_catalog_scope_church(): ?array
{
    $church = current_church();
    if ($church && !church_is_deleted($church)) {
        return $church;
    }

    return null;
}

/**
 * Resolve church for /api/homegroups (?church=slug or ?church_id=).
 *
 * @return array{0: array<string, mixed>|null, 1: string|null} [church|null for all, error]
 */
function homegroup_catalog_api_church_from_request(): array
{
    $tenant = homegroup_catalog_scope_church();
    if ($tenant) {
        if (function_exists('church_visible_on_public_catalog') && !church_visible_on_public_catalog($tenant)) {
            return [null, 'Church not found'];
        }

        return [$tenant, null];
    }

    $slug = strtolower(trim((string) ($_GET['church'] ?? '')));
    if ($slug !== '') {
        $church = find_church_by_slug($slug);
        if (!$church || (function_exists('church_visible_on_public_catalog') && !church_visible_on_public_catalog($church))) {
            return [null, 'Church not found'];
        }

        return [$church, null];
    }

    $churchId = (int) ($_GET['church_id'] ?? 0);
    if ($churchId > 0) {
        $church = find_church_by_id($churchId);
        if (!$church || (function_exists('church_visible_on_public_catalog') && !church_visible_on_public_catalog($church))) {
            return [null, 'Church not found'];
        }

        return [$church, null];
    }

    return [null, null];
}

/**
 * Multiselect options for the platform-wide church filter.
 * Shown only when there are at least two churches.
 *
 * @param list<array<string, mixed>> $churches
 * @return array<string, string> id => label
 */
function homegroup_catalog_church_filter_options(array $churches): array
{
    if (count($churches) < 2) {
        return [];
    }

    $options = [];
    foreach ($churches as $church) {
        $id = (int) ($church['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $options[(string) $id] = church_public_label($church);
    }

    return $options;
}

/**
 * @param int|null $churchId null = all non-deleted churches
 * @return list<array<string, mixed>>
 */
function fetch_public_home_groups(?int $churchId = null, ?array $filters = null): array
{
    [$scopeSql, $scopeParams] = public_home_groups_scope_sql($churchId);
    [$filterSql, $filterParams] = ChurchCare\home_group_catalog_filter_sql($filters, 'g');
    $where = $scopeSql;
    $params = $scopeParams;
    if ($filterSql !== '') {
        $where .= ' AND ' . $filterSql;
        $params = array_merge($params, $filterParams);
    }

    $rows = fetch_all(
        "SELECT g.*,
                leader.full_name AS leader_name,
                c.name AS church_name,
                c.city AS church_city,
                c.slug AS church_slug,
                COUNT(p.id) AS people_count
         FROM home_groups g
         INNER JOIN churches c ON c.id = g.church_id
         LEFT JOIN people leader ON leader.id = g.leader_person_id
         LEFT JOIN people p ON p.group_id = g.id AND p.deleted_at IS NULL
         WHERE $where
         GROUP BY g.id
         ORDER BY COALESCE(c.city, c.name), g.name",
        $params
    );

    return array_map(static fn(array $row): array => ChurchCare\home_group_catalog_row($row), $rows);
}

/**
 * @param int|null $churchId null = all non-deleted churches
 * @return list<array<string, mixed>>
 */
function fetch_public_home_group_leaders(?int $churchId = null): array
{
    [$where, $params] = public_home_groups_visibility_sql($churchId);

    return fetch_all(
        "SELECT g.id, g.name, g.church_id,
                c.name AS church_name, c.city AS church_city, c.slug AS church_slug,
                leader.full_name AS leader_name
         FROM home_groups g
         INNER JOIN churches c ON c.id = g.church_id
         LEFT JOIN people leader ON leader.id = g.leader_person_id
         WHERE $where
         ORDER BY COALESCE(c.city, c.name), COALESCE(leader.full_name, g.name)",
        $params
    );
}

/**
 * Active public group by id, optionally constrained to a church.
 *
 * @return array<string, mixed>|null
 */
function fetch_public_home_group_by_id(int $groupId, ?int $churchId = null): ?array
{
    if ($groupId <= 0) {
        return null;
    }

    [$visSql, $visParams] = public_home_groups_visibility_sql($churchId);
    $where = 'g.id = ? AND ' . $visSql;
    $params = array_merge([$groupId], $visParams);

    return fetch_one(
        "SELECT g.*, leader.full_name AS leader_name,
                c.name AS church_name, c.city AS church_city, c.slug AS church_slug,
                c.personal_data_consent_text AS church_consent_text
         FROM home_groups g
         INNER JOIN churches c ON c.id = g.church_id
         LEFT JOIN people leader ON leader.id = g.leader_person_id
         WHERE $where
         LIMIT 1",
        $params
    );
}

/**
 * Active public group by exact leader full name (scoped when churchId set).
 *
 * @return array<string, mixed>|null
 */
function fetch_public_home_group_by_leader_name(string $leaderName, ?int $churchId = null): ?array
{
    $leaderName = trim($leaderName);
    if ($leaderName === '') {
        return null;
    }

    [$visSql, $visParams] = public_home_groups_visibility_sql($churchId);
    $where = $visSql . ' AND leader.full_name = ?';
    $params = array_merge($visParams, [$leaderName]);

    return fetch_one(
        "SELECT g.*, leader.full_name AS leader_name,
                c.name AS church_name, c.city AS church_city, c.slug AS church_slug,
                c.personal_data_consent_text AS church_consent_text
         FROM home_groups g
         INNER JOIN churches c ON c.id = g.church_id
         LEFT JOIN people leader ON leader.id = g.leader_person_id
         WHERE $where
         LIMIT 1",
        $params
    );
}

function homegroup_catalog_page(): void
{
    $scopeChurch = homegroup_catalog_scope_church();
    $churchId = $scopeChurch ? (int) $scopeChurch['id'] : null;
    $catalogPath = public_homegroup_catalog_path($scopeChurch);
    $showChurchLabels = $scopeChurch === null;
    $pickerChurches = $scopeChurch ? [] : list_churches_for_picker();
    $churchFilterOptions = homegroup_catalog_church_filter_options($pickerChurches);

    $filters = ChurchCare\parse_home_group_catalog_filters($_GET);
    if ($scopeChurch) {
        // Church already fixed by /{slug}/homegroup — ignore churches[] from query.
        $filters['church_ids'] = [];
    } elseif ($churchFilterOptions !== []) {
        // Drop ids that are no longer in the picker (deleted churches).
        $allowedIds = array_map('intval', array_keys($churchFilterOptions));
        $filters['church_ids'] = array_values(array_filter(
            $filters['church_ids'],
            static fn(int $id): bool => in_array($id, $allowedIds, true)
        ));
    }

    $groups = fetch_public_home_groups($churchId, $filters);
    $groupsJson = json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $mapKey = maps_api_key();
    $mapReady = maps_api_ready();

    $title = 'Домашние группы';
    $subtitle = 'Еженедельные встречи в небольших группах: общение, изучение Писания и молитва. Выберите группу на карте или в списке.';
    $brandSubtitle = null;
    if ($scopeChurch) {
        $brandSubtitle = church_public_label($scopeChurch);
        $title = 'Домашние группы — ' . $brandSubtitle;
        $subtitle = 'Группы церкви «' . church_public_label($scopeChurch) . '». Выберите группу на карте или в списке.';
    }

    public_page_header($title);
    echo '<div class="public-shell homegroup-shell">';
    echo '<header class="homegroup-hero">' . render_brand_markup(['variant' => 'hero', 'subtitle' => $brandSubtitle]);
    echo '<h1>Домашние группы</h1>';
    if ($scopeChurch) {
        echo '<p class="eyebrow">' . e(church_public_label($scopeChurch)) . '</p>';
        echo '<p class="muted">' . e($subtitle) . '</p>';
        echo '<p class="muted homegroup-scope-note"><a href="/homegroup">Смотреть группы всех церквей</a></p>';
    } else {
        echo '<p class="muted">' . e($subtitle) . '</p>';
        if (count($pickerChurches) > 1) {
            echo '<p class="muted homegroup-scope-note">Карта всех церквей. Можно отфильтровать по церкви ниже или открыть отдельно: ';
            $links = [];
            foreach ($pickerChurches as $church) {
                $links[] = '<a href="' . e(public_homegroup_catalog_path($church)) . '">' . e(church_public_label($church)) . '</a>';
            }
            echo implode(' · ', $links) . '</p>';
        }
    }
    echo '</header>';

    echo '<form method="get" action="' . e($catalogPath) . '" class="homegroup-filters panel stack" data-homegroup-filters>';
    echo '<p class="homegroup-filters__intro muted">Нажмите на фильтр и отметьте один или несколько вариантов — клавиатура не нужна.</p>';
    echo '<div class="homegroup-filters__grid">';
    if ($churchFilterOptions !== []) {
        echo render_filter_multiselect('churches', 'Церковь', $churchFilterOptions, $filters['church_ids']);
    }
    echo render_filter_multiselect('age', 'Возраст', ChurchCare\home_group_age_categories(), $filters['ages']);
    echo render_filter_multiselect('weekday', 'День недели', ChurchCare\home_group_weekdays(), $filters['weekdays']);
    echo render_filter_multiselect('type', 'Тип', ChurchCare\home_group_types(), $filters['types']);
    echo render_filter_multiselect('time', 'Время встречи', array_combine(ChurchCare\home_group_time_filter_options(), ChurchCare\home_group_time_filter_options()), $filters['times']);
    echo '<label class="filter-time-range">Диапазон времени<div class="time-range"><input name="time_from" type="time" value="' . e((string) ($filters['time_from'] ?? '')) . '"><span>—</span><input name="time_to" type="time" value="' . e((string) ($filters['time_to'] ?? '')) . '"></div><span class="field-hint">Например, с 10:00 до 14:00</span></label>';
    echo '</div>';
    echo '<div class="homegroup-filters__actions"><button class="primary" type="submit">Применить фильтры</button><a class="ghost" href="' . e($catalogPath) . '">Сбросить все</a></div>';
    echo '</form>';

    $groupCount = count($groups);
    $groupCountLabel = $groupCount . ' ' . homegroup_count_label($groupCount);

    echo '<div class="homegroup-view" data-homegroup-view>';
    echo '<div class="homegroup-tabs" role="tablist" aria-label="Режим просмотра групп">';
    echo '<button type="button" role="tab" class="homegroup-tabs__tab is-active" id="homegroup-tab-list" aria-selected="true" aria-controls="homegroup-panel-list" data-homegroup-tab="list">Список групп <span class="homegroup-tabs__badge">' . $groupCount . '</span></button>';
    echo '<button type="button" role="tab" class="homegroup-tabs__tab" id="homegroup-tab-map" aria-selected="false" aria-controls="homegroup-panel-map" data-homegroup-tab="map">Карта</button>';
    echo '</div>';

    echo '<section role="tabpanel" class="panel homegroup-tab-panel" id="homegroup-panel-list" aria-labelledby="homegroup-tab-list" data-homegroup-panel="list">';
    echo '<div class="panel-head"><h2>Список групп</h2><span class="muted">' . e($groupCountLabel) . '</span></div>';
    echo '<div class="homegroup-list" data-homegroup-list>';
    if ($groups) {
        foreach ($groups as $group) {
            echo render_homegroup_list_card($group, $showChurchLabels);
        }
    } else {
        echo '<p class="muted">По выбранным фильтрам групп не найдено. Попробуйте изменить параметры.</p>';
    }
    echo '</div></section>';

    echo '<section role="tabpanel" class="panel homegroup-tab-panel" id="homegroup-panel-map" aria-labelledby="homegroup-tab-map" data-homegroup-panel="map" hidden>';
    echo '<div class="panel-head"><h2>Карта</h2><span class="muted">' . e($groupCountLabel) . '</span></div>';
    echo '<div' . ($mapReady ? ' data-yandex-map-ready="1"' : '') . ' data-homegroup-map data-groups="' . e((string) $groupsJson) . '" data-map-key="' . e($mapKey) . '" data-show-church="' . ($showChurchLabels ? '1' : '0') . '">';
    if ($mapReady) {
        echo '<div id="homegroup-map" class="homegroup-map"></div>';
    } else {
        echo '<p class="muted">Карта недоступна: укажите ключ Yandex Maps API в настройках.</p>';
    }
    echo '</div></section>';
    echo '</div>';

    $loginHref = $scopeChurch ? ('/' . (string) $scopeChurch['slug'] . '/login') : '/login';
    echo '<p class="muted homegroup-footer-note"><a href="' . e($loginHref) . '">Вход для лидеров и пасторов</a></p>';
    echo '</div>';
    public_page_footer();
}

function homegroup_count_label(int $count): string
{
    $mod10 = $count % 10;
    $mod100 = $count % 100;
    if ($mod10 === 1 && $mod100 !== 11) {
        return 'группа';
    }
    if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
        return 'группы';
    }

    return 'групп';
}

/**
 * @param array<string, mixed> $group
 */
function render_homegroup_list_card(array $group, bool $showChurchLabel = false): string
{
    $html = '<article class="homegroup-card" data-group-id="' . (int) $group['id'] . '">';
    if ($showChurchLabel && trim((string) ($group['church_label'] ?? '')) !== '') {
        $html .= '<p class="eyebrow homegroup-card__church">' . e((string) $group['church_label']) . '</p>';
    }
    $html .= '<h3>' . e((string) $group['leader_name']) . '</h3>'
        . '<p class="homegroup-card__meta">' . e((string) $group['types_label']) . '</p>'
        . '<p class="homegroup-card__meta">' . e((string) $group['schedule_label']) . '</p>'
        . '<p class="homegroup-card__address">' . e((string) $group['short_address']) . '</p>'
        . '<a class="primary" href="' . e((string) $group['join_url']) . '">Присоединиться</a>'
        . '</article>';

    return $html;
}

function homegroup_catalog_api(): void
{
    header('Content-Type: application/json; charset=utf-8');
    [$church, $error] = homegroup_catalog_api_church_from_request();
    if ($error !== null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
        return;
    }

    $filters = ChurchCare\parse_home_group_catalog_filters($_GET);
    $churchId = $church ? (int) $church['id'] : null;
    $groups = fetch_public_home_groups($churchId, $filters);
    echo json_encode([
        'ok' => true,
        'scope' => $church ? [
            'church_id' => (int) $church['id'],
            'slug' => (string) ($church['slug'] ?? ''),
            'label' => church_public_label($church),
        ] : ['church_id' => null, 'slug' => null, 'label' => 'all'],
        'groups' => $groups,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * @return array{0: string, 1: string}
 */
function homegroup_join_social_name_parts(array $profile): array
{
    $firstName = trim((string) ($profile['first_name'] ?? ''));
    $lastName = trim((string) ($profile['last_name'] ?? ''));
    if ($firstName === '' && $lastName === '') {
        $name = trim((string) ($profile['profile_name'] ?? ''));
        if ($name !== '') {
            $parts = preg_split('/\s+/', $name, 2);
            $firstName = trim((string) ($parts[0] ?? ''));
            $lastName = trim((string) ($parts[1] ?? ''));
        }
    }

    return [$firstName, $lastName];
}

function homegroup_join_current_url(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/homegroup/join');
    return social_safe_return_to($uri, homegroup_join_base_path());
}

/**
 * Join form base path: /homegroup/join or /{slug}/homegroup/join when tenant-bound.
 */
function homegroup_join_base_path(?array $church = null): string
{
    $church = $church ?? homegroup_catalog_scope_church();
    if ($church) {
        $slug = trim((string) ($church['slug'] ?? ''));
        if ($slug !== '' && church_slug_is_valid($slug)) {
            return '/' . $slug . '/homegroup/join';
        }
    }

    return '/homegroup/join';
}

/**
 * Public join URL for CRM buttons (group cards, empty inbox hints).
 * Prefers the current church slug so pastors stay on their tenant.
 */
function crm_public_group_join_url(int $groupId = 0, ?array $church = null): string
{
    $church = $church ?? (function_exists('current_church') ? current_church() : null);
    $base = homegroup_join_base_path($church);
    if ($groupId > 0) {
        return $base . '?group=' . $groupId;
    }

    return $base;
}

function homegroup_join_selection_url(int $groupId, string $leaderQuery): string
{
    $params = [];
    if ($groupId > 0) {
        $params['group'] = (string) $groupId;
    } elseif ($leaderQuery !== '') {
        $params['leader'] = $leaderQuery;
    }

    $base = homegroup_join_base_path();

    return $base . ($params ? ('?' . http_build_query($params)) : '');
}

function homegroup_join_reset_social_url(int $groupId, string $leaderQuery): string
{
    $params = ['social_reset' => '1'];
    if ($groupId > 0) {
        $params = ['group' => (string) $groupId] + $params;
    } elseif ($leaderQuery !== '') {
        $params = ['leader' => $leaderQuery] + $params;
    }

    return homegroup_join_base_path() . '?' . http_build_query($params);
}

function homegroup_join_social_address(?array $profile): ?string
{
    if (!$profile || ($profile['provider'] ?? '') !== 'yandex' || !is_array($profile['addresses'] ?? null)) {
        return null;
    }

    foreach ($profile['addresses'] as $address) {
        if (!is_array($address)) {
            continue;
        }
        $line = yandex_address_formatted_line($address);
        if ($line !== '') {
            return $line;
        }
    }

    return null;
}

/**
 * Leader option label for the public join form.
 *
 * @param array<string, mixed> $leader
 */
function homegroup_join_leader_option_label(array $leader, bool $includeChurch): string
{
    $label = trim((string) ($leader['leader_name'] ?: $leader['name']));
    if (!$includeChurch) {
        return $label;
    }
    $churchLabel = church_public_label($leader);
    if ($churchLabel !== '' && $churchLabel !== 'Церковь') {
        return $label . ' — ' . $churchLabel;
    }

    return $label;
}

function homegroup_join_page(): void
{
    $scopeChurch = homegroup_catalog_scope_church();
    $scopeChurchId = $scopeChurch ? (int) $scopeChurch['id'] : null;
    $catalogBackPath = public_homegroup_catalog_path($scopeChurch);

    $groupId = (int) ($_GET['group'] ?? $_POST['group_id'] ?? 0);
    $leaderQuery = trim((string) ($_GET['leader'] ?? ''));
    $selectedGroup = null;

    if ($groupId > 0) {
        $selectedGroup = fetch_public_home_group_by_id($groupId, $scopeChurchId);
    } elseif ($leaderQuery !== '') {
        $selectedGroup = fetch_public_home_group_by_leader_name($leaderQuery, $scopeChurchId);
    }

    if (isset($_GET['social_reset'])) {
        social_join_session_clear();
        redirect(homegroup_join_selection_url($selectedGroup ? (int) $selectedGroup['id'] : $groupId, $leaderQuery));
    }

    // Consent and notifications follow the selected/target group's church, then URL tenant.
    $formChurch = null;
    if ($selectedGroup) {
        $formChurch = find_church_by_id((int) ($selectedGroup['church_id'] ?? 0)) ?: [
            'id' => (int) ($selectedGroup['church_id'] ?? 0),
            'name' => (string) ($selectedGroup['church_name'] ?? ''),
            'city' => (string) ($selectedGroup['church_city'] ?? ''),
            'slug' => (string) ($selectedGroup['church_slug'] ?? ''),
            'personal_data_consent_text' => (string) ($selectedGroup['church_consent_text'] ?? ''),
        ];
    } elseif ($scopeChurch) {
        $formChurch = $scopeChurch;
    }

    $leadersChurchId = $formChurch ? (int) $formChurch['id'] : $scopeChurchId;
    $leaders = fetch_public_home_group_leaders($leadersChurchId);
    $showChurchInLeaders = $leadersChurchId === null;
    $consentText = $formChurch ? trim((string) ($formChurch['personal_data_consent_text'] ?? '')) : '';

    // Platform join without a chosen group: pick a group first so consent matches that church.
    $needsGroupPick = $formChurch === null && $_SERVER['REQUEST_METHOD'] !== 'POST';

    $socialJoin = social_join_profile();
    [$socialFirstName, $socialLastName] = $socialJoin ? homegroup_join_social_name_parts($socialJoin) : ['', ''];
    $sent = false;
    $error = null;
    $form = [
        'first_name' => $socialFirstName,
        'last_name' => $socialLastName,
        'phone' => (string) ($socialJoin['phone'] ?? ''),
        'telegram' => '',
        'email' => (string) ($socialJoin['email'] ?? ''),
        'group_id' => $selectedGroup ? (int) $selectedGroup['id'] : 0,
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $form = [
            'first_name' => trim((string) ($_POST['first_name'] ?? '')),
            'last_name' => trim((string) ($_POST['last_name'] ?? '')),
            'phone' => (string) ($_POST['phone'] ?? ''),
            'telegram' => (string) ($_POST['telegram'] ?? ''),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'group_id' => (int) ($_POST['group_id'] ?? 0),
        ];

        $targetGroup = $form['group_id'] > 0
            ? fetch_public_home_group_by_id($form['group_id'], $scopeChurchId)
            : null;
        $targetChurchId = $targetGroup ? (int) ($targetGroup['church_id'] ?? 0) : 0;
        $targetConsent = $targetGroup ? trim((string) ($targetGroup['church_consent_text'] ?? '')) : '';
        if ($targetGroup && $targetConsent === '' && $targetChurchId > 0) {
            $targetChurchRow = find_church_by_id($targetChurchId);
            $targetConsent = $targetChurchRow ? trim((string) ($targetChurchRow['personal_data_consent_text'] ?? '')) : '';
        }

        if (!throttle_action('join:' . ($_SERVER['REMOTE_ADDR'] ?? 'local'), 5, 600)) {
            $error = 'Слишком много попыток. Попробуйте позже.';
        } elseif ($form['first_name'] === '' || $form['last_name'] === '') {
            $error = 'Укажите имя и фамилию.';
        } elseif ($form['group_id'] <= 0 || !$targetGroup) {
            $error = 'Выберите лидера домашней группы.';
        } elseif ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Укажите корректную почту или оставьте поле пустым.';
        } elseif ($targetConsent !== '' && empty($_POST['consent'])) {
            $error = 'Необходимо согласие на обработку персональных данных.';
        } elseif ($targetConsent === '') {
            $error = 'Текст согласия на обработку персональных данных пока не настроен администратором церкви.';
        } else {
            $phone = ChurchCare\normalize_phone($form['phone'] ?: null);
            if ($phone === null) {
                $error = 'Укажите корректный номер телефона.';
            } else {
                $telegram = ChurchCare\normalize_telegram($form['telegram'] ?: null);
                $fullName = trim($form['first_name'] . ' ' . $form['last_name']);
                $sourceProvider = $socialJoin ? (string) $socialJoin['provider'] : 'site';
                $sourceExternalId = $socialJoin ? (string) $socialJoin['external_user_id'] : null;
                $sourceLabel = $socialJoin ? (string) ($socialJoin['provider_label'] ?? $sourceProvider) : '';
                $socialName = $socialJoin ? trim((string) ($socialJoin['profile_name'] ?? '')) : '';
                $message = 'Заявка на присоединение к группе: ' . ($targetGroup['leader_name'] ?: $targetGroup['name']);
                if ($sourceLabel !== '') {
                    $message .= '. Профиль подтвержден через ' . $sourceLabel . ($socialName !== '' ? ': ' . $socialName : '') . '.';
                }
                db()->prepare(
                    'INSERT INTO participant_requests (church_id, full_name, first_name, last_name, email, phone, telegram, address, source_provider, source_external_id, target_group_id, recommended_group_id, consent_given, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $targetChurchId,
                    $fullName,
                    $form['first_name'],
                    $form['last_name'],
                    $form['email'] !== '' ? $form['email'] : null,
                    $phone,
                    $telegram,
                    homegroup_join_social_address($socialJoin),
                    $sourceProvider,
                    $sourceExternalId,
                    (int) $targetGroup['id'],
                    (int) $targetGroup['id'],
                    !empty($_POST['consent']) ? 1 : 0,
                    $message,
                ]);
                $requestId = (int) db()->lastInsertId();
                $admins = fetch_all(
                    'SELECT id, email FROM users WHERE role IN ("admin", "pastor", "super_admin") AND church_id = ? AND email IS NOT NULL',
                    [$targetChurchId]
                );
                $adminBody = $fullName . ' оставил заявку на присоединение к домашней группе '
                    . ($targetGroup['leader_name'] ?: $targetGroup['name']) . '.';
                foreach ($admins as $admin) {
                    queue_email_notification((int) $admin['id'], (string) $admin['email'], 'Новая заявка участника', $adminBody, 'participant_request');
                }
                audit('participant_request_created', 'participant_request', $requestId);
                $sent = true;
                social_join_session_clear();
                $socialJoin = null;
                $form = [
                    'first_name' => '',
                    'last_name' => '',
                    'phone' => '',
                    'telegram' => '',
                    'email' => '',
                    'group_id' => 0,
                ];
                $selectedGroup = null;
                $formChurch = $scopeChurch;
                $consentText = $formChurch ? trim((string) ($formChurch['personal_data_consent_text'] ?? '')) : '';
                $leaders = fetch_public_home_group_leaders($scopeChurchId);
                $showChurchInLeaders = $scopeChurchId === null;
                $needsGroupPick = $formChurch === null;
            }
        }

        if ($error !== null && $targetGroup) {
            $selectedGroup = $targetGroup;
            $formChurch = find_church_by_id($targetChurchId) ?: $formChurch;
            $consentText = $targetConsent;
            $leaders = fetch_public_home_group_leaders($targetChurchId > 0 ? $targetChurchId : $scopeChurchId);
            $showChurchInLeaders = false;
            $needsGroupPick = false;
        }
    }

    public_page_header('Заявка в домашнюю группу');
    echo '<div class="public-shell homegroup-join-shell">';
    echo '<header class="homegroup-hero compact"><h1>Присоединиться к домашней группе</h1>';
    if ($selectedGroup) {
        $groupTitle = (string) ($selectedGroup['leader_name'] ?: $selectedGroup['name']);
        $churchLabel = church_public_label($selectedGroup);
        echo '<p class="muted">Вы выбрали группу: <strong>' . e($groupTitle) . '</strong>';
        if ($churchLabel !== '' && $churchLabel !== 'Церковь') {
            echo ' <span class="homegroup-join-church">(' . e($churchLabel) . ')</span>';
        }
        echo '</p>';
    } elseif ($scopeChurch) {
        echo '<p class="muted">Церковь: <strong>' . e(church_public_label($scopeChurch)) . '</strong></p>';
    }
    echo '</header>';

    if ($needsGroupPick) {
        echo '<form class="login-card homegroup-join-form" method="get" action="' . e(homegroup_join_base_path($scopeChurch)) . '">';
        $flash = flash();
        if ($sent) {
            echo '<div class="flash">Заявка отправлена. Лидер свяжется с вами.</div>';
        }
        if ($flash) {
            echo '<div class="flash">' . e($flash) . '</div>';
        }
        echo '<p class="muted">Сначала выберите группу — текст согласия на обработку данных зависит от церкви.</p>';
        echo '<label>Лидер группы *<select name="group" required><option value="">Выберите лидера</option>';
        foreach ($leaders as $leader) {
            $id = (int) $leader['id'];
            $label = homegroup_join_leader_option_label($leader, true);
            echo '<option value="' . $id . '">' . e($label) . '</option>';
        }
        echo '</select></label>';
        echo '<button class="primary full" type="submit">Продолжить</button>';
        echo '<p class="muted"><a href="' . e($catalogBackPath) . '">← Вернуться к выбору группы</a> · <a href="/login">Уже есть доступ?</a></p>';
        echo '</form></div>';
        public_page_footer();
        return;
    }

    echo '<form class="login-card homegroup-join-form" method="post">';
    $flash = flash();
    if ($sent) {
        echo '<div class="flash">Заявка отправлена. Лидер свяжется с вами.</div>';
    }
    if ($flash) {
        echo '<div class="flash">' . e($flash) . '</div>';
    }
    if ($error) {
        echo '<div class="flash error">' . e($error) . '</div>';
    }
    if ($socialJoin) {
        $socialName = trim((string) ($socialJoin['profile_name'] ?? ''));
        echo '<div class="social-join-status"><strong>Подтверждено через ' . e((string) ($socialJoin['provider_label'] ?? $socialJoin['provider'])) . '</strong><span>' . e($socialName !== '' ? $socialName : (string) $socialJoin['external_user_id']) . '</span><a class="ghost" data-no-ajax href="' . e(homegroup_join_reset_social_url((int) $form['group_id'], $leaderQuery)) . '">Выбрать другой ID</a></div>';
    } else {
        echo render_social_join_choice(homegroup_join_current_url());
    }
    echo csrf_field();
    echo '<div class="manual-join-fields">';
    echo '<div class="form-grid"><label>Имя *<input name="first_name" required autocomplete="given-name" value="' . e($form['first_name']) . '"></label>';
    echo '<label>Фамилия *<input name="last_name" required autocomplete="family-name" value="' . e($form['last_name']) . '"></label></div>';
    echo '<label>Номер телефона *' . phone_input('phone', $form['phone'] !== '' ? $form['phone'] : null) . '</label>';
    echo '<label>Email<input name="email" type="email" autocomplete="email" value="' . e($form['email']) . '"></label>';
    echo '<label>Ник в Telegram' . telegram_input('telegram', $form['telegram'] !== '' ? $form['telegram'] : null) . '</label>';
    if ($socialJoin && ($socialJoin['provider'] ?? '') === 'yandex' && is_array($socialJoin['addresses'] ?? null)) {
        render_yandex_address_picker($socialJoin['addresses'], 'join');
    }
    echo '<label>Лидер группы *<select name="group_id" required><option value="">Выберите лидера</option>';
    foreach ($leaders as $leader) {
        $id = (int) $leader['id'];
        $label = homegroup_join_leader_option_label($leader, $showChurchInLeaders);
        $selected = ($form['group_id'] === $id || ($selectedGroup && (int) $selectedGroup['id'] === $id)) ? ' selected' : '';
        echo '<option value="' . $id . '"' . $selected . '>' . e($label) . '</option>';
    }
    echo '</select></label>';
    if ($consentText !== '') {
        echo '<label class="consent-check"><input type="checkbox" name="consent" value="1" required><span>' . nl2br(e($consentText)) . '</span></label>';
    } else {
        echo '<p class="muted">Текст согласия на обработку персональных данных пока не настроен администратором церкви.</p>';
    }
    echo '<button class="primary full" type="submit"' . ($consentText === '' ? ' disabled' : '') . '>Отправить заявку</button>';
    $loginHref = $scopeChurch ? ('/' . (string) $scopeChurch['slug'] . '/login') : '/login';
    echo '<p class="muted"><a href="' . e($catalogBackPath) . '">← Вернуться к выбору группы</a> · <a href="' . e($loginHref) . '">Уже есть доступ?</a></p>';
    echo '</div>';
    echo '</form></div>';
    public_page_footer();
}
