<?php

declare(strict_types=1);

function previewable_roles(): array
{
    return roles();
}

function is_valid_preview_role(string $role): bool
{
    return in_array($role, previewable_roles(), true);
}

function can_use_role_preview_for_user(array $user): bool
{
    return in_array(($user['role'] ?? ''), ['admin', 'super_admin'], true);
}

function user_with_preview_role(array $user, ?string $previewRole): array
{
    if ($previewRole === null || $previewRole === '' || $previewRole === ($user['role'] ?? '')) {
        return $user;
    }

    if (!can_use_role_preview_for_user($user) || !is_valid_preview_role($previewRole)) {
        return $user;
    }

    $effective = $user;
    $effective['role'] = $previewRole;

    return $effective;
}

function role_preview_session_key(): string
{
    return 'role_preview';
}

function active_role_preview(?array $realUser): ?string
{
    if (!$realUser || !can_use_role_preview_for_user($realUser)) {
        return null;
    }

    $role = $_SESSION[role_preview_session_key()] ?? null;
    if (!is_string($role) || !is_valid_preview_role($role)) {
        return null;
    }

    if ($role === ($realUser['role'] ?? '')) {
        return null;
    }

    return $role;
}

function is_role_preview_active(): bool
{
    return active_role_preview(real_user()) !== null;
}

function set_role_preview(?string $role): void
{
    $real = real_user();
    if (!$real || !can_use_role_preview_for_user($real)) {
        unset($_SESSION[role_preview_session_key()]);
        return;
    }

    if ($role === null || $role === '') {
        unset($_SESSION[role_preview_session_key()]);
        return;
    }

    if (!is_valid_preview_role($role)) {
        return;
    }

    if ($role === ($real['role'] ?? '')) {
        unset($_SESSION[role_preview_session_key()]);
        return;
    }

    $_SESSION[role_preview_session_key()] = $role;
}

function role_preview_mutation_allowed_paths(): array
{
    return ['/logout', '/role-preview-start', '/role-preview-stop'];
}

function role_preview_blocks_mutation(string $method, string $path): bool
{
    if ($method !== 'POST' || !is_role_preview_active()) {
        return false;
    }

    return !in_array($path, role_preview_mutation_allowed_paths(), true);
}

function deny_role_preview_mutation(): never
{
    if (wants_ajax()) {
        json_response([
            'ok' => false,
            'error' => 'В режиме просмотра роли изменения недоступны. Выйдите из просмотра, чтобы редактировать данные.',
        ], 403);
    }

    ajax_redirect('/', 'В режиме просмотра роли изменения недоступны. Выйдите из просмотра, чтобы редактировать данные.');
}

function role_preview_banner_markup(array $user): string
{
    if (!is_role_preview_active()) {
        return '';
    }

    $roleLabel = role_label((string) $user['role']);

    return '<section class="role-preview-banner" role="status" aria-live="polite">'
        . '<div class="role-preview-banner__copy">'
        . '<p class="eyebrow">Просмотр от имени роли</p>'
        . '<p><strong>' . e($roleLabel) . '</strong> — интерфейс и доступы показаны так, как их видит эта роль. Изменения данных отключены.</p>'
        . '</div>'
        . '<div class="role-preview-banner__actions">'
        . '<a class="ghost" href="' . e(app_path('/role-preview')) . '">Сменить роль</a>'
        . '<form method="post" action="' . e(app_path('/role-preview-stop')) . '" class="inline-form">' . csrf_field()
        . '<button class="primary">Выйти из просмотра</button></form>'
        . '</div></section>';
}

