<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

// Apply pending SQL migrations (no-op when up to date; locked for concurrency).
if (function_exists('migrate_auto_run')) {
    try {
        migrate_auto_run();
    } catch (Throwable) {
        // Schema endpoints / login should still render connection errors themselves.
    }
}

$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$queryString = (string) ($_SERVER['QUERY_STRING'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

[$path, $tenantBound] = resolve_church_tenant_from_request_path($rawPath);
$demoResolved = resolve_demo_tenant_from_request_path($rawPath);
if ($demoResolved !== null) {
    [$path, $tenantBound] = $demoResolved;
}

function church_picker_page(?array $user): void
{
    if ($user && function_exists('is_demo_user') && is_demo_user($user)) {
        redirect('/demo/');
    }
    if ($user && !is_super_admin($user)) {
        $slug = trim((string) ($user['church_slug'] ?? ''));
        if ($slug === '' && (int) ($user['church_id'] ?? 0) > 0) {
            $church = find_church_by_id((int) $user['church_id']);
            $slug = trim((string) ($church['slug'] ?? ''));
        }
        if ($slug !== '') {
            redirect('/' . $slug);
        }
    }

    public_page_header('Выбор церкви');
    echo '<section class="login-card church-picker">';
    echo render_brand_markup(['variant' => 'hero']);
    echo '<p class="muted login-subtitle">Выберите церковь</p>';
    $churches = list_churches_for_picker();
    if ($churches === []) {
        echo '<p class="muted">Церкви ещё не созданы.</p>';
    } else {
        echo '<div class="stack church-picker-list">';
        foreach ($churches as $church) {
            $slug = (string) $church['slug'];
            $city = trim((string) ($church['city'] ?? ''));
            $label = $city !== '' ? $city : (string) $church['name'];
            $href = $user ? '/' . $slug : '/' . $slug . '/login';
            echo '<a class="panel action-card" href="' . e($href) . '"><h2>' . e($label) . '</h2>';
            if ($city !== '' && $city !== (string) $church['name']) {
                echo '<p class="muted">' . e((string) $church['name']) . '</p>';
            } else {
                echo '<p class="muted">' . e($slug) . '</p>';
            }
            echo '</a>';
        }
        echo '</div>';
    }
    if ($user && is_super_admin($user)) {
        echo '<p class="muted"><a href="' . e(platform_path('/admin')) . '">Платформенное администрирование</a></p>';
    }
    echo '<p class="muted"><a href="/homegroup">Карта домашних групп</a></p>';
    echo '</section>';
    public_page_footer();
}

function login_page(): void
{
    $error = null;
    $church = current_church();
    if (!$church) {
        redirect_legacy_crm_to_default_church('/login', (string) ($_SERVER['QUERY_STRING'] ?? ''));
    }

    if (isset($_GET['reset'])) {
        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_started_at']);
    }

    $pendingUserId = (int) ($_SESSION['pending_2fa_user_id'] ?? 0);
    $isTwoFactorStep = $pendingUserId > 0;
    $loginValue = '';
    $brandCity = trim((string) ($church['city'] ?? ''));
    if ($brandCity === '') {
        $brandCity = (string) ($church['name'] ?? '');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!login_csrf_is_valid()) {
            refresh_csrf_token();
            $error = 'Сессия формы входа устарела. Попробуйте войти еще раз.';
        } else {
            $remoteKey = 'login:' . ($_SERVER['REMOTE_ADDR'] ?? 'local');
            if (!throttle_action($remoteKey, 12, 300) || !throttle_action('login-session', 20, 300)) {
                $error = 'Слишком много попыток. Попробуйте через несколько минут';
            } elseif (($_POST['step'] ?? '') === '2fa' && $pendingUserId > 0) {
                $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([$pendingUserId]);
                $user = $stmt->fetch();
                $code = trim((string) ($_POST['two_factor_code'] ?? ''));
                if ($user && two_factor_verify($user['two_factor_secret'] ?? '', $code) && user_can_access_church($user, (int) $church['id'])) {
                    session_regenerate_id(true);
                    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_started_at']);
                    $_SESSION['user_id'] = (int) $user['id'];
                    audit("login", "user", (int) $user["id"]);
                    if (!empty($_SESSION['mcp_oauth_return']) && is_string($_SESSION['mcp_oauth_return'])) {
                        $ret = $_SESSION['mcp_oauth_return'];
                        unset($_SESSION['mcp_oauth_return']);
                        if (function_exists('mcp_oauth_is_safe_return_url') && mcp_oauth_is_safe_return_url($ret)) {
                            header('Location: ' . $ret); exit;
                        }
                    }
                    ajax_redirect("/");
                } else {
                    $error = $user && !user_can_access_church($user, (int) $church['id'])
                        ? 'Этот аккаунт не относится к выбранной церкви.'
                        : 'Введите актуальный 2FA-код';
                    $isTwoFactorStep = true;
                }
            } else {
                unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_started_at']);
                $loginValue = normalize_user_login((string) ($_POST['login'] ?? ''));
                $user = function_exists('find_user_for_church_login')
                    ? find_user_for_church_login($loginValue, (int) $church['id'])
                    : null;
                if (!$user) {
                    $stmt = db()->prepare('SELECT * FROM users WHERE login = ? LIMIT 1');
                    $stmt->execute([$loginValue]);
                    $user = $stmt->fetch() ?: null;
                }
                if ($user && password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
                    if (function_exists('demo_password_login_allowed') && !demo_password_login_allowed($user)) {
                        $error = 'Демо-учётные записи открываются только через /demo/enter.';
                    } elseif (!user_can_access_church($user, (int) $church['id'])) {
                        $error = 'Этот аккаунт не относится к выбранной церкви.';
                    } elseif ((int) ($user['two_factor_enabled'] ?? 0) === 1) {
                        $_SESSION['pending_2fa_user_id'] = (int) $user['id'];
                        $_SESSION['pending_2fa_started_at'] = time();
                        $isTwoFactorStep = true;
                        $loginValue = (string) $user['login'];
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = (int) $user['id'];
                        audit("login", "user", (int) $user["id"]);
                        if (!empty($_SESSION['mcp_oauth_return']) && is_string($_SESSION['mcp_oauth_return'])) {
                            $ret = $_SESSION['mcp_oauth_return'];
                            unset($_SESSION['mcp_oauth_return']);
                            if (function_exists('mcp_oauth_is_safe_return_url') && mcp_oauth_is_safe_return_url($ret)) {
                                header('Location: ' . $ret); exit;
                            }
                        }
                        ajax_redirect("/");
                    }
                } else {
                    $error = 'Неверный логин или пароль';
                }
            }
        }
    }

    public_page_header('Вход');
    echo '<form class="login-card" method="post">' . render_brand_markup(['variant' => 'hero', 'subtitle' => $brandCity !== '' ? $brandCity : null]);
    if ($error) echo '<div class="flash error">' . e($error) . '</div>';
    if ($isTwoFactorStep) {
        echo login_csrf_field() . '<input type="hidden" name="step" value="2fa"><p class="muted">Для этого аккаунта включена двухфакторная проверка. Введите одноразовый код.</p><label>2FA-код<input name="two_factor_code" inputmode="numeric" autocomplete="one-time-code" required autofocus></label><button class="primary full">Подтвердить вход</button>';
        echo '<p class="muted"><a href="' . e(app_path('/login?reset=1')) . '">Войти другим аккаунтом</a></p>';
    } else {
        echo login_csrf_field() . '<label>Логин<input name="login" required autocomplete="username" value="' . e($loginValue) . '"></label><label>Пароль<input name="password" type="password" required></label><button class="primary full">Войти</button>';
        if ($church) {
            echo '<p class="muted"><a href="' . e(public_church_about_path($church)) . '">О церкви</a></p>';
        }
        $joinHref = $church && function_exists('homegroup_join_base_path')
            ? homegroup_join_base_path($church)
            : '/homegroup/join';
        echo '<p class="muted"><a href="' . e(app_path('/forgot-password')) . '">Восстановить пароль</a> · <a href="' . e($joinHref) . '">Заявка в домашнюю группу</a> · <a href="' . e(public_prayer_request_path($church)) . '">Молитвенная нужда</a> · <a href="' . e(public_miracle_share_path($church)) . '">Свидетельство</a></p>';
        echo '<p class="muted"><a href="' . e(platform_path('/')) . '">Выбрать другую церковь</a></p>';
        echo render_social_login_buttons('login');
    }
    echo '</form>';
    public_page_footer();
}
function get_groups(array $user): array
{
    [$sql, $params] = accessible_group_sql($user, 'g');
    return fetch_all("SELECT g.*, c.name AS church_name,
            leader.full_name AS leader_name, helper.full_name AS helper_name,
            COUNT(p.id) AS people_count
        FROM home_groups g
        JOIN churches c ON c.id = g.church_id
        LEFT JOIN people leader ON leader.id = g.leader_person_id
        LEFT JOIN people helper ON helper.id = g.assistant_person_id
        LEFT JOIN people p ON p.group_id = g.id AND p.deleted_at IS NULL
        WHERE $sql AND g.is_active = 1
        GROUP BY g.id
        ORDER BY g.name", $params);
}

function dashboard(array $user): void
{
    redirect_member_to_own_card($user);
    ensure_automations($user);
    page_header('Главная', $user);

    [$groupSql, $groupParams] = accessible_group_sql($user, 'g');
    [$personSql, $personParams] = accessible_person_sql($user);
    // Count via correlated subqueries — joining people and prayer_requests on the same
    // group multiplies SUM(...) by the cartesian product (people × prayers).
    $stats = fetch_one("SELECT
            COUNT(*) AS groups_count,
            COALESCE(SUM((
                SELECT COUNT(*) FROM people p
                WHERE p.group_id = g.id AND p.deleted_at IS NULL
            )), 0) AS people_count,
            COALESCE(SUM((
                SELECT COUNT(*) FROM people p
                WHERE p.group_id = g.id AND p.deleted_at IS NULL
                  AND p.first_visit_date >= CURDATE() - INTERVAL 30 DAY
            )), 0) AS newcomers,
            COALESCE(SUM((
                SELECT COUNT(*) FROM prayer_requests pr
                WHERE pr.group_id = g.id AND pr.status IN ('new','praying')
            )), 0) AS open_prayers
        FROM home_groups g
        WHERE $groupSql AND g.is_active = 1", $groupParams) ?: [];

    $churchId = effective_scope_church_id($user);
    if ($churchId !== null && $churchId > 0) {
        $tasks = fetch_all(
            'SELECT t.*, p.full_name
             FROM tasks t
             LEFT JOIN people p ON p.id = t.person_id
             LEFT JOIN home_groups g_task ON g_task.id = t.group_id
             LEFT JOIN home_groups g_person ON g_person.id = p.group_id
             WHERE t.assigned_to = ?
               AND t.status = "open"
               AND COALESCE(g_task.church_id, g_person.church_id) = ?
             ORDER BY t.due_date, t.id
             LIMIT 8',
            [$user['id'], $churchId]
        );
    } else {
        $tasks = fetch_all(
            'SELECT t.*, p.full_name FROM tasks t LEFT JOIN people p ON p.id = t.person_id WHERE t.assigned_to = ? AND t.status = "open" ORDER BY t.due_date, t.id LIMIT 8',
            [$user['id']]
        );
    }
    $meetings = fetch_all("SELECT m.*, g.name AS group_name FROM meetings m JOIN home_groups g ON g.id = m.group_id WHERE $groupSql AND g.is_active = 1 AND m.meeting_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY ORDER BY m.meeting_date", $groupParams);
    $newPeople = fetch_all("SELECT p.*, g.name AS group_name FROM people p JOIN home_groups g ON g.id = p.group_id WHERE $personSql AND g.is_active = 1 AND p.first_visit_date >= CURDATE() - INTERVAL 30 DAY ORDER BY p.first_visit_date DESC", $personParams);
    $radar = array_slice(array_filter(care_people($user), static fn($p) => $p['care_level'] !== 'green'), 0, 8);

    echo '<section class="metric-grid">';
    foreach ([['Домашних групп', $stats['groups_count'] ?? 0], ['Людей', $stats['people_count'] ?? 0], ['Новых за месяц', $stats['newcomers'] ?? 0], ['Открытых нужд', $stats['open_prayers'] ?? 0]] as [$label, $value]) {
        echo '<article class="metric"><span>' . e((string) $value) . '</span><p>' . e($label) . '</p></article>';
    }
    echo '</section>';

    echo '<section class="grid two"><article class="panel"><div class="panel-head"><h2>Радар заботы</h2><a href="' . e(app_path('/stats')) . '">Вся аналитика</a></div>';
    if (!$radar) echo '<p class="muted">Сейчас нет явных рисков.</p>';
    foreach ($radar as $person) render_person_row($person);
    echo '</article><article class="panel"><div class="panel-head"><h2>Задачи лидера</h2><span>' . count($tasks) . '</span></div>';
    foreach ($tasks as $task) render_task($task);
    if (!$tasks) echo '<p class="muted">Открытых задач нет.</p>';
    echo '</article></section>';

    echo '<section class="grid two"><article class="panel"><h2>Встречи этой недели</h2>';
    foreach ($meetings as $meeting) {
        echo '<a class="list-row" href="' . e(app_path('/meeting?id=' . (int) $meeting['id'])) . '"><strong>' . e($meeting['group_name']) . '</strong><span>' . e($meeting['meeting_date']) . ' · ' . e($meeting['topic']) . '</span></a>';
    }
    if (!$meetings) echo '<p class="muted">На ближайшие 7 дней встреч не найдено.</p>';
    echo '</article><article class="panel"><h2>Новые люди</h2>';
    foreach ($newPeople as $person) {
        echo '<a class="list-row" href="' . e(app_path('/person?id=' . (int) $person['id'])) . '"><strong>' . e($person['full_name']) . '</strong><span>' . e($person['group_name']) . ' · первое посещение ' . e($person['first_visit_date']) . '</span></a>';
    }
    if (!$newPeople) echo '<p class="muted">За месяц новых людей нет.</p>';
    echo '</article></section>';

    page_footer();
}

function render_person_row(array $person): void
{
    echo '<div class="care-row"><span class="dot ' . e($person['care_level']) . '"></span><div><a href="' . e(app_path('/person?id=' . (int) $person['id'])) . '"><strong>' . e($person['full_name']) . '</strong></a><p>' . e($person['care_label']) . ' · ' . e($person['group_name'] ?? '') . '</p></div><div class="row-actions">';
    $phoneHref = phone_href($person['phone'] ?? null);
    $telegramHref = telegram_href($person['telegram'] ?? null);
    if ($phoneHref !== '') echo '<a class="icon-btn" href="tel:' . e($phoneHref) . '" title="Позвонить">☎</a>';
    if ($telegramHref !== '') echo '<a class="icon-btn" href="' . e($telegramHref) . '" title="Telegram" aria-label="Telegram">' . telegram_icon_svg() . '</a>';
    echo '</div></div>';
}

function render_task(array $task): void
{
    echo '<form class="task-row" method="post" action="' . e(app_path('/task-done')) . '">' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $task['id'] . '"><div><strong>' . e($task['title']) . '</strong><p>Срок: ' . e($task['due_date']) . ($task['full_name'] ? ' · ' . e($task['full_name']) : '') . '</p></div><button class="ghost">Готово</button></form>';
}

function redirect_member_to_own_card(array $user): void
{
    if ($user['role'] !== 'member') {
        return;
    }

    $person = fetch_one('SELECT id FROM people WHERE user_id = ? AND deleted_at IS NULL LIMIT 1', [$user['id']]);
    if ($person) {
        redirect('/person?id=' . (int) $person['id']);
    }

    if (is_role_preview_active()) {
        role_preview_member_empty_page($user);
        exit;
    }

    http_response_code(403);
    exit('Карточка участника не найдена');
}

function groups_page(array $user): void
{
    redirect_member_to_own_card($user);
    page_header('Домашние группы', $user);
    if (can_create_home_group($user)) {
        echo '<details class="panel collapsible-panel group-create-panel"><summary><div><h2>Новая домашняя группа</h2><span class="muted">Доступно окружному лидеру, пастору и администратору</span></div><span class="collapse-indicator" aria-hidden="true"></span></summary><form method="post" action="' . e(app_path('/group-create')) . '" class="stack">' . csrf_field() . '<div class="form-grid"><label>Название<input name="name" required></label><label>Возраст<select name="age_category"><option value="">Любой</option>';
        foreach (ChurchCare\home_group_age_categories() as $value => $label) echo '<option value="' . e($value) . '">' . e($label) . '</option>';
        echo '</select></label><label>День недели<select name="meeting_weekday"><option value="">Любой</option>';
        foreach (ChurchCare\home_group_weekdays() as $value => $label) echo '<option value="' . (int) $value . '">' . e($label) . '</option>';
        echo '</select></label></div><div class="form-grid"><label>Короткий адрес<input name="short_address" placeholder="Метро / Город"></label><label>Вместимость<input name="max_people" type="number" min="1" value="16"></label></div><div class="form-grid"><label>Тип<select name="group_types[]" multiple size="7">';
        foreach (ChurchCare\home_group_types() as $value => $label) echo '<option value="' . e($value) . '">' . e($label) . '</option>';
        echo '</select></label><label>С <input name="meeting_time_start" type="time"></label><label>До <input name="meeting_time_end" type="time"></label></div><label>Адрес' . address_field_markup('address') . '</label><div class="quick-actions"><button class="primary">Создать группу</button><a class="ghost" href="' . e(public_homegroup_catalog_path(current_church())) . '" target="_blank" rel="noopener">Открыть публичную страницу</a></div></form></details>';
    }
    echo '<section class="grid three">';
    foreach (get_groups($user) as $group) {
        $deleteForm = '';
        if (can_delete_home_group($user)) {
            $deleteForm = '<form method="post" action="' . e(app_path('/group-delete')) . '" class="inline-form">' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $group['id'] . '"><button class="ghost danger" data-confirm="Удалить домашнюю группу ' . e($group['name']) . '? Группа будет закрыта и скрыта из активных списков.">Удалить</button></form>';
        }
        $cardMeta = ChurchCare\home_group_schedule_label($group) ?: ChurchCare\home_group_short_address($group);
        $joinHref = function_exists('crm_public_group_join_url')
            ? crm_public_group_join_url((int) $group['id'])
            : ('/homegroup/join?group=' . (int) $group['id']);
        echo '<article class="panel group-card"><p class="eyebrow">' . e(ChurchCare\home_group_age_label($group['age_category'] ?? null) ?: ($group['church_name'] ?? '')) . '</p><h2>' . e($group['name']) . '</h2><p>' . e($cardMeta) . '</p><div class="capacity"><span style="width:' . min(100, (int) $group['people_count'] / max(1, (int) $group['max_people']) * 100) . '%"></span></div><p>' . (int) $group['people_count'] . ' из ' . (int) $group['max_people'] . ' человек</p><p class="muted">Лидер: ' . e($group['leader_name'] ?: 'не назначен') . '</p><div class="quick-actions"><a class="primary" href="' . e(app_path('/group?id=' . (int) $group['id'])) . '">Открыть</a><a class="ghost" href="' . e($joinHref) . '" target="_blank" rel="noopener">Публичная заявка</a>' . $deleteForm . '</div></article>';
    }
    echo '</section>';
    page_footer();
}

function group_page(array $user): void
{
    redirect_member_to_own_card($user);
    $id = (int) ($_GET['id'] ?? 0);
    [$sql, $params] = accessible_group_sql($user, 'g');
    $params[] = $id;
    $group = fetch_one("SELECT g.*, c.name AS church_name, leader.full_name AS leader_name FROM home_groups g JOIN churches c ON c.id = g.church_id LEFT JOIN people leader ON leader.id = g.leader_person_id WHERE $sql AND g.is_active = 1 AND g.id = ?", $params);
    if (!$group) { http_response_code(404); exit('Group not found'); }

    page_header($group['name'], $user);
    $deleteForm = '';
    if (can_delete_home_group($user)) {
        $deleteForm = '<form method="post" action="' . e(app_path('/group-delete')) . '" class="inline-form">' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $group['id'] . '"><button class="ghost danger" data-confirm="Удалить домашнюю группу ' . e($group['name']) . '? Группа будет закрыта и скрыта из активных списков.">Удалить</button></form>';
    }
    echo '<section class="grid two"><article class="panel"><div class="panel-head"><div><p class="eyebrow">' . e($group['church_name']) . '</p><h2>Участники</h2></div>' . $deleteForm . '</div>';
    foreach (care_people($user, $id) as $person) render_person_row($person);
    echo '</article><article class="panel"><h2>Настройки группы</h2><form method="post" action="' . e(app_path('/group-update')) . '" class="stack">' . csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><label>Название<input name="name" required value="' . e($group['name']) . '"></label><div class="form-grid"><label>Возраст<select name="age_category"><option value="">Любой</option>';
    foreach (ChurchCare\home_group_age_categories() as $value => $label) echo '<option value="' . e($value) . '"' . (($group['age_category'] ?? '') === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    echo '</select></label><label>День недели<select name="meeting_weekday"><option value="">Любой</option>';
    foreach (ChurchCare\home_group_weekdays() as $value => $label) echo '<option value="' . (int) $value . '"' . ((int) ($group['meeting_weekday'] ?? 0) === (int) $value ? ' selected' : '') . '>' . e($label) . '</option>';
    echo '</select></label><label>Вместимость<input name="max_people" type="number" min="1" value="' . (int) $group['max_people'] . '"></label></div><div class="form-grid">';
    echo render_filter_multiselect('group_types', 'Тип', ChurchCare\home_group_types(), ChurchCare\home_group_types_from_value($group['group_types'] ?? null));
    echo '<label>С <input name="meeting_time_start" type="time" value="' . e(ChurchCare\home_group_time_hm($group['meeting_time_start'] ?? null)) . '"></label><label>До <input name="meeting_time_end" type="time" value="' . e(ChurchCare\home_group_time_hm($group['meeting_time_end'] ?? null)) . '"></label></div><div class="form-grid"><label>Короткий адрес<input name="short_address" value="' . e($group['short_address']) . '" placeholder="Метро / Город"></label><label>Лидер<select name="leader_person_id"><option value="">Не назначен</option>';
    foreach (care_people($user, $id, 'attendance') as $person) echo '<option value="' . (int) $person['id'] . '"' . ((int) ($group['leader_person_id'] ?? 0) === (int) $person['id'] ? ' selected' : '') . '>' . e($person['full_name']) . '</option>';
    echo '</select></label>';
    if (function_exists('districts_for_church') && function_exists('can_assign_home_group_leader') && can_assign_home_group_leader($user)) {
        $churchDistricts = districts_for_church((int) $group['church_id'], true);
        echo '<label>Округ<select name="district_id"><option value="">Авто по координатам</option>';
        foreach ($churchDistricts as $district) {
            $did = (int) $district['id'];
            echo '<option value="' . $did . '"' . ((int) ($group['district_id'] ?? 0) === $did ? ' selected' : '') . '>' . e((string) $district['name']) . '</option>';
        }
        echo '</select></label>';
    }
    echo '</div><label>Адрес' . address_field_markup('address', $group['address']) . '</label><button class="primary">Сохранить группу</button></form>';
    if (!(function_exists('is_demo_session') && is_demo_session())) {
        $joinHref = function_exists('crm_public_group_join_url')
            ? crm_public_group_join_url($id)
            : ('/homegroup/join?group=' . $id);
        echo '<div class="quick-actions"><a class="ghost" href="' . e($joinHref) . '" target="_blank" rel="noopener">Открыть публичную заявку</a><a class="ghost" href="' . e(public_homegroup_catalog_path(current_church())) . '" target="_blank" rel="noopener">Публичная карта</a></div>';
    }
    echo '<h2>Новый человек</h2><form method="post" action="' . e(app_path('/person-create')) . '" class="stack">' . csrf_field() . '<input type="hidden" name="group_id" value="' . $id . '"><label>Имя и фамилия<input name="full_name" required></label><label>Email<input name="email" type="email"></label><label>Телефон' . phone_input('phone') . '</label><label>Telegram' . telegram_input('telegram') . '</label><label>Первое посещение<input name="first_visit_date" type="date" value="' . e(date('Y-m-d')) . '"></label><button class="primary">Добавить человека</button></form><h2>Новая встреча</h2><form method="post" action="' . e(app_path('/meeting-create')) . '" class="stack">' . csrf_field() . '<input type="hidden" name="group_id" value="' . $id . '"><label>Дата<input name="meeting_date" type="date" value="' . e(date('Y-m-d')) . '" required></label><label>Тема<input name="topic" placeholder="Тема встречи"></label><button class="primary">Создать встречу</button></form>';
    echo '<h2>Последние встречи</h2>';
    $meetings = fetch_all('SELECT * FROM meetings WHERE group_id = ? ORDER BY meeting_date DESC LIMIT 8', [$id]);
    foreach ($meetings as $meeting) echo '<a class="list-row" href="' . e(app_path('/meeting?id=' . (int) $meeting['id'])) . '"><strong>' . e($meeting['meeting_date']) . '</strong><span>' . e($meeting['topic']) . '</span></a>';
    echo '</article></section>';
    page_footer();
}

function meeting_page(array $user): void
{
    redirect_member_to_own_card($user);
    $id = (int) ($_GET['id'] ?? 0);
    [$groupSql, $params] = accessible_group_sql($user, 'g');
    $params[] = $id;
    $meeting = fetch_one("SELECT m.*, g.name AS group_name FROM meetings m JOIN home_groups g ON g.id = m.group_id WHERE $groupSql AND m.id = ?", $params);
    if (!$meeting) { http_response_code(404); exit('Meeting not found'); }
    $people = care_people($user, (int) $meeting['group_id'], 'attendance');
    $present = fetch_all('SELECT person_id, status FROM attendance WHERE meeting_id = ?', [$id]);
    $presentMap = array_column($present, 'status', 'person_id');

    page_header('Встреча: ' . $meeting['group_name'], $user);
    echo '<form method="post" action="' . e(app_path('/meeting-save')) . '" class="panel stack">' . csrf_field() . '<input type="hidden" name="meeting_id" value="' . $id . '"><div class="panel-head"><div><p class="eyebrow">' . e($meeting['meeting_date']) . '</p><h2>' . e($meeting['topic'] ?: 'Без темы') . '</h2></div><button type="button" class="ghost" data-toggle-all="#attendance-list">Отметить всех</button></div>';
    echo '<div id="attendance-list" class="check-list">';
    foreach ($people as $person) {
        $checked = (($presentMap[$person['id']] ?? '') !== 'absent' && isset($presentMap[$person['id']])) ? ' checked' : '';
        echo '<label><input type="checkbox" name="present[]" value="' . (int) $person['id'] . '"' . $checked . '><span>' . e($person['full_name']) . '</span><small>' . e($person['care_label']) . '</small></label>';
    }
    echo '</div><div class="form-grid"><label>Атмосфера<select name="atmosphere"><option value="">Не выбрано</option><option value="excellent">Отлично</option><option value="good">Хорошо</option><option value="normal">Обычно</option><option value="hard">Тяжело</option></select></label><label>Конфликты<select name="had_conflicts"><option value="0">Нет</option><option value="1">Да</option></select></label><label>Нужна помощь пастора<select name="pastor_help_needed"><option value="0">Нет</option><option value="1">Да</option></select></label></div><label>Заметки<textarea name="notes" rows="3">' . e($meeting['notes']) . '</textarea></label><div class="quick-actions"><button class="primary">Сохранить</button><a class="ghost" href="' . e(app_path('/meeting-report?id=' . $id)) . '">Отчет после встречи</a></div></form>';
    page_footer();
}

function person_page(array $user): void
{
    $id = (int) ($_GET['id'] ?? 0);
    [$personSql, $params] = accessible_person_sql($user, 'p');
    $params[] = $id;
    $person = fetch_one(
        "SELECT p.*, g.name AS group_name, c.name AS church_name,
                u.id AS linked_user_id, u.login AS user_login, u.email AS user_email, u.role AS user_role,
                u.name AS user_name, u.two_factor_enabled AS user_two_factor_enabled,
                ss.name AS spiritual_stage_name
         FROM people p
         JOIN home_groups g ON g.id = p.group_id
         JOIN churches c ON c.id = g.church_id
         LEFT JOIN users u ON u.id = p.user_id
         LEFT JOIN spiritual_stages ss ON ss.id = p.spiritual_stage_id
         WHERE $personSql AND p.id = ?",
        $params
    );
    if (!$person) { http_response_code(404); exit('Person not found'); }

    $meetingJoinSql = person_group_memberships_ready()
        ? 'LEFT JOIN meetings m ON (
                m.group_id = p.group_id
                OR EXISTS (
                    SELECT 1 FROM person_group_memberships pgm
                    WHERE pgm.person_id = p.id AND pgm.group_id = m.group_id
                )
           )'
        : 'LEFT JOIN meetings m ON m.group_id = p.group_id';
    $careMeta = fetch_one(
        "SELECT MAX(CASE WHEN a.status IN (\"present\",\"new\") THEN m.meeting_date END) AS last_attendance,
                MAX(cl.contact_date) AS last_contact
         FROM people p
         $meetingJoinSql
         LEFT JOIN attendance a ON a.meeting_id = m.id AND a.person_id = p.id
         LEFT JOIN contact_logs cl ON cl.person_id = p.id
         WHERE p.id = ?
         GROUP BY p.id",
        [$id]
    ) ?: [];
    $firstVisit = isset($person['first_visit_date']) ? (string) $person['first_visit_date'] : null;
    $lastAttendance = ChurchCare\effective_last_attendance(
        isset($careMeta['last_attendance']) ? (string) $careMeta['last_attendance'] : null,
        $firstVisit
    );
    $lastContact = ChurchCare\effective_last_contact(
        isset($careMeta['last_contact']) ? (string) $careMeta['last_contact'] : null,
        $firstVisit
    );
    [$careLevel, $careLabel] = care_status($lastAttendance, $lastContact);

    $contacts = fetch_all('SELECT cl.*, u.name AS user_name FROM contact_logs cl LEFT JOIN users u ON u.id = cl.user_id WHERE cl.person_id = ? ORDER BY cl.contact_date DESC, cl.id DESC', [$id]);
    $prayers = fetch_all(
        'SELECT pr.*, m.id AS miracle_id, m.title AS miracle_title, m.body AS miracle_body, m.is_anonymous AS miracle_anonymous
         FROM prayer_requests pr
         LEFT JOIN miracles m ON m.prayer_request_id = pr.id
         WHERE pr.person_id = ?
         ORDER BY pr.created_at DESC',
        [$id]
    );
    $attendance = fetch_all('SELECT m.meeting_date, m.topic, a.status FROM attendance a JOIN meetings m ON m.id = a.meeting_id WHERE a.person_id = ? ORDER BY m.meeting_date DESC LIMIT 12', [$id]);
    $canEdit = can_edit_group_data($user);
    $stages = fetch_all('SELECT * FROM spiritual_stages WHERE church_id = ? ORDER BY sort_order, name', [$user['church_id']]);
    $stageNames = [];
    foreach ($stages as $stage) {
        $stageNames[(int) $stage['id']] = (string) $stage['name'];
    }
    $changes = $user['role'] === 'member'
        ? []
        : fetch_all('SELECT pcl.*, u.name AS user_name FROM person_change_logs pcl LEFT JOIN users u ON u.id = pcl.user_id WHERE pcl.person_id = ? ORDER BY pcl.created_at DESC LIMIT 20', [$id]);
    $canViewSensitive = can_view_person_field($user, 'sensitive_notes');
    $canEditSensitive = can_edit_person_field($user, 'sensitive_notes');
    $sensitiveNote = $canViewSensitive ? decrypt_sensitive_note($person['sensitive_notes_encrypted'] ?? null) : '';
    $registered = (int) ($person['user_id'] ?? 0) > 0;

    page_header($person['full_name'], $user);
    $phoneHref = phone_href($person['phone'] ?? null);
    $telegramHref = telegram_href($person['telegram'] ?? null);

    echo '<section class="person-hero panel"><div class="person-hero-main"><span class="dot ' . e($careLevel) . '" title="' . e($careLabel) . '"></span><div>';
    echo '<p class="eyebrow">' . e(($person['church_name'] ?? '') . ' · ' . $person['group_name']) . '</p>';
    echo '<h2>' . e($person['full_name']) . '</h2>';
    echo '<p class="muted">' . e($careLabel);
    if ($person['spiritual_stage_name']) {
        echo ' · ' . e($person['spiritual_stage_name']);
    }
    if ($person['training_stage']) {
        echo ' · ' . e($person['training_stage']);
    }
    echo '</p></div></div><div class="person-hero-actions">';
    if ($registered) {
        echo '<span class="status-badge registered">Есть учётка</span>';
    } else {
        echo '<span class="status-badge unregistered">Нет учётки</span>';
    }
    if ($phoneHref !== '') {
        echo '<a class="primary" href="tel:' . e($phoneHref) . '">Позвонить</a>';
    }
    if ($telegramHref !== '') {
        echo '<a class="primary" href="' . e($telegramHref) . '">Telegram</a>';
    }
    if (can_manage_users($user)) {
        echo '<a class="ghost" href="' . e(app_path('/users')) . '">Люди</a>';
    }
    echo '</div></section>';

    echo '<section class="grid two"><article class="panel"><h2>Контакты</h2><dl class="details">';
    render_person_detail_field('Телефон', 'phone', $person['phone'] ?? null, $canEdit, $id);
    render_person_detail_field('Telegram', 'telegram', $person['telegram'] ?? null, $canEdit, $id);
    render_person_detail_field('Email', 'email', $person['email'] ?? null, $canEdit, $id);
    render_person_detail_field('Адрес', 'address', $person['address'] ?? null, $canEdit, $id);
    $yandexAddresses = social_yandex_addresses_for_user((int) ($person['user_id'] ?? 0));
    if ($canEdit && $yandexAddresses !== []) {
        render_yandex_address_picker($yandexAddresses, 'person');
    }
    echo '</dl></article>';

    echo '<article class="panel"><h2>Путь и рост</h2>';
    if ($canEdit) {
        echo '<form method="post" action="' . e(app_path('/person-update')) . '" class="stack person-edit-form">' . csrf_field() . '<input type="hidden" name="id" value="' . $id . '">';
        echo '<div class="form-grid">';
        echo '<label>Дата рождения<input name="birth_date" type="date" value="' . e((string) ($person['birth_date'] ?? '')) . '"></label>';
        echo '<label>Семейное положение' . marital_status_select_markup('marital_status', $person['marital_status'] ?? null) . '</label>';
        echo '<label>Первое посещение<input name="first_visit_date" type="date" value="' . e((string) ($person['first_visit_date'] ?? '')) . '"></label>';
        echo '<label>Покаяние<input name="repentance_date" type="date" value="' . e((string) ($person['repentance_date'] ?? '')) . '"></label>';
        echo '<label>Крещение<input name="baptism_date" type="date" value="' . e((string) ($person['baptism_date'] ?? '')) . '"></label>';
        echo '<label>Членство<input name="membership_date" type="date" value="' . e((string) ($person['membership_date'] ?? '')) . '"></label>';
        echo '<label>Служение<input name="ministry" value="' . e((string) ($person['ministry'] ?? '')) . '"></label>';
        echo '<label>Духовная воронка<select name="spiritual_stage_id"><option value="">—</option>';
        foreach ($stages as $stage) {
            echo '<option value="' . (int) $stage['id'] . '"' . ((int) ($person['spiritual_stage_id'] ?? 0) === (int) $stage['id'] ? ' selected' : '') . '>' . e($stage['name']) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Этап обучения<input name="training_stage" value="' . e($person['training_stage'] ?? '') . '"></label>';
        echo '</div>';
        echo '<label class="checkbox-row"><input type="checkbox" name="is_potential_leader" value="1"' . ((int) $person['is_potential_leader'] ? ' checked' : '') . '> Потенциальный лидер</label>';
        echo '<label>Заметки<textarea name="notes" rows="3">' . e($person['notes'] ?? '') . '</textarea></label>';
        if ($canViewSensitive) {
            $sensitiveAttrs = $canEditSensitive ? '' : ' readonly';
            echo '<label>Особые заметки<textarea name="sensitive_notes" rows="3"' . $sensitiveAttrs . '>' . e($sensitiveNote) . '</textarea></label>';
            if (!$canEditSensitive) {
                echo '<p class="muted">Особые заметки доступны только для просмотра.</p>';
            }
        }
        echo '<button class="primary">Сохранить</button></form>';
    } else {
        echo '<dl class="details">';
        render_person_static_field('Дата рождения', $person['birth_date'] ?? null);
        render_person_static_field('Семейное положение', $person['marital_status'] ?? null);
        render_person_static_field('Первое посещение', $person['first_visit_date'] ?? null);
        render_person_static_field('Покаяние', $person['repentance_date'] ?? null);
        render_person_static_field('Крещение', $person['baptism_date'] ?? null);
        render_person_static_field('Членство', $person['membership_date'] ?? null);
        render_person_static_field('Служение', $person['ministry'] ?? null);
        render_person_static_field('Духовная воронка', $person['spiritual_stage_name'] ?? null);
        render_person_static_field('Этап обучения', $person['training_stage'] ?? null);
        if ((int) $person['is_potential_leader']) {
            render_person_static_field('Потенциальный лидер', 'да');
        }
        echo '</dl>';
        if (trim((string) ($person['notes'] ?? '')) !== '') {
            echo '<div class="person-notes-readonly"><h3>Заметки</h3><p>' . e($person['notes']) . '</p></div>';
        }
    }
    echo '</article></section>';

    $memberships = person_membership_rows($id);
    [$groupSql, $groupParams] = accessible_group_sql($user, 'g');
    $availableGroups = fetch_all("SELECT g.id, g.name FROM home_groups g WHERE $groupSql AND g.is_active = 1 ORDER BY g.name", $groupParams);
    $memberGroupIds = array_map(static fn(array $row): int => (int) $row['group_id'], $memberships);
    echo '<section class="panel person-groups-panel"><div class="panel-head"><div><h2>Домашние группы</h2><p class="muted">Человек может одновременно ходить в несколько групп. Основная группа используется для отчётов и карточки.</p></div></div>';
    if ($memberships === []) {
        echo '<p class="muted">Группы пока не назначены.</p>';
    } else {
        echo '<div class="stack">';
        foreach ($memberships as $membership) {
            $membershipGroupId = (int) $membership['group_id'];
            echo '<div class="list-row"><div><strong>' . e((string) $membership['group_name']) . '</strong>';
            if ((int) ($membership['is_primary'] ?? 0) === 1) {
                echo '<span class="status-badge registered">Основная</span>';
            }
            echo '</div><div class="user-row-actions">';
            if ($canEdit && (int) ($membership['is_primary'] ?? 0) !== 1) {
                echo '<form method="post" action="' . e(app_path('/person-group-primary')) . '" class="compact">' . csrf_field() . '<input type="hidden" name="person_id" value="' . $id . '"><input type="hidden" name="group_id" value="' . $membershipGroupId . '"><button class="ghost">Сделать основной</button></form>';
            }
            if ($canEdit && count($memberships) > 1) {
                echo '<form method="post" action="' . e(app_path('/person-group-remove')) . '" class="compact">' . csrf_field() . '<input type="hidden" name="person_id" value="' . $id . '"><input type="hidden" name="group_id" value="' . $membershipGroupId . '"><button class="ghost" data-confirm="Убрать человека из группы ' . e((string) $membership['group_name']) . '?">Убрать</button></form>';
            }
            echo '</div></div>';
        }
        echo '</div>';
    }
    if ($canEdit) {
        $addable = array_values(array_filter($availableGroups, static fn(array $group): bool => !in_array((int) $group['id'], $memberGroupIds, true)));
        if ($addable !== []) {
            echo '<form method="post" action="' . e(app_path('/person-group-add')) . '" class="stack compact person-group-add">' . csrf_field() . '<input type="hidden" name="person_id" value="' . $id . '">';
            echo '<label>Добавить в группу<select name="group_id" required><option value="">Выберите группу</option>';
            foreach ($addable as $group) {
                echo '<option value="' . (int) $group['id'] . '">' . e((string) $group['name']) . '</option>';
            }
            echo '</select></label><button class="primary">Добавить</button></form>';
        }
    }
    echo '</section>';

    echo '<section class="grid two person-care-section"><article class="panel"><h2>Общение</h2>';
    if ($canEdit) {
        echo '<form method="post" action="' . e(app_path('/contact-add')) . '" class="stack">' . csrf_field() . '<input type="hidden" name="person_id" value="' . $id . '"><label>Тип контакта<select name="type"><option value="call">Звонок</option><option value="telegram">Telegram</option><option value="meeting">Встреча</option><option value="note">Заметка</option><option value="visit">Визит</option></select></label><label>Итог<input name="summary" required placeholder="Созвонились, договорились..."></label><label>Причина отсутствия<input name="absence_reason"></label><label>Следующий контакт<input name="next_contact_at" type="date"></label><button class="primary">Записать контакт</button></form>';
    }
    echo '<div class="person-care-history"><h3>История общения</h3>';
    foreach ($contacts as $contact) {
        echo '<div class="timeline"><strong>' . e($contact['contact_date']) . ' · ' . e($contact['user_name'] ?: '') . '</strong><p>' . e($contact['summary']) . '</p></div>';
    }
    if (!$contacts) {
        echo '<p class="muted">Истории общения пока нет.</p>';
    }
    echo '</div></article>';

    echo '<article class="panel"><h2>Молитвенные нужды</h2>';
    $canManagePrayers = can_action($user, 'manage_prayers');
    foreach ($prayers as $prayer) {
        $miracleId = (int) ($prayer['miracle_id'] ?? 0);
        echo '<div class="timeline"><strong>' . e($prayer['title']) . '</strong>';
        echo '<p>' . e(ChurchCare\prayer_status_label($prayer['status'] ?? null)) . ' · ' . e($prayer['description']) . '</p>';
        if ($miracleId > 0) {
            echo '<p class="miracle-inline"><a href="' . e(app_path('/miracle?id=' . $miracleId)) . '">' . e((string) ($prayer['miracle_title'] ?: 'Свидетельство')) . '</a>';
            if (!empty($prayer['miracle_body'])) {
                echo ' — ' . e(ChurchCare\miracle_excerpt((string) $prayer['miracle_body'], 140));
            }
            echo '</p>';
        } elseif ($canManagePrayers && in_array((string) ($prayer['status'] ?? ''), ['new', 'praying', 'testimony'], true)) {
            echo '<details class="prayer-testimony-details"><summary>Рассказать свидетельство (чудо)</summary>';
            echo '<form method="post" action="' . e(app_path('/miracle-from-prayer')) . '" class="stack compact">' . csrf_field();
            echo '<input type="hidden" name="prayer_id" value="' . (int) $prayer['id'] . '">';
            echo '<label>Заголовок<input name="title" maxlength="190" value="' . e(ChurchCare\miracle_default_title_from_prayer((string) $prayer['title'])) . '"></label>';
            echo '<label>Что сделал Господь<textarea name="miracle_body" rows="4" required placeholder="Как Господь ответил на эту нужду?"></textarea></label>';
            echo '<label class="checkbox-row"><input type="checkbox" name="miracle_anonymous" value="1"> Опубликовать анонимно</label>';
            echo '<button class="primary" type="submit">Опубликовать в Чудесах</button></form></details>';
        }
        echo '</div>';
    }
    if ($canEdit) {
        echo '<form method="post" action="' . e(app_path('/prayer-add')) . '" class="stack compact">' . csrf_field() . '<input type="hidden" name="person_id" value="' . $id . '"><input type="hidden" name="group_id" value="' . (int) $person['group_id'] . '"><label>Нужда<input name="title" required></label><label>Описание<textarea name="description"></textarea></label><button class="primary">Добавить</button></form>';
    }
    if (!$prayers && !$canEdit) {
        echo '<p class="muted">Молитвенных нужд пока нет.</p>';
    }
    echo '</article></section>';

    echo '<section class="grid two"><article class="panel"><h2>Посещаемость</h2>';
    foreach ($attendance as $row) {
        echo '<div class="list-row"><strong>' . e($row['meeting_date']) . '</strong><span>' . e($row['topic']) . ' · ' . e($row['status']) . '</span></div>';
    }
    if (!$attendance) {
        echo '<p class="muted">Посещений пока нет.</p>';
    }
    echo '</article>';

    echo '<article class="panel person-account-panel"><h2>Учётная запись</h2>';
    if ($registered) {
        render_person_account_summary_fields($person, $user);
        echo '<p class="muted">Карточка хранит заботу и историю; учётка даёт вход в CRM.</p>';
    } else {
        $canInvite = can_invite_person_account($user);
        if ($canInvite) {
            $activeInvite = active_account_invite_for_person($id);
            $inviteUrl = $activeInvite['url'] ?? null;
            $invitePath = trim((string) ($_GET['invite'] ?? ''));
            if ($inviteUrl === null && $invitePath !== '' && str_contains($invitePath, '/account-invite?token=')) {
                $inviteUrl = public_url($invitePath);
            }
            echo '<div class="stack compact account-invite">';
            echo '<p class="muted">Ссылка на создание учётки к карточке. После регистрации история сохранится.</p>';
            if ($inviteUrl) {
                echo '<label>Ссылка<span class="copy-field"><input readonly data-copy-source onclick="this.select()" value="' . e($inviteUrl) . '"><button type="button" class="ghost" data-copy>Копировать</button></span></label>';
                if (!empty($activeInvite['expires_at'])) {
                    echo '<p class="muted">Действует до ' . e(substr((string) $activeInvite['expires_at'], 0, 16)) . '</p>';
                }
                echo '<form method="post" action="' . e(app_path('/person-account-invite')) . '" class="stack compact">' . csrf_field() . '<input type="hidden" name="person_id" value="' . $id . '"><button class="ghost">Создать новую ссылку</button></form>';
            } else {
                if ($activeInvite && empty($activeInvite['recoverable'])) {
                    echo '<p class="muted">Активная ссылка есть, но её нельзя показать повторно. Создайте новую.</p>';
                }
                echo '<form method="post" action="' . e(app_path('/person-account-invite')) . '" class="stack compact">' . csrf_field() . '<input type="hidden" name="person_id" value="' . $id . '"><button class="primary">Создать ссылку на создание учётки</button></form>';
            }
            echo '</div>';
        } else {
            echo '<p class="muted">Учётки ещё нет.</p>';
        }
    }
    echo '</article></section>';

    if ($changes) {
        echo '<details class="panel collapsible-panel person-change-log" data-default-collapsed="1"><summary><div><h2>История изменений</h2></div><span class="collapse-indicator" aria-hidden="true"></span></summary><div class="change-log">';
        foreach ($changes as $change) {
            $fieldLabel = person_change_field_label((string) $change['field_name']);
            $oldValue = format_person_change_value((string) $change['field_name'], $change['old_value'] ?? null, $stageNames);
            $newValue = format_person_change_value((string) $change['field_name'], $change['new_value'] ?? null, $stageNames);
            echo '<div class="change-log-row"><div><strong>' . e($fieldLabel) . '</strong><span class="muted">' . e(substr((string) $change['created_at'], 0, 16)) . ' · ' . e($change['user_name'] ?: 'система') . '</span></div>';
            echo '<p>' . e($oldValue) . ' → ' . e($newValue) . '</p></div>';
        }
        echo '</div></details>';
    }

    echo '<p class="person-export-link muted"><a class="ghost" href="' . e(app_path('/export-person?id=' . $id)) . '">Экспорт JSON</a></p>';

    page_footer();
}

function growth_page(array $user): void
{
    redirect_member_to_own_card($user);
    page_header('Карта роста церкви', $user);

    [$groupSql, $params] = accessible_group_sql($user, 'g');
    if (function_exists('districts_table_ready') && districts_table_ready()) {
        $groups = fetch_all(
            "SELECT g.*, c.name AS church_name, d.name AS district_name
             FROM home_groups g
             JOIN churches c ON c.id = g.church_id
             LEFT JOIN districts d ON d.id = g.district_id
             WHERE $groupSql AND g.is_active = 1
             ORDER BY c.name, d.name IS NULL, d.name, g.name",
            $params
        );
    } else {
        $groups = fetch_all(
            "SELECT g.*, c.name AS church_name, NULL AS district_name
             FROM home_groups g
             JOIN churches c ON c.id = g.church_id
             WHERE $groupSql AND g.is_active = 1
             ORDER BY c.name, g.name",
            $params
        );
    }
    $byDistrict = [];
    foreach ($groups as $group) {
        $districtKey = trim((string) ($group['district_name'] ?? '')) !== ''
            ? (string) $group['district_name']
            : 'Без округа';
        $byDistrict[$districtKey][] = $group;
    }

    echo '<section class="tree"><div class="tree-node church">' . e($user['church_name'] ?? (string) app_config('default_church_name')) . '</div>';
    foreach ($byDistrict as $districtName => $districtGroups) {
        echo '<div class="tree-branch"><div class="tree-node region">' . e($districtName) . '</div>';
        foreach ($districtGroups as $group) {
            echo '<div class="tree-branch"><a class="tree-node group" href="' . e(app_path('/group?id=' . (int) $group['id'])) . '">' . e($group['name']) . '</a>';
            $peopleSql = 'SELECT DISTINCT p.* FROM people p WHERE p.deleted_at IS NULL AND ' . people_in_group_sql('p')
                . ' ORDER BY p.is_leader DESC, p.is_potential_leader DESC, p.full_name';
            $peopleParams = person_group_memberships_ready() ? [(int) $group['id'], (int) $group['id']] : [(int) $group['id']];
            $people = fetch_all($peopleSql, $peopleParams);
            foreach ($people as $person) {
                $class = $person['is_leader'] ? 'leader' : ((int) $person['is_potential_leader'] ? 'future' : 'disciple');
                echo '<a class="tree-node ' . $class . '" href="' . e(app_path('/person?id=' . (int) $person['id'])) . '">' . e($person['full_name']) . '<small>' . e($person['training_stage']) . '</small></a>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</section>';
    page_footer();
}

function stats_page(array $user): void
{
    redirect_member_to_own_card($user);
    page_header('Аналитика здоровья', $user);
    $rows = group_health_stats($user);

    echo '<section class="panel"><h2>По группам</h2><div class="table-wrap"><table><thead><tr><th>Группа</th><th>Людей</th><th>Новые</th><th>Посещаемость</th><th>Служат</th><th>Будущие лидеры</th><th>Нужды закрыты</th><th>Оценка здоровья</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $avgAttendance = (int) $row['average_attendance'];
        $score = (int) $row['health_score'];
        echo '<tr><td>' . e($row['name']) . '</td><td>' . (int) $row['people_count'] . '</td><td>' . (int) $row['newcomers'] . '</td><td>' . $avgAttendance . '%</td><td>' . (int) $row['serving'] . '</td><td>' . (int) $row['future_leaders'] . '</td><td>' . (int) $row['closed_prayers'] . '</td><td><div class="health"><span style="width:' . $score . '%"></span></div>' . $score . '%</td></tr>';
    }
    echo '</tbody></table></div></section>';

    echo '<section class="panel"><h2>Люди в риске</h2>';
    foreach (care_inactive_people($user) as $person) {
        render_person_row($person);
    }
    echo '</section>';
    page_footer();
}

function knowledge_page(array $user): void
{
    if ($user['role'] === 'member') {
        http_response_code(403);
        exit('Недостаточно прав');
    }
    page_header('Обучение и база знаний', $user);
    $labels = ['training' => 'Обучение платформе и лидерству', 'faq' => 'FAQ', 'article' => 'База знаний', 'icebreaker' => 'Ледоколы и игры', 'template' => 'Шаблоны'];
    $canManageTraining = can_manage_training($user) && !(function_exists('demo_knowledge_editable') && !demo_knowledge_editable());
    $editMode = $canManageTraining && (($_GET['edit'] ?? '') === '1');

    if ($canManageTraining) {
        echo '<div class="page-actions">';
        if ($editMode) {
            echo '<a class="ghost" href="' . e(app_path('/knowledge')) . '">Режим чтения</a>';
        } else {
            echo '<a class="icon-btn" href="' . e(app_path('/knowledge?edit=1')) . '" title="Редактировать материалы" aria-label="Редактировать материалы">&#9998;</a>';
        }
        echo '</div>';
    }

    if ($editMode) {
        echo '<section class="panel"><div class="panel-head"><h2>Новый материал обучения</h2><span class="muted">Доступно областному лидеру, пастору и администратору</span></div>';
        echo '<form method="post" action="' . e(app_path('/knowledge-save')) . '" class="stack" enctype="multipart/form-data">' . csrf_field();
        echo '<div class="form-grid"><label>Раздел<select name="type">';
        foreach ($labels as $type => $label) echo '<option value="' . e($type) . '">' . e($label) . '</option>';
        echo '</select></label><label>Порядок<input name="sort_order" type="number" value="100"></label><label>Название<input name="title" required></label></div>';
        echo '<label>Текст<textarea name="body" rows="5" required data-knowledge-body></textarea></label>';
        echo knowledge_inline_image_controls_html();
        echo knowledge_image_field_html(null);
        echo '<button class="primary">Добавить материал</button></form></section>';
    }

    echo system_guide_promo_html($user);

    $course = home_group_leader_course_data();
    echo '<section class="panel knowledge-course-promo"><div class="knowledge-course-promo__copy"><p class="eyebrow">Новый курс</p><h2>' . e($course['title']) . '</h2><p class="muted">Полный курс для лидеров домашних групп. 6 уроков, раскрывающих видение, структуру служения, характер лидера и основы практического душепопечения.</p><a class="primary" href="' . e(app_path('/knowledge/home-group-leader-course')) . '">Открыть курс</a></div><img src="' . e(asset_url((string) $course['hero_image'])) . '" alt="Домашняя группа за общим столом" loading="lazy"></section>';

    echo '<section class="panel"><h2>Видеосеминары</h2><div class="grid two">';
    echo '<article class="knowledge-card knowledge-card--video"><h3>Домашняя группа: больше, чем просто встречи</h3><div class="knowledge-card__body">' . knowledge_render_body("https://vkvideo.ru/video-112148879_456239069") . '<p>Видео о домашних группах</p></div></article>';
    echo '<article class="knowledge-card knowledge-card--video"><h3>Мастер класс: домашние группы | EQUIP</h3><div class="knowledge-card__body">' . knowledge_render_body("https://vkvideo.ru/video-187304847_456239313") . '<p>Братская Христианская Община</p></div></article>';
    echo '</div></section>';

    foreach ($labels as $type => $label) {
        $items = fetch_all('SELECT * FROM knowledge_items WHERE type = ? ORDER BY sort_order, title', [$type]);
        echo '<section class="panel"><h2>' . e($label) . '</h2><div class="knowledge-grid">';
        foreach ($items as $item) {
            $class = $type === 'template' ? ' template-card' : '';
            echo '<article class="knowledge-card' . $class . '"><h3>' . e($item['title']) . '</h3>';
            echo knowledge_render_card_media($item['image_path'] ?? null, (string) $item['title']);
            echo '<div class="knowledge-card__body" data-template-body>' . knowledge_render_body((string) $item['body']) . '</div>';
            if ($type === 'template') echo '<button class="ghost" type="button" data-copy-template>Копировать</button>';
            if ($editMode) {
                echo '<form method="post" action="' . e(app_path('/knowledge-save')) . '" class="stack compact" enctype="multipart/form-data">' . csrf_field();
                echo '<input type="hidden" name="id" value="' . (int) $item['id'] . '"><label>Раздел<select name="type">';
                foreach ($labels as $optionType => $optionLabel) echo '<option value="' . e($optionType) . '"' . ($optionType === $item['type'] ? ' selected' : '') . '>' . e($optionLabel) . '</option>';
                echo '</select></label><label>Порядок<input name="sort_order" type="number" value="' . (int) $item['sort_order'] . '"></label><label>Название<input name="title" value="' . e($item['title']) . '" required></label>';
                echo '<label>Текст<textarea name="body" rows="4" required data-knowledge-body>' . e($item['body']) . '</textarea></label>';
                echo knowledge_inline_image_controls_html();
                echo knowledge_image_field_html($item['image_path'] ?? null);
                echo '<button class="ghost">Сохранить</button></form>';
            }
            echo '</article>';
        }
        echo '</div></section>';
    }
    page_footer();
}

function knowledge_course_paragraph_class(string $paragraph): string
{
    if (preg_match('/^(ОПРЕДЕЛЕНИЕ|ВАЖНЫЙ МОМЕНТ|БИБЛЕЙСКОЕ ОСНОВАНИЕ|ЗАДАЧИ СИСТЕМЫ|ПОСМОТРИМ)\b/u', $paragraph)) {
        return 'course-marker';
    }
    if (preg_match('/^[«"“].{40,}/u', $paragraph)) {
        return 'course-quote';
    }
    if (preg_match('/^(?:[0-9]+[.)]|[•-])\s*/u', $paragraph)) {
        return 'course-list-line';
    }
    if (strlen($paragraph) <= 140 && !str_contains($paragraph, '.') && preg_match('/[А-Яа-яЁё]/u', $paragraph)) {
        return 'course-subheading';
    }

    return 'course-text';
}

function render_knowledge_course_paragraph(string $paragraph): void
{
    $videoHtml = knowledge_render_video_iframes($paragraph);
    if ($videoHtml !== null) {
        echo $videoHtml;
        return;
    }

    $class = knowledge_course_paragraph_class($paragraph);
    if ($class === 'course-quote') {
        echo '<blockquote class="course-quote">' . e($paragraph) . '</blockquote>';
        return;
    }

    echo '<p class="' . e($class) . '">' . e($paragraph) . '</p>';
}

function knowledge_course_page(array $user): void
{
    if ($user['role'] === 'member') {
        http_response_code(403);
        exit('Недостаточно прав');
    }

    $course = home_group_leader_course_data();
    page_header((string) $course['title'], $user);

    $hero = asset_url((string) $course['hero_image']);
    echo '<section class="course-hero" id="top" style="background-image: linear-gradient(90deg, rgba(29,29,27,.78), rgba(29,29,27,.34)), url(' . e($hero) . ')">';
    echo '<div class="course-hero__content"><p class="eyebrow">6 уроков для команды домашних групп</p><h2>' . e($course['title']) . '</h2><div class="course-hero__actions"><a class="primary" href="#lesson-1">Начать курс</a><a class="ghost" href="' . e(app_path('/knowledge')) . '">Назад к базе знаний</a></div></div>';
    echo '</section>';

    echo '<section class="course-overview" aria-label="Оглавление курса">';
    foreach ($course['lessons'] as $lesson) {
        echo '<a class="course-overview__item" href="#' . e($lesson['anchor']) . '"><span>Урок ' . (int) $lesson['number'] . '</span><strong>' . e($lesson['title']) . '</strong></a>';
    }
    echo '</section>';

    echo '<section class="course-layout">';
    echo '<aside class="course-toc" aria-label="Навигация по курсу"><strong>Навигация</strong><nav>';
    foreach ($course['lessons'] as $lesson) {
        echo '<a href="#' . e($lesson['anchor']) . '">Урок ' . (int) $lesson['number'] . '</a>';
    }
    echo '</nav></aside>';

    echo '<article class="course-reader">';
    echo '<section class="course-intro"><p class="eyebrow">Программа курса</p><h2>Темы обучения</h2><div class="course-intro__grid">';
    foreach ($course['intro'] as $introLine) {
        echo '<p>' . e($introLine) . '</p>';
    }
    echo '</div></section>';

    foreach ($course['lessons'] as $lesson) {
        echo '<section class="course-lesson" id="' . e($lesson['anchor']) . '">';
        echo '<div class="course-lesson__head"><span>Урок ' . (int) $lesson['number'] . '</span><h2>' . e($lesson['title']) . '</h2><p>' . e($lesson['summary']) . '</p></div>';
        echo '<div class="course-lesson__body">';
        foreach ($lesson['paragraphs'] as $paragraph) {
            render_knowledge_course_paragraph((string) $paragraph);
        }
        echo '</div><a class="course-back-top" href="#top">К оглавлению</a></section>';
    }

    echo '</article></section>';
    page_footer();
}

function knowledge_system_guide_page(array $user): void
{
    if ($user['role'] === 'member') {
        http_response_code(403);
        exit('Недостаточно прав');
    }

    page_header('Как пользоваться системой', $user);
    system_guide_render_page($user);
    page_footer();
}

function security_page(array $user): void
{
    if (!is_super_admin($user)) {
        redirect('/profile');
    }
    page_header('Безопасность', $user);
    $vapid = webpush_config();
    $hasVapid = $vapid['public_key'] !== '' && $vapid['private_key'] !== '';
    $hasContact = $vapid['subject'] !== '';
    echo '<section class="grid two"><article class="panel"><h2>VAPID-ключи WebPush</h2>';
    echo '<p class="muted">' . ($hasVapid ? 'Ключи заданы. Можно сгенерировать новые или сохранить вручную.' : 'Ключи ещё не заданы. Сгенерируйте пару или вставьте существующие.') . '</p>';
    if ($hasVapid && !$hasContact) {
        echo '<p class="muted">Ключи есть, но не указан контакт отправителя — без него отправка уведомлений не заработает.</p>';
    }
    echo '<p class="muted">2FA настраивается в <a href="' . e(app_path('/profile')) . '">профиле</a>.</p>';
    echo '<form method="post" action="' . e(app_path('/vapid-generate')) . '" class="stack">' . csrf_field() . '<button class="primary" type="submit" data-confirm="Сгенерировать новую пару ключей? Старые подписки браузеров перестанут работать, пользователям нужно будет снова подключить WebPush.">Сгенерировать ключи</button></form>';
    echo '<form method="post" action="' . e(app_path('/settings-security')) . '" class="stack">' . csrf_field();
    echo '<label>Публичный ключ<input name="vapid_public_key" value="' . e($vapid['public_key']) . '" autocomplete="off"></label>';
    echo '<label>Закрытый ключ<input name="vapid_private_key" value="' . e($vapid['private_key']) . '" autocomplete="off"></label>';
    echo '<label>Контакт отправителя<input name="vapid_subject" value="' . e($vapid['subject']) . '" placeholder="email@церковь.ru или https://сайт-церкви.ru" autocomplete="off"></label>';
    echo '<p class="muted">Это не ключ и не тема письма. По стандарту WebPush сюда указывают контакт владельца приложения: рабочий email администрации или URL сайта. Push-сервисы могут связаться по нему при проблемах с рассылкой. Ключи общие для всех церквей.</p>';
    echo '<button class="ghost">Сохранить настройки</button></form></article></section>';
    page_footer();
}
function save_webpush_subscription(array $user): void
{
    verify_csrf();
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $endpoint = $payload['endpoint'] ?? '';
    $keys = $payload['keys'] ?? [];
    if (!$endpoint || empty($keys['p256dh']) || empty($keys['auth'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid subscription']);
        return;
    }
    $stmt = db()->prepare('INSERT INTO web_push_subscriptions (user_id, endpoint, p256dh, auth, user_agent) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth), user_agent = VALUES(user_agent), last_seen_at = CURRENT_TIMESTAMP');
    $stmt->execute([$user['id'], $endpoint, $keys['p256dh'], $keys['auth'], substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
    audit('webpush_subscribe', 'user', (int) $user['id']);
    echo json_encode(['ok' => true]);
}

if ($path === '/login') { login_page(); exit; }
if ($path === '/demo/enter') { demo_enter_and_redirect(); }
if ($method === 'POST' && $path === '/demo/reset') { demo_reset_and_redirect(); }
if ($path === '/social/start') { social_start((string) ($_GET['provider'] ?? ''), (string) ($_GET['mode'] ?? 'login'), (string) ($_GET['return_to'] ?? '/')); }
if ($path === '/social/callback') { social_callback(); }
if ($method === 'POST' && $path === '/bot/max/webhook') { bot_handle_webhook('max'); exit; }
if ($method === 'POST' && $path === '/bot/vk/webhook') { bot_handle_webhook('vk'); exit; }
if ($method === 'POST' && $path === '/bot/telegram/webhook') { bot_handle_webhook('telegram'); exit; }
if ($path === '/forgot-password') { forgot_password_page(); exit; }
if ($path === '/reset-password') { reset_password_page(); exit; }
if ($path === '/account-invite') { account_invite_page(); exit; }
if ($path === '/role-invite') { role_invite_page(); exit; }
if ($path === '/regchurch') { church_invite_page(); exit; }
if ($path === '/church-invite') {
    $qs = $queryString !== '' ? '?' . $queryString : '';
    redirect('/regchurch' . $qs);
}
if ($path === '/homegroup') { homegroup_catalog_page(); exit; }
if ($path === '/homegroup/join') { homegroup_join_page(); exit; }
if ($path === '/join') { redirect('/homegroup/join' . ($queryString !== '' ? '?' . $queryString : '')); }
if (preg_match('#^/l/([a-z0-9]+(?:-[a-z0-9]+)*)$#', $path, $landingMatch) === 1) {
    $landingChurch = find_church_by_slug($landingMatch[1]);
    if (
        !$landingChurch
        || !empty($landingChurch['deleted_at'])
        || (function_exists('is_demo_church') && is_demo_church($landingChurch))
    ) {
        http_response_code(404);
        exit('Страница не найдена');
    }
    set_current_church($landingChurch);
    church_about_page();
    exit;
}
if ($path === '/about') {
    // Legacy /{slug}/about → /l/{slug}
    if ($tenantBound) {
        $legacySlug = current_church_slug();
        if ($legacySlug !== null && $legacySlug !== '') {
            redirect('/l/' . $legacySlug . ($queryString !== '' ? '?' . $queryString : ''));
        }
    }
    http_response_code(404);
    exit('Страница не найдена');
}
if ($path === '/prayer-request') {
    if (!$tenantBound) {
        redirect_legacy_crm_to_default_church('/prayer-request', $queryString);
    }
    prayer_request_public_page();
    exit;
}
if ($path === '/miracle-share') {
    if (!$tenantBound) {
        redirect_legacy_crm_to_default_church('/miracle-share', $queryString);
    }
    miracle_share_public_page();
    exit;
}
if ($path === '/api/homegroups') { homegroup_catalog_api(); exit; }
if ($method === 'POST' && $path === '/geocode/reverse') { handle_geocode_reverse(); exit; }
if (function_exists('mcp_oauth_handle_request') && mcp_oauth_handle_request($path, $method)) { exit; }
if ($path === '/mcp') { mcp_handle_http_request(); exit; }
if ($path === '/system/migrate') { migrate_handle_http_request(); exit; }
if ($path === '/manifest.webmanifest') {
    if ($method !== 'GET' && $method !== 'HEAD') {
        http_response_code(405);
        header('Allow: GET, HEAD');
        exit;
    }
    send_pwa_manifest_response(current_church_slug());
}

// Platform root "/" — church picker (PWA/bookmarks for guests; signed-in users jump to their church).
if (!$tenantBound && $path === '/') {
    church_picker_page(current_user());
    exit;
}

// /{unknown-slug} or /{unknown-slug}/… — church missing (renamed/deleted), not a legacy CRM path.
if (!$tenantBound) {
    $rawParts = explode('/', trim($rawPath, '/'));
    $maybeSlug = strtolower((string) ($rawParts[0] ?? ''));
    if (
        $maybeSlug !== ''
        && church_slug_is_valid($maybeSlug)
        && !in_array($maybeSlug, church_slug_reserved_segments(), true)
        && !in_array($maybeSlug, crm_logical_root_segments(), true)
    ) {
        http_response_code(404);
        public_page_header('Церковь не найдена');
        echo '<section class="login-card stack">';
        echo '<h1>Церковь не найдена</h1>';
        echo '<p class="muted">Адрес <code>/' . e($maybeSlug) . '</code> больше не соответствует активной церкви. Возможно, slug изменили в настройках.</p>';
        echo '<p><a class="primary" href="/">Выбрать церковь</a></p>';
        echo '</section>';
        public_page_footer();
        exit;
    }
}

// Old CRM URLs without slug → /{default}/... (bookmarks, installed PWA shortcuts, stale forms).
if (!$tenantBound && ($path === '/login' || is_legacy_crm_path($path))) {
    redirect_legacy_crm_to_default_church($path, $queryString);
}

$user = require_login();

if (function_exists('demo_guard_request')) {
    demo_guard_request($path, $method);
}

// Authenticated CRM requires a church tenant (except platform /admin).
if (!$tenantBound && !is_platform_root_path($path)) {
    if (is_legacy_crm_path($path)) {
        redirect_legacy_crm_to_default_church($path, $queryString);
    }
    http_response_code(404);
    echo 'Страница не найдена';
    exit;
}

if ($tenantBound && ($path === '/admin' || str_starts_with($path, '/admin/'))) {
    header('Location: ' . platform_path($path) . ($queryString !== '' ? '?' . $queryString : ''), true, 302);
    exit;
}

if ($tenantBound && !user_can_access_church($user, (int) (current_church()['id'] ?? 0))) {
    http_response_code(403);
    echo 'Нет доступа к этой церкви.';
    exit;
}

if (handle_role_preview_post($path, $method, $user)) {
    exit;
}

if (role_preview_blocks_mutation($method, $path)) {
    deny_role_preview_mutation();
}

if ($method === 'POST' && $path === '/logout') {
    verify_csrf();
    $demoLogout = function_exists('is_demo_session') && is_demo_session();
    audit('logout', 'user', (int) $user['id']);
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    if ($demoLogout) {
        header('Location: ' . demo_logout_redirect_target(), true, 302);
        exit;
    }
    ajax_redirect("/login");
}
if ($method === 'POST' && $path === '/push/subscribe') { save_webpush_subscription($user); exit; }
mcp_handle_account_post($path, $method, $user);
handle_account_post($path, $method, $user);
handle_districts_post($path, $method, $user);
handle_social_account_post($path, $method, $user);
handle_bot_account_post($path, $method, $user);
handle_church_landing_post($path, $user);
handle_feature_post($path, $method, $user);
if ($method === 'POST' && $path === '/task-done') {
    verify_csrf();
    $taskId = (int) $_POST['id'];
    $duplicateDone = fetch_one(
        'SELECT done_task.id
         FROM tasks current_task
         JOIN tasks done_task
           ON done_task.id <> current_task.id
          AND done_task.person_id <=> current_task.person_id
          AND done_task.assigned_to <=> current_task.assigned_to
          AND done_task.type = current_task.type
          AND done_task.due_date = current_task.due_date
          AND done_task.status = "done"
         WHERE current_task.id = ? AND current_task.assigned_to = ? AND current_task.status = "open"
         LIMIT 1',
        [$taskId, $user['id']]
    );
    if ($duplicateDone) {
        db()->prepare('DELETE FROM tasks WHERE id = ? AND assigned_to = ? AND status = "open"')->execute([$taskId, $user['id']]);
        audit('task_done_duplicate_merged', 'task', $taskId, ['merged_into' => (int) $duplicateDone['id']]);
    } else {
        db()->prepare('UPDATE tasks SET status = "done", completed_at = NOW() WHERE id = ? AND assigned_to = ?')->execute([$taskId, $user['id']]);
        audit('task_done', 'task', $taskId);
    }
    ajax_redirect("/", "Задача закрыта");
}
if ($method === 'POST' && $path === '/person-create') {
    verify_csrf();
    if (!can_edit_group_data($user)) ajax_redirect('/');
    $group = scoped_group($user, (int) $_POST['group_id']);
    if (!$group) ajax_redirect('/groups', 'Группа не найдена или недоступна');
    try {
        if (function_exists('demo_assert_people_limit')) {
            demo_assert_people_limit((int) ($group['church_id'] ?? 0));
        }
    } catch (Throwable $e) {
        ajax_redirect('/group?id=' . (int) $group['id'], $e->getMessage());
    }
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    if ($fullName === '') ajax_redirect('/group?id=' . (int) $group['id'], 'Укажите имя и фамилию');
    db()->prepare('INSERT INTO people (group_id, full_name, phone, email, telegram, first_visit_date, training_stage) VALUES (?, ?, ?, ?, ?, ?, "новичок")')
        ->execute([
            (int) $group['id'],
            $fullName,
            ChurchCare\normalize_phone($_POST['phone'] ?? null),
            trim((string) ($_POST['email'] ?? '')) ?: null,
            ChurchCare\normalize_telegram($_POST['telegram'] ?? null),
            $_POST['first_visit_date'] ?: date('Y-m-d'),
        ]);
    $personId = (int) db()->lastInsertId();
    try {
        person_add_group_membership($personId, (int) $group['id'], true);
    } catch (Throwable $e) {
        // Membership table may be unavailable until migration; primary group_id already set.
    }
    audit('person_create', 'person', $personId, ['group_id' => (int) $group['id']]);
    ajax_redirect('/person?id=' . $personId, 'Карточка человека создана');
}
if ($method === 'POST' && $path === '/person-set-group') {
    verify_csrf();
    if (!can_edit_group_data($user)) {
        ajax_redirect('/');
    }
    $person = scoped_person($user, (int) ($_POST['person_id'] ?? 0));
    if (!$person) {
        ajax_redirect('/users', 'Карточка не найдена или недоступна');
    }
    $group = scoped_group($user, (int) ($_POST['group_id'] ?? 0));
    if (!$group) {
        ajax_redirect('/users', 'Группа не найдена или недоступна');
    }
    $returnTo = trim((string) ($_POST['return'] ?? 'people')) === 'people' ? '/users' : '/person?id=' . (int) $person['id'];
    try {
        person_add_group_membership((int) $person['id'], (int) $group['id'], true);
        // Keep CRM primary group in sync when account exists.
        if ((int) ($person['user_id'] ?? 0) > 0) {
            db()->prepare('UPDATE users SET group_id = ? WHERE id = ?')->execute([(int) $group['id'], (int) $person['user_id']]);
        }
        audit('person_set_group', 'person', (int) $person['id'], ['group_id' => (int) $group['id']]);
        ajax_redirect($returnTo, 'Домашняя группа обновлена');
    } catch (Throwable $e) {
        $message = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Не удалось обновить группу';
        ajax_redirect($returnTo, $message);
    }
}
if ($method === 'POST' && $path === '/person-group-add') {
    verify_csrf();
    if (!can_edit_group_data($user)) {
        ajax_redirect('/');
    }
    $person = scoped_person($user, (int) ($_POST['person_id'] ?? 0));
    if (!$person) {
        ajax_redirect('/users', 'Карточка не найдена или недоступна');
    }
    $group = scoped_group($user, (int) ($_POST['group_id'] ?? 0));
    if (!$group) {
        ajax_redirect('/person?id=' . (int) $person['id'], 'Группа не найдена или недоступна');
    }
    try {
        person_add_group_membership((int) $person['id'], (int) $group['id'], false);
        audit('person_group_add', 'person', (int) $person['id'], ['group_id' => (int) $group['id']]);
        ajax_redirect('/person?id=' . (int) $person['id'], 'Человек добавлен в группу');
    } catch (Throwable $e) {
        $message = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Не удалось добавить в группу';
        ajax_redirect('/person?id=' . (int) $person['id'], $message);
    }
}
if ($method === 'POST' && $path === '/person-group-remove') {
    verify_csrf();
    if (!can_edit_group_data($user)) {
        ajax_redirect('/');
    }
    $person = scoped_person($user, (int) ($_POST['person_id'] ?? 0));
    if (!$person) {
        ajax_redirect('/users', 'Карточка не найдена или недоступна');
    }
    $groupId = (int) ($_POST['group_id'] ?? 0);
    if ($groupId <= 0 || !scoped_group($user, $groupId)) {
        ajax_redirect('/person?id=' . (int) $person['id'], 'Группа не найдена или недоступна');
    }
    try {
        person_remove_group_membership((int) $person['id'], $groupId);
        audit('person_group_remove', 'person', (int) $person['id'], ['group_id' => $groupId]);
        ajax_redirect('/person?id=' . (int) $person['id'], 'Человек убран из группы');
    } catch (Throwable $e) {
        $message = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Не удалось убрать из группы';
        ajax_redirect('/person?id=' . (int) $person['id'], $message);
    }
}
if ($method === 'POST' && $path === '/person-group-primary') {
    verify_csrf();
    if (!can_edit_group_data($user)) {
        ajax_redirect('/');
    }
    $person = scoped_person($user, (int) ($_POST['person_id'] ?? 0));
    if (!$person) {
        ajax_redirect('/users', 'Карточка не найдена или недоступна');
    }
    $groupId = (int) ($_POST['group_id'] ?? 0);
    if ($groupId <= 0 || !person_belongs_to_group((int) $person['id'], $groupId) || !scoped_group($user, $groupId)) {
        ajax_redirect('/person?id=' . (int) $person['id'], 'Группа не найдена или недоступна');
    }
    person_sync_primary_group((int) $person['id'], $groupId);
    audit('person_group_primary', 'person', (int) $person['id'], ['group_id' => $groupId]);
    ajax_redirect('/person?id=' . (int) $person['id'], 'Основная группа обновлена');
}
if ($method === 'POST' && $path === '/meeting-create') {
    verify_csrf();
    try {
        $created = meeting_create_for_user(
            $user,
            (int) ($_POST['group_id'] ?? 0),
            (string) ($_POST['meeting_date'] ?? ''),
            trim((string) ($_POST['topic'] ?? ''))
        );
        ajax_redirect('/meeting?id=' . $created['id'], 'Встреча создана');
    } catch (Throwable $e) {
        $message = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Не удалось создать встречу';
        ajax_redirect('/groups', $message);
    }
}
if ($method === 'POST' && $path === '/meeting-save') {
    verify_csrf();
    $meetingId = (int) ($_POST['meeting_id'] ?? 0);
    try {
        meeting_save_attendance_for_user(
            $user,
            $meetingId,
            array_map('intval', $_POST['present'] ?? []),
            trim((string) ($_POST['atmosphere'] ?? '')) ?: null,
            (int) ($_POST['had_conflicts'] ?? 0) === 1,
            (int) ($_POST['pastor_help_needed'] ?? 0) === 1,
            trim((string) ($_POST['notes'] ?? ''))
        );
        ajax_redirect('/meeting?id=' . $meetingId, 'Встреча сохранена');
    } catch (Throwable $e) {
        $message = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Не удалось сохранить встречу';
        ajax_redirect('/calendar', $message);
    }
}
if ($method === 'POST' && $path === '/contact-add') {
    verify_csrf();
    if (!can_edit_group_data($user)) ajax_redirect("/");
    $person = scoped_person($user, (int) $_POST['person_id']);
    if (!$person) ajax_redirect('/care', 'Карточка не найдена или недоступна');
    $stmt = db()->prepare('INSERT INTO contact_logs (person_id, user_id, contact_date, type, summary, absence_reason, next_contact_at) VALUES (?, ?, CURDATE(), ?, ?, ?, ?)');
    $stmt->execute([(int) $person['id'], $user['id'], $_POST['type'], trim((string) $_POST['summary']), trim((string) $_POST['absence_reason']) ?: null, $_POST['next_contact_at'] ?: null]);
    audit('contact_add', 'person', (int) $person['id']);
    ajax_redirect("/person?id=" . (int) $person['id'], "Контакт записан");
}
if ($method === 'POST' && $path === '/prayer-add') {
    verify_csrf();
    if (!can_edit_group_data($user)) ajax_redirect("/");
    $person = scoped_person($user, (int) $_POST['person_id']);
    if (!$person) ajax_redirect('/care', 'Карточка не найдена или недоступна');
    $group = fetch_one('SELECT church_id FROM home_groups WHERE id = ? LIMIT 1', [(int) $person['group_id']]);
    $churchId = (int) ($group['church_id'] ?? $user['church_id'] ?? 0) ?: null;
    $stmt = db()->prepare('INSERT INTO prayer_requests (person_id, group_id, church_id, title, description, status, next_reminder_at, created_by) VALUES (?, ?, ?, ?, ?, "new", CURDATE() + INTERVAL 14 DAY, ?)');
    $stmt->execute([(int) $person['id'], (int) $person['group_id'], $churchId, trim((string) $_POST['title']), trim((string) $_POST['description']), $user['id']]);
    audit('prayer_add', 'person', (int) $person['id']);
    ajax_redirect("/person?id=" . (int) $person['id'], "Молитвенная нужда добавлена");
}
if ($method === 'POST' && $path === '/person-delete') {
    verify_csrf();
    require_action($user, 'delete_people');
    $person = scoped_person($user, (int) ($_POST['id'] ?? 0));
    if (!$person) ajax_redirect('/users', 'Карточка не найдена или недоступна');
    $personId = (int) $person['id'];
    delete_person_account_card($person);
    audit('person_delete', 'person', $personId, ['full_name' => $person['full_name']]);
    if (wants_ajax()) {
        json_response([
            'ok' => true,
            'message' => 'Карточка удалена',
            'remove' => '[data-person-row="' . $personId . '"]',
        ]);
    }
    ajax_redirect('/users', 'Карточка удалена');
}
if ($path === '/export-person') {
    $id = (int) ($_GET['id'] ?? 0);
    [$personSql, $params] = accessible_person_sql($user, 'p');
    $params[] = $id;
    $person = fetch_one("SELECT p.* FROM people p JOIN home_groups g ON g.id = p.group_id WHERE $personSql AND p.id = ?", $params);
    if (!$person) { http_response_code(404); exit('Not found'); }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="person-' . $id . '.json"');
    echo json_encode($person, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

match ($path) {
    '/' => dashboard($user),
    '/groups' => groups_page($user),
    '/group' => group_page($user),
    '/care' => care_hub_page($user),
    '/analytics' => analytics_hub_page($user),
    '/users' => users_page($user),
    '/user' => user_page($user),
    '/participant-requests' => participant_requests_page($user),
    '/role-requests' => role_requests_page($user),
    '/profile' => profile_page($user),
    '/settings' => settings_hub_page($user),
    '/church-settings' => church_settings_page($user),
    '/church-page' => church_landing_editor_page($user),
    '/meeting' => meeting_page($user),
    '/person' => person_page($user),
    '/growth' => growth_page($user),
    '/stats' => stats_page($user),
    '/knowledge' => knowledge_page($user),
    '/knowledge/system-guide' => knowledge_system_guide_page($user),
    '/knowledge/home-group-leader-course' => knowledge_course_page($user),
    '/calendar' => calendar_page($user),
    '/services' => services_page($user),
    '/match' => group_match_page($user),
    '/map' => group_map_page($user),
    '/prayers' => prayers_page($user),
    '/miracles' => miracles_page($user),
    '/miracle' => miracle_page($user),
    '/newcomers' => newcomers_page($user),
    '/regional' => regional_page($user),
    '/districts' => districts_page($user),
    '/church-analytics' => church_analytics_page($user),
    '/courses' => courses_page($user),
    '/admin' => admin_page($user),
    '/permissions' => permissions_page($user),
    '/privacy' => privacy_page($user),
    '/import' => import_page($user),
    '/meeting-report' => meeting_report_page($user),
    '/security' => security_page($user),
    '/role-preview' => role_preview_page($user),
    default => (function () { http_response_code(404); echo 'Страница не найдена'; })(),
};