function role_preview_page(array $user): void
{
    $real = real_user();
    if (!$real || !can_use_role_preview_for_user($real)) {
        http_response_code(403);
        exit('Недостаточно прав');
    }

    page_header('Просмотр от имени роли', $user);

    $activeRole = active_role_preview($real);
    echo '<section class="panel stack"><h2>Как видит систему другая роль</h2>';
    echo '<p class="muted">Администратор может посмотреть навигацию, доступные разделы и ограничения так, как их видит выбранная роль. Ваши церковь и группа остаются прежними — меняется только уровень доступа.</p>';

    if ($activeRole) {
        echo '<div class="flash">Сейчас активен просмотр роли <strong>' . e(role_label($activeRole)) . '</strong>.</div>';
    }

    echo '<form method="post" action="' . e(app_path('/role-preview-start')) . '" class="stack">' . csrf_field();
    echo '<label>Роль для просмотра<select name="role" required>';
    foreach (previewable_roles() as $role) {
        $selected = $activeRole === $role ? ' selected' : '';
        echo '<option value="' . e($role) . '"' . $selected . '>' . e(role_label($role)) . '</option>';
    }
    echo '</select></label>';
    echo '<div class="quick-actions"><button class="primary">Начать просмотр</button>';
    if ($activeRole) {
        echo '<button class="ghost" formaction="' . e(app_path('/role-preview-stop')) . '" formmethod="post">Выйти из просмотра</button>';
    }
    echo '</div></form></section>';

    echo '<section class="panel"><h2>Что учитывается</h2><ul class="stack muted">';
    echo '<li>Меню, разделы и кнопки соответствуют выбранной роли.</li>';
    echo '<li>Матрица прав и видимость полей карточек берутся из настроек этой роли.</li>';
    echo '<li>Область данных остаётся привязанной к вашей церкви и группе.</li>';
    echo '<li>Любые изменения данных в режиме просмотра заблокированы.</li>';
    echo '<li>Для роли «Участник» нужна привязанная пастырская карточка к вашей учётной записи.</li>';
    echo '</ul></section>';

    page_footer();
}

function role_preview_member_empty_page(array $user): void
{
    page_header('Просмотр: участник', $user);
    echo '<section class="panel stack"><h2>Нет привязанной карточки</h2>';
    echo '<p class="muted">Участник видит только свою пастырскую карточку. У вашей учётной записи администратора нет связанной карточки, поэтому полный сценарий участника показать нельзя.</p>';
    echo '<p class="muted">Привяжите карточку к аккаунту или выберите другую роль для просмотра.</p>';
    echo '<div class="quick-actions"><a class="ghost" href="' . e(app_path('/role-preview')) . '">Сменить роль</a>';
    echo '<form method="post" action="' . e(app_path('/role-preview-stop')) . '" class="inline-form">' . csrf_field();
    echo '<button class="primary">Выйти из просмотра</button></form></div></section>';
    page_footer();
}

function handle_role_preview_post(string $path, string $method, array $user): bool
{
    if ($method !== 'POST') {
        return false;
    }

    if ($path === '/role-preview-start') {
        verify_csrf();
        $real = real_user();
        if (!$real || !can_use_role_preview_for_user($real)) {
            http_response_code(403);
            exit('Недостаточно прав');
        }

        $role = (string) ($_POST['role'] ?? '');
        if (!is_valid_preview_role($role)) {
            ajax_redirect('/role-preview', 'Выберите корректную роль');
        }

        set_role_preview($role);
        audit('role_preview_start', 'role', null, ['preview_role' => $role]);
        ajax_redirect('/', 'Просмотр роли «' . role_label($role) . '» включён');
    }

    if ($path === '/role-preview-stop') {
        verify_csrf();
        $real = real_user();
        if (!$real || !can_use_role_preview_for_user($real)) {
            http_response_code(403);
            exit('Недостаточно прав');
        }

        $previous = active_role_preview($real);
        set_role_preview(null);
        audit('role_preview_stop', 'role', null, ['preview_role' => $previous]);
        ajax_redirect('/role-preview', $previous ? 'Просмотр роли «' . role_label($previous) . '» выключен' : 'Просмотр роли выключен');
    }

    return false;
}
