<?php

declare(strict_types=1);

function roles(): array { return ['member','assistant','leader','district_leader','pastor','admin','super_admin']; }

function permission_actions(): array
{
    return [
        'manage_structure' => 'Группы и пользователи церкви; настройки церкви (без создания/удаления церквей платформы)',
        'manage_church_page' => 'Публичная страница церкви (лендинг): контент, кнопки, расписание, фото',
        'manage_security' => 'Двухфакторная аутентификация',
        'edit_people' => 'Редактирование карточек людей',
        'delete_people' => 'Удаление персональных данных',
        'manage_prayers' => 'Редактирование молитвенных нужд',
        'manage_services' => 'Планирование служений',
        'import_data' => 'Импорт CSV',
        'view_church_analytics' => 'Церковная аналитика',
    ];
}

function default_permission(string $role, string $action): bool
{
    if ($role === 'super_admin' || $role === 'admin') return true;
    if ($role === 'pastor') return in_array($action, ['manage_security','manage_church_page','edit_people','delete_people','manage_prayers','manage_services','view_church_analytics'], true);
    if ($role === 'district_leader') return in_array($action, ['edit_people','manage_prayers','manage_services','view_church_analytics'], true);
    if (in_array($role, ['leader','assistant'], true)) return in_array($action, ['edit_people','manage_prayers','manage_services'], true);
    return false;
}

function can_action(array $user, string $action): bool
{
    if (function_exists('demo_can_action')) {
        $demo = demo_can_action($user, $action);
        if ($demo !== null) {
            return $demo;
        }
    }
    try {
        $row = fetch_one('SELECT allowed FROM role_permissions WHERE role = ? AND action = ? LIMIT 1', [$user['role'], $action]);
        if ($row !== null) return (bool) $row['allowed'];
    } catch (Throwable) {
        return default_permission((string) $user['role'], $action);
    }
    return default_permission((string) $user['role'], $action);
}


function can_manage_training(array $user): bool
{
    return in_array($user['role'], ['district_leader', 'pastor', 'admin', 'super_admin'], true);
}

function require_action(array $user, string $action): void
{
    if (!can_action($user, $action)) { http_response_code(403); exit('Недостаточно прав'); }
}

function app_setting(string $key, ?string $fallback = null): ?string
{
    try { $row = fetch_one('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1', [$key]); return $row['setting_value'] ?? $fallback; }
    catch (Throwable) { return $fallback; }
}

function save_app_setting(string $key, string $value, bool $secret, int $userId): void
{
    db()->prepare('INSERT INTO app_settings (setting_key, setting_value, is_secret, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret), updated_by = VALUES(updated_by)')->execute([$key, $value, $secret ? 1 : 0, $userId]);
}

function webpush_config(): array
{
    return [
        'public_key' => (string) (app_setting('vapid_public_key', (string) (app_config('webpush.public_key') ?: '')) ?: ''),
        'private_key' => (string) (app_setting('vapid_private_key', (string) (app_config('webpush.private_key') ?: '')) ?: ''),
        'subject' => (string) (app_setting('vapid_subject', (string) (app_config('webpush.subject') ?: '')) ?: ''),
    ];
}

function base64url_encode_bin(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** @return array{publicKey: string, privateKey: string} */
function generate_vapid_keys(): array
{
    $key = openssl_pkey_new([
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    if ($key === false) {
        throw new RuntimeException('Не удалось создать EC-ключ VAPID.');
    }

    $details = openssl_pkey_get_details($key);
    if ($details === false || empty($details['ec']['x']) || empty($details['ec']['y']) || empty($details['ec']['d'])) {
        throw new RuntimeException('Не удалось извлечь параметры VAPID-ключа.');
    }

    $x = str_pad((string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT);
    $y = str_pad((string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $d = str_pad((string) $details['ec']['d'], 32, "\0", STR_PAD_LEFT);

    return [
        'publicKey' => base64url_encode_bin("\x04" . $x . $y),
        'privateKey' => base64url_encode_bin($d),
    ];
}

/** Нормализует контакт VAPID: mailto:... или https://... */
function normalize_vapid_subject(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $value) === 1) {
        return $value;
    }
    if (stripos($value, 'mailto:') === 0) {
        $email = trim(substr($value, 7));
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? 'mailto:' . $email : '';
    }
    if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
        return 'mailto:' . $value;
    }
    return '';
}

function two_factor_secret(): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bytes = random_bytes(20);
    $secret = '';
    foreach (str_split($bytes) as $byte) {
        $secret .= $alphabet[ord($byte) & 31];
    }
    return $secret;
}

function base32_decode_secret(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret));
    $buffer = '';
    foreach (str_split($secret) as $char) {
        $value = strpos($alphabet, $char);
        if ($value === false) return '';
        $buffer .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
    }

    $raw = '';
    foreach (str_split($buffer, 8) as $chunk) {
        if (strlen($chunk) === 8) $raw .= chr(bindec($chunk));
    }
    return $raw;
}

function two_factor_code(?string $secret = null, ?int $timestamp = null): string
{
    $secret = (string) ($secret ?: '');
    $key = base32_decode_secret($secret);
    if ($key === '') {
        $digits = preg_replace('/\D+/', '', hash('sha256', ($secret ?: 'legacy') . date('YmdHi', $timestamp ?? time())));
        return substr($digits ?: '000000', 0, 6);
    }

    $counter = intdiv($timestamp ?? time(), 30);
    $binaryCounter = pack('N*', 0, $counter);
    $hash = hash_hmac('sha1', $binaryCounter, $key, true);
    $offset = ord(substr($hash, -1)) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function two_factor_verify(?string $secret, string $code): bool
{
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $now = time();
    foreach ([-30, 0, 30] as $offset) {
        if (hash_equals(two_factor_code($secret, $now + $offset), $code)) return true;
    }
    return false;
}
function encrypt_sensitive_note(?string $plain): ?string
{
    $plain = trim((string) $plain);
    if ($plain === '') return null;
    if (!function_exists('openssl_encrypt')) return base64_encode($plain);
    $key = hash('sha256', (string) (app_config('security.encryption_key') ?: app_config('db.password')), true);
    $iv = random_bytes(16);
    return base64_encode($iv . openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));
}

function decrypt_sensitive_note(?string $encoded): string
{
    if (!$encoded) return '';
    $raw = base64_decode($encoded, true);
    if ($raw === false) return '';
    if (!function_exists('openssl_decrypt') || strlen($raw) <= 16) return $raw;
    $key = hash('sha256', (string) (app_config('security.encryption_key') ?: app_config('db.password')), true);
    $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $plain === false ? '' : $plain;
}

function log_person_changes(int $personId, int $userId, array $old, array $new): void
{
    $insert = db()->prepare('INSERT INTO person_change_logs (person_id, user_id, field_name, old_value, new_value) VALUES (?, ?, ?, ?, ?)');
    foreach ($new as $field => $value) {
        $before = (string) ($old[$field] ?? '');
        $after = (string) ($value ?? '');
        if ($before !== $after) $insert->execute([$personId, $userId, $field, $before, $after]);
    }
}

function person_field_permission(array $user, string $field, string $capability): bool
{
    $row = fetch_one('SELECT can_view, can_edit FROM field_permissions WHERE role = ? AND field_name = ? LIMIT 1', [$user['role'], $field]);
    if (!$row) {
        return $capability === 'can_view';
    }

    return (bool) ($row[$capability] ?? false);
}

function can_view_person_field(array $user, string $field): bool
{
    return person_field_permission($user, $field, 'can_view');
}

function can_edit_person_field(array $user, string $field): bool
{
    return can_edit_group_data($user) && person_field_permission($user, $field, 'can_edit');
}

/**
 * Canonical marital status values used in person forms and validation.
 *
 * @return list<string>
 */
function marital_status_options(): array
{
    return ['женат', 'замужем', 'не женат', 'не замужем'];
}

/**
 * Options for a select, preserving an unknown stored value so it stays visible.
 *
 * @return list<string>
 */
function marital_status_choices(?string $current = null): array
{
    $options = marital_status_options();
    $current = trim((string) $current);
    if ($current !== '' && !in_array($current, $options, true)) {
        $options[] = $current;
    }

    return $options;
}

function marital_status_select_markup(string $name, ?string $selected = null): string
{
    $current = trim((string) $selected);
    $html = '<select name="' . e($name) . '"><option value="">—</option>';
    foreach (marital_status_choices($current) as $option) {
        $html .= '<option value="' . e($option) . '"' . ($current === $option ? ' selected' : '') . '>' . e($option) . '</option>';
    }
    $html .= '</select>';

    return $html;
}

function person_change_field_label(string $field): string
{
    return match ($field) {
        'phone' => 'Телефон',
        'telegram' => 'Telegram',
        'email' => 'Email',
        'address' => 'Адрес',
        'birth_date' => 'Дата рождения',
        'marital_status' => 'Семейное положение',
        'first_visit_date' => 'Первое посещение',
        'repentance_date' => 'Покаяние',
        'baptism_date' => 'Крещение',
        'membership_date' => 'Членство',
        'ministry' => 'Служение',
        'training_stage' => 'Этап обучения',
        'is_potential_leader' => 'Потенциальный лидер',
        'notes' => 'Заметки',
        'sensitive_notes_encrypted' => 'Особые заметки',
        'spiritual_stage_id' => 'Духовная воронка',
        'retention_until' => 'Хранение до',
        default => $field,
    };
}

function format_person_change_value(string $field, ?string $value, array $stageNames = []): string
{
    if ($field === 'sensitive_notes_encrypted') {
        return $value !== null && $value !== '' ? '[обновлено]' : '—';
    }
    if ($field === 'is_potential_leader') {
        return ((string) $value === '1') ? 'да' : 'нет';
    }
    if ($field === 'spiritual_stage_id') {
        $id = (int) $value;
        return $id > 0 ? ($stageNames[$id] ?? (string) $id) : '—';
    }

    $trimmed = trim((string) $value);
    return $trimmed !== '' ? $trimmed : '—';
}

/**
 * @return array{ok:true,value:?string}|array{ok:false,error:string}
 */
function parse_person_field_value(string $field, ?string $raw): array
{
    $value = trim((string) $raw);

    return match ($field) {
        'phone' => (static function () use ($value): array {
            if ($value === '' || $value === '+7' || $value === '+7 ') {
                return ['ok' => true, 'value' => null];
            }
            $normalized = ChurchCare\normalize_phone($value);
            if ($normalized === null) {
                return ['ok' => false, 'error' => 'Введите корректный телефон'];
            }
            return ['ok' => true, 'value' => $normalized];
        })(),
        'telegram' => (static function () use ($value): array {
            if ($value === '' || $value === '@') {
                return ['ok' => true, 'value' => null];
            }
            $normalized = ChurchCare\normalize_telegram($value);
            if ($normalized === null) {
                return ['ok' => false, 'error' => 'Введите корректный Telegram'];
            }
            return ['ok' => true, 'value' => $normalized];
        })(),
        'email' => (static function () use ($value): array {
            if ($value === '') {
                return ['ok' => true, 'value' => null];
            }
            $normalized = ChurchCare\normalize_email($value);
            if ($normalized === null) {
                return ['ok' => false, 'error' => 'Введите корректный email'];
            }
            return ['ok' => true, 'value' => $normalized];
        })(),
        'address' => ['ok' => true, 'value' => $value === '' ? null : mb_substr($value, 0, 255)],
        'birth_date', 'first_visit_date', 'repentance_date', 'baptism_date', 'membership_date' => (static function () use ($value, $field): array {
            if ($value === '') {
                return ['ok' => true, 'value' => null];
            }
            $label = person_change_field_label($field);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return ['ok' => false, 'error' => 'Введите корректную дату: ' . $label];
            }
            $parts = array_map('intval', explode('-', $value));
            if (!checkdate($parts[1], $parts[2], $parts[0])) {
                return ['ok' => false, 'error' => 'Введите корректную дату: ' . $label];
            }
            return ['ok' => true, 'value' => $value];
        })(),
        'marital_status' => (static function () use ($value): array {
            if ($value === '') {
                return ['ok' => true, 'value' => null];
            }
            if (!in_array($value, marital_status_options(), true)) {
                return ['ok' => false, 'error' => 'Выберите семейное положение из списка'];
            }

            return ['ok' => true, 'value' => $value];
        })(),
        'ministry' => ['ok' => true, 'value' => $value === '' ? null : mb_substr($value, 0, 160)],
        default => ['ok' => false, 'error' => 'Неизвестное поле'],
    };
}

function admin_page(array $user): void
{
    require_super_admin($user);
    page_header('Администрирование платформы', $user);
    $churches = list_churches_for_admin();
    $users = fetch_all('SELECT u.*, c.name AS church_name FROM users u LEFT JOIN churches c ON c.id = u.church_id AND c.deleted_at IS NULL ORDER BY u.role, u.name');
    $editId = (int) ($_GET['edit'] ?? 0);
    $editChurch = $editId > 0 ? find_church_by_id($editId) : null;

    echo '<section class="grid two"><article class="panel"><h2>Новая церковь</h2><form method="post" action="' . e(platform_path('/admin/church-create')) . '" class="stack">' . csrf_field() . '<label>Название<input name="name" required></label><label>Город<input name="city"></label><label>Slug<input name="slug" required pattern="[a-z0-9]+(?:-[a-z0-9]+)*" placeholder="pushkino"><span class="muted">Латиница, цифры и дефис. URL: /slug/…</span></label><label>Часовой пояс<input name="timezone" value="Europe/Moscow" placeholder="Europe/Moscow"></label><button class="primary">Создать церковь</button></form></article>';
    if ($editChurch) {
        echo '<article class="panel"><h2>Настройки: ' . e((string) $editChurch['name']) . '</h2><form method="post" action="' . e(platform_path('/admin/church-update')) . '" class="stack">' . csrf_field();
        echo '<input type="hidden" name="id" value="' . (int) $editChurch['id'] . '">';
        echo '<label>Название<input name="name" required value="' . e((string) $editChurch['name']) . '"></label>';
        echo '<label>Город<input name="city" value="' . e((string) ($editChurch['city'] ?? '')) . '"></label>';
        echo '<label>Slug<input name="slug" required pattern="[a-z0-9]+(?:-[a-z0-9]+)*" value="' . e((string) ($editChurch['slug'] ?? '')) . '"><span class="muted">URL: /' . e((string) ($editChurch['slug'] ?? '')) . '/…</span></label>';
        echo '<label>Часовой пояс<input name="timezone" value="' . e((string) ($editChurch['timezone'] ?? 'Europe/Moscow')) . '"></label>';
        echo '<label>Текст согласия<textarea name="personal_data_consent_text" rows="8">' . e(church_personal_data_consent_text((int) $editChurch['id'])) . '</textarea></label>';
        echo '<div class="quick-actions"><button class="primary">Сохранить настройки</button><a class="ghost" href="' . e(platform_path('/admin')) . '">Отмена</a></div></form></article>';
    } else {
        echo '<article class="panel"><h2>Редактирование</h2><p class="muted">Выберите «Изменить» в таблице церквей, чтобы поправить название, slug, часовой пояс и текст согласия. Для работы внутри CRM откройте «Настройки» у нужной церкви.</p></article>';
    }
    echo '</section>';

    render_admin_church_invites_panel();

    echo '<section class="panel"><h2>Церкви</h2><div class="table-wrap"><table><thead><tr><th>Название</th><th>Город</th><th>Slug</th><th>Группы</th><th>Пользователи</th><th></th></tr></thead><tbody>';
    foreach ($churches as $church) {
        $churchId = (int) $church['id'];
        $slug = (string) $church['slug'];
        $block = church_delete_block_reason($churchId);
        $confirm = 'Удалить церковь «' . $church['name'] . '» (/' . $slug . '/)? Группы будут закрыты, карточки людей скрыты. Действие для тенанта необратимо из интерфейса.';
        echo '<tr><td>' . e((string) $church['name']) . '</td><td>' . e((string) ($church['city'] ?? '')) . '</td><td><code>' . e($slug) . '</code></td>';
        echo '<td>' . (int) ($church['active_groups_count'] ?? 0) . ' / ' . (int) ($church['groups_count'] ?? 0) . '</td>';
        echo '<td>' . (int) ($church['users_count'] ?? 0) . '</td><td class="quick-actions">';
        echo '<a class="ghost" href="' . e('/' . $slug) . '">Открыть</a> ';
        echo '<a class="ghost" href="' . e('/' . $slug . '/church-settings') . '">Настройки CRM</a> ';
        echo '<a class="ghost" href="' . e(platform_path('/admin?edit=' . $churchId)) . '">Изменить</a> ';
        if ($block === null) {
            echo '<form method="post" action="' . e(platform_path('/admin/church-delete')) . '" class="compact" style="display:inline">' . csrf_field();
            echo '<input type="hidden" name="id" value="' . $churchId . '">';
            echo '<button type="submit" class="ghost" data-confirm="' . e($confirm) . '" data-confirm-value="' . e($slug) . '" data-confirm-prompt="' . e('Для подтверждения введите slug: ' . $slug) . '" data-confirm-field="confirm_slug">Удалить</button></form>';
        } else {
            echo '<span class="muted" title="' . e($block) . '">Удаление недоступно</span>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div></section>';

    $defaultLogo = trim((string) (app_setting('default_church_logo', '') ?: ''));
    echo '<section class="panel"><h2>Логотип по умолчанию</h2>';
    echo '<p class="muted">Используется на лендингах и в CRM церквей, у которых свой логотип ещё не задан. Встроенный знак платформы остаётся запасным вариантом.</p>';
    echo '<form method="post" action="' . e(platform_path('/admin/default-logo-save')) . '" class="stack" enctype="multipart/form-data">' . csrf_field();
    echo '<div class="landing-media-field">';
    echo '<div class="landing-media-field__row">';
    echo '<label class="landing-media-field__url">URL<input name="logo" value="' . e($defaultLogo) . '" placeholder="https://…" autocomplete="off" spellcheck="false" inputmode="url"></label>';
    echo '<label class="landing-media-field__file">Загрузить<input type="file" name="logo_file" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml,.svg"></label>';
    echo '</div>';
    echo '<p class="muted landing-media-field__hint">Вставьте ссылку на картинку или загрузите файл с устройства.</p>';
    if ($defaultLogo !== '') {
        $preview = preg_match('#^https?://#i', $defaultLogo) === 1
            ? $defaultLogo
            : brand_mark_img_src(null, $defaultLogo);
        echo '<p class="landing-media-field__preview"><img class="brand-mark" src="' . e($preview) . '" alt="" width="72" height="72"></p>';
    }
    echo '</div>';
    echo '<label><input type="checkbox" name="clear_logo" value="1"> Сбросить к встроенному логотипу</label>';
    echo '<div class="quick-actions"><button class="primary">Сохранить логотип по умолчанию</button></div></form></section>';

    echo '<section class="grid two"><article class="panel"><h2>Домашняя группа</h2><form method="post" action="' . e(platform_path('/admin/group-create')) . '" class="stack">' . csrf_field() . '<label>Церковь<select name="church_id">'; foreach ($churches as $church) echo '<option value="' . (int) $church['id'] . '">' . e($church['name']) . '</option>'; echo '</select></label><label>Название<input name="name" required></label><label>Адрес' . address_field_markup('address') . '</label><label>Вместимость<input name="max_people" type="number" min="1" value="16"></label><button class="primary">Создать группу</button></form></article>';
    echo '<article class="panel"><h2>Пользователь</h2><form method="post" action="' . e(platform_path('/admin/user-create')) . '" class="stack">' . csrf_field() . '<label>Имя<input name="name" required></label><label>Почта<input name="email" type="email" required></label><label>Пароль<input name="password" type="password" required></label><label>Роль<select name="role">'; foreach (roles() as $role) echo '<option value="' . e($role) . '">' . e(role_label($role)) . '</option>'; echo '</select></label><label>Церковь<select name="church_id"><option value="">—</option>'; foreach ($churches as $church) echo '<option value="' . (int) $church['id'] . '">' . e($church['name']) . '</option>'; echo '</select></label><button class="primary">Создать пользователя</button></form></article></section>';
    echo '<section class="panel"><h2>Пользователи</h2><div class="table-wrap"><table><thead><tr><th>Имя</th><th>Почта</th><th>Роль</th><th>Церковь</th><th>2FA</th><th></th></tr></thead><tbody>';
    foreach ($users as $row) {
        $userId = (int) $row['id'];
        echo '<tr data-user-row="' . $userId . '"><td><a href="' . e(user_card_href($userId, $user)) . '"><strong>' . e($row['name']) . '</strong></a></td><td>' . e($row['email']) . '</td><td>' . e(role_label($row['role'])) . '</td><td>' . e($row['church_name']) . '</td><td>' . ((int) $row['two_factor_enabled'] ? 'включена' : 'выключена') . '</td><td>';
        if (can_delete_user($user, $row)) {
            echo '<form method="post" action="' . e(platform_path('/user-delete')) . '" class="compact" data-user-delete>' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $row['id'] . '"><input type="hidden" name="return" value="admin"><button class="ghost" data-confirm="Удалить пользователя ' . e($row['name']) . '? Это действие нельзя отменить.">Удалить</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div></section>';
    page_footer();
}

function permissions_page(array $user): void
{
    require_super_admin($user);
    page_header('Права доступа', $user);
    echo '<form method="post" action="' . e(app_path('/permissions-save')) . '" class="panel stack">' . csrf_field() . '<div class="table-wrap"><table><thead><tr><th>Действие</th>'; foreach (roles() as $role) echo '<th>' . e(role_label($role)) . '</th>'; echo '</tr></thead><tbody>';
    foreach (permission_actions() as $action => $label) { echo '<tr><td><strong>' . e($label) . '</strong><br><span class="muted">' . e($action) . '</span></td>'; foreach (roles() as $role) echo '<td><label class="inline-check"><input type="checkbox" name="perm[' . e($role) . '][' . e($action) . ']" value="1"' . (can_action(['role' => $role], $action) ? ' checked' : '') . '></label></td>'; echo '</tr>'; }
    echo '</tbody></table></div><button class="primary">Сохранить права</button></form>'; page_footer();
}

function calendar_page(array $user): void
{
    redirect_member_to_own_card($user); page_header('Календарь встреч и контактов', $user);
    [$groupSql, $groupParams] = accessible_group_sql($user, 'g'); [$personSql, $personParams] = accessible_person_sql($user, 'p');
    $meetings = fetch_all("SELECT m.meeting_date AS event_date, m.topic, g.name AS label, CONCAT('/meeting?id=', m.id) AS url FROM meetings m JOIN home_groups g ON g.id = m.group_id WHERE $groupSql AND g.is_active = 1 AND m.meeting_date BETWEEN CURDATE() - INTERVAL 14 DAY AND CURDATE() + INTERVAL 60 DAY ORDER BY m.meeting_date", $groupParams);
    $contacts = fetch_all("SELECT cl.next_contact_at AS event_date, cl.summary AS topic, p.full_name AS label, CONCAT('/person?id=', p.id) AS url FROM contact_logs cl JOIN people p ON p.id = cl.person_id JOIN home_groups g ON g.id = p.group_id WHERE $personSql AND g.is_active = 1 AND cl.next_contact_at IS NOT NULL AND cl.next_contact_at BETWEEN CURDATE() AND CURDATE() + INTERVAL 60 DAY ORDER BY cl.next_contact_at", $personParams);
    foreach ($meetings as &$event) {
        $event['url'] = app_path((string) $event['url']);
    }
    unset($event);
    foreach ($contacts as &$event) {
        $event['url'] = app_path((string) $event['url']);
    }
    unset($event);
    echo '<section class="grid two"><article class="panel"><h2>Встречи</h2>'; foreach ($meetings as $event) echo '<a class="list-row" href="' . e($event['url']) . '"><strong>' . e($event['event_date'] . ' · ' . $event['label']) . '</strong><span>' . e($event['topic']) . '</span></a>'; if (!$meetings) echo '<p class="muted">Встреч не найдено.</p>'; echo '</article><article class="panel"><h2>Пастырские контакты</h2>'; foreach ($contacts as $event) echo '<a class="list-row" href="' . e($event['url']) . '"><strong>' . e($event['event_date'] . ' · ' . $event['label']) . '</strong><span>' . e($event['topic']) . '</span></a>'; if (!$contacts) echo '<p class="muted">Контактов нет.</p>'; echo '</article></section>'; page_footer();
}

function services_page(array $user): void
{
    require_action($user, 'manage_services'); page_header('Расписание служений', $user); [$groupSql, $params] = accessible_group_sql($user, 'g');
    $schedule = fetch_all("SELECT ss.*, sr.name AS role_name, p.full_name, m.meeting_date, m.topic, g.name AS group_name FROM service_schedule ss JOIN service_roles sr ON sr.id = ss.service_role_id JOIN people p ON p.id = ss.person_id JOIN meetings m ON m.id = ss.meeting_id JOIN home_groups g ON g.id = m.group_id WHERE $groupSql ORDER BY m.meeting_date DESC, sr.name", $params);
    $meetings = fetch_all("SELECT m.id, m.meeting_date, m.topic, g.name AS group_name FROM meetings m JOIN home_groups g ON g.id = m.group_id WHERE $groupSql AND g.is_active = 1 AND m.meeting_date >= CURDATE() ORDER BY m.meeting_date LIMIT 30", $params);
    [$personSql, $personParams] = accessible_person_sql($user); $people = fetch_all("SELECT p.id, p.full_name, g.name AS group_name FROM people p JOIN home_groups g ON g.id = p.group_id WHERE $personSql AND g.is_active = 1 ORDER BY g.name, p.full_name", $personParams); $roles = fetch_all('SELECT * FROM service_roles ORDER BY name');
    echo '<section class="grid two"><article class="panel"><h2>Назначить служение</h2><form method="post" action="' . e(app_path('/service-add')) . '" class="stack">' . csrf_field() . '<label>Встреча<select name="meeting_id">'; foreach ($meetings as $meeting) echo '<option value="' . (int) $meeting['id'] . '">' . e($meeting['meeting_date'] . ' · ' . $meeting['group_name']) . '</option>'; echo '</select></label><label>Человек<select name="person_id">'; foreach ($people as $person) echo '<option value="' . (int) $person['id'] . '">' . e($person['group_name'] . ' · ' . $person['full_name']) . '</option>'; echo '</select></label><label>Служение<select name="service_role_id">'; foreach ($roles as $role) echo '<option value="' . (int) $role['id'] . '">' . e($role['name']) . '</option>'; echo '</select></label><button class="primary">Назначить</button></form></article><article class="panel"><h2>Назначения</h2>'; foreach ($schedule as $item) echo '<div class="list-row"><strong>' . e($item['meeting_date'] . ' · ' . $item['role_name']) . '</strong><span>' . e($item['full_name'] . ' · ' . $item['group_name']) . '</span></div>'; echo '</article></section>'; page_footer();
}

function group_match_page(array $user): void
{
    redirect_member_to_own_card($user);
    page_header('Подбор подходящей группы', $user);
    $query = trim((string) ($_GET['q'] ?? $_GET['district'] ?? ''));
    [$groupSql, $params] = accessible_group_sql($user, 'g');
    $groups = fetch_all("SELECT g.*, COUNT(p.id) AS people_count FROM home_groups g LEFT JOIN people p ON p.group_id = g.id AND p.deleted_at IS NULL WHERE $groupSql AND g.is_active = 1 GROUP BY g.id", $params);
    foreach ($groups as &$group) {
        $free = max(0, (int) $group['max_people'] - (int) $group['people_count']);
        $group['score'] = (ChurchCare\home_group_matches_location_query($group, $query) ? 40 : 0) + min(40, $free * 4) + 20;
    }
    unset($group);
    usort($groups, static fn($a, $b) => $b['score'] <=> $a['score']);
    echo '<section class="panel"><form method="get" action="' . e(app_path('/match')) . '" class="form-grid"><label>Адрес<input name="q" value="' . e($query) . '" placeholder="Митино, Пушкино..."></label><label>&nbsp;<button class="primary">Подобрать</button></label></form></section><section class="grid three">';
    foreach ($groups as $group) {
        $location = ChurchCare\home_group_short_address($group);
        $schedule = ChurchCare\home_group_schedule_label($group) ?: (string) ($group['weekday'] ?? '');
        $meta = $location !== '' && $schedule !== '' ? $location . ' · ' . $schedule : ($location !== '' ? $location : $schedule);
        echo '<article class="panel group-card"><p class="eyebrow">Совпадение ' . (int) $group['score'] . '%</p><h2>' . e($group['name']) . '</h2><p>' . e($meta) . '</p><p class="muted">' . e((string) ($group['address'] ?? '')) . '</p><p>' . (int) $group['people_count'] . ' из ' . (int) $group['max_people'] . '</p><a class="primary" href="' . e(app_path('/group?id=' . (int) $group['id'])) . '">Открыть</a></article>';
    }
    echo '</section>';
    page_footer();
}

function group_map_page(array $user): void
{
    redirect_member_to_own_card($user);
    redirect(public_homegroup_catalog_path(current_church()));
}

function prayers_page(array $user): void
{
    require_action($user, 'manage_prayers');
    page_header('Молитвенные нужды', $user);
    $status = trim((string) ($_GET['status'] ?? ''));
    [$personSql, $params] = accessible_person_sql($user, 'p');
    $churchId = (int) (effective_scope_church_id($user) ?? 0);
    [$orphanChurchSql, $orphanChurchParams] = church_id_scope_sql('pr.church_id', $churchId > 0 ? $churchId : null);
    $params = array_merge($params, $orphanChurchParams);
    if ($status !== '') {
        $params[] = $status;
    }
    $rows = fetch_all(
        "SELECT pr.*, p.full_name, g.name AS group_name, m.id AS miracle_id
         FROM prayer_requests pr
         LEFT JOIN people p ON p.id = pr.person_id
         LEFT JOIN home_groups g ON g.id = pr.group_id
         LEFT JOIN miracles m ON m.prayer_request_id = pr.id
         WHERE (($personSql) OR (pr.person_id IS NULL AND pr.group_id IS NULL AND $orphanChurchSql))"
            . ($status !== '' ? ' AND pr.status = ?' : '')
            . " ORDER BY FIELD(pr.status, 'new','praying','testimony','closed'), pr.next_reminder_at IS NULL, pr.next_reminder_at",
        $params
    );
    $statusLabels = ChurchCare\prayer_status_labels();
    echo '<div class="page-actions"><a class="ghost" href="' . e(public_prayer_request_path()) . '" target="_blank" rel="noopener">Публичная форма нужды</a></div>';
    echo '<section class="panel"><p class="muted">Когда Господь ответил на нужду — поставьте статус «Свидетельство» и расскажите чудо. Оно появится в разделе <a href="' . e(app_path('/miracles')) . '">Чудеса</a>.</p></section>';
    echo '<section class="panel"><div class="table-wrap"><table><thead><tr><th>Нужда</th><th>Человек</th><th>Группа</th><th>Статус</th><th>Действие</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $current = (string) ($row['status'] ?? '');
        $miracleId = (int) ($row['miracle_id'] ?? 0);
        echo '<tr><td><strong>' . e($row['title']) . '</strong><br><span class="muted">' . e($row['description']) . '</span>';
        if ($miracleId > 0) {
            echo '<br><a class="ghost" href="' . e(app_path('/miracle?id=' . $miracleId)) . '">Открыть чудо</a>';
        }
        echo '</td>';
        echo '<td>' . e(ChurchCare\prayer_submitter_label($row)) . '</td><td>' . e($row['group_name']) . '</td>';
        echo '<td>' . e(ChurchCare\prayer_status_label($current)) . '</td><td>';
        echo '<form method="post" action="' . e(app_path('/prayer-update')) . '" class="stack compact prayer-status-form">' . csrf_field();
        echo '<input type="hidden" name="id" value="' . (int) $row['id'] . '"><label>Статус<select name="status" data-prayer-status>';
        foreach ($statusLabels as $value => $label) {
            echo '<option value="' . e($value) . '"' . ($current === $value ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        echo '</select></label>';
        echo '<div class="prayer-testimony-fields" data-prayer-testimony-fields' . ($current === 'testimony' ? '' : ' hidden') . '>';
        echo '<label>Свидетельство о чуде<textarea name="miracle_body" rows="3" placeholder="' . e($miracleId > 0 ? 'Оставьте пустым, чтобы не менять текст чуда' : 'Как Господь ответил на эту нужду?') . '"></textarea></label>';
        echo '<label class="checkbox-row"><input type="checkbox" name="miracle_anonymous" value="1"> Опубликовать анонимно</label>';
        if ($miracleId <= 0) {
            echo '<p class="muted">Обязательно при переводе в «Свидетельство» — история появится в разделе Чудеса.</p>';
        } else {
            echo '<p class="muted">Можно уточнить текст свидетельства — карточка чуда обновится.</p>';
        }
        echo '</div>';
        echo '<button class="ghost">Сохранить</button></form></td></tr>';
    }
    echo '</tbody></table></div></section>';
    page_footer();
}

function newcomers_page(array $user): void
{
    redirect_member_to_own_card($user); page_header('Новички и удержание', $user); [$personSql, $params] = accessible_person_sql($user); $rows = fetch_all("SELECT p.*, g.name AS group_name, MAX(cl.contact_date) AS last_contact FROM people p JOIN home_groups g ON g.id = p.group_id LEFT JOIN contact_logs cl ON cl.person_id = p.id WHERE $personSql AND g.is_active = 1 AND p.first_visit_date >= CURDATE() - INTERVAL 90 DAY GROUP BY p.id ORDER BY p.first_visit_date DESC", $params);
    echo '<section class="grid three">'; foreach ($rows as $row) echo '<article class="panel"><p class="eyebrow">Первое посещение ' . e($row['first_visit_date']) . '</p><h2>' . e($row['full_name']) . '</h2><p>' . e($row['group_name']) . '</p><p class="muted">Последний контакт: ' . e($row['last_contact'] ?: 'нет') . '</p><a class="primary" href="' . e(app_path('/person?id=' . (int) $row['id'])) . '">Карточка</a></article>'; echo '</section>'; page_footer();
}

function regional_page(array $user): void
{
    redirect_member_to_own_card($user);
    page_header('Экран окружного лидера', $user);
    [$groupSql, $params] = accessible_group_sql($user, 'g');
    if (function_exists('districts_table_ready') && districts_table_ready()) {
        $rows = fetch_all(
            "SELECT g.id, g.name, c.name AS church_name, d.name AS district_name, COUNT(DISTINCT p.id) AS people_count,
                    COUNT(DISTINCT CASE WHEN p.is_potential_leader = 1 THEN p.id END) AS future_leaders,
                    COUNT(DISTINCT CASE WHEN m.pastor_help_needed = 1 AND m.meeting_date >= CURDATE() - INTERVAL 30 DAY THEN m.id END) AS help_flags,
                    COUNT(DISTINCT CASE WHEN m.meeting_date >= CURDATE() - INTERVAL 30 DAY THEN m.id END) AS meetings_count
             FROM home_groups g
             JOIN churches c ON c.id = g.church_id
             LEFT JOIN districts d ON d.id = g.district_id
             LEFT JOIN people p ON p.group_id = g.id AND p.deleted_at IS NULL
             LEFT JOIN meetings m ON m.group_id = g.id
             WHERE $groupSql AND g.is_active = 1
             GROUP BY g.id
             ORDER BY help_flags DESC, meetings_count ASC, g.name",
            $params
        );
    } else {
        $rows = fetch_all(
            "SELECT g.id, g.name, c.name AS church_name, NULL AS district_name, COUNT(DISTINCT p.id) AS people_count,
                    COUNT(DISTINCT CASE WHEN p.is_potential_leader = 1 THEN p.id END) AS future_leaders,
                    COUNT(DISTINCT CASE WHEN m.pastor_help_needed = 1 AND m.meeting_date >= CURDATE() - INTERVAL 30 DAY THEN m.id END) AS help_flags,
                    COUNT(DISTINCT CASE WHEN m.meeting_date >= CURDATE() - INTERVAL 30 DAY THEN m.id END) AS meetings_count
             FROM home_groups g
             JOIN churches c ON c.id = g.church_id
             LEFT JOIN people p ON p.group_id = g.id AND p.deleted_at IS NULL
             LEFT JOIN meetings m ON m.group_id = g.id
             WHERE $groupSql AND g.is_active = 1
             GROUP BY g.id
             ORDER BY help_flags DESC, meetings_count ASC, g.name",
            $params
        );
    }
    echo '<section class="panel"><div class="table-wrap"><table><thead><tr><th>Группа</th><th>Округ</th><th>Церковь</th><th>Людей</th><th>Встреч за 30 дней</th><th>Будущие лидеры</th><th>Просьбы о помощи</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td><a href="' . e(app_path('/group?id=' . (int) $row['id'])) . '">' . e($row['name']) . '</a></td><td>' . e((string) ($row['district_name'] ?: '—')) . '</td><td>' . e($row['church_name']) . '</td><td>' . (int) $row['people_count'] . '</td><td>' . (int) $row['meetings_count'] . '</td><td>' . (int) $row['future_leaders'] . '</td><td>' . (int) $row['help_flags'] . '</td></tr>';
    }
    echo '</tbody></table></div></section>';
    page_footer();
}

function church_analytics_page(array $user): void
{
    require_action($user, 'view_church_analytics');
    page_header('Церковная аналитика за квартал и год', $user);
    $stats = church_analytics_stats($user);
    echo '<section class="metric-grid">';
    foreach (
        [
            ['Активные группы', $stats['active_groups']],
            ['Новые группы за год', $stats['opened_year']],
            ['Закрытые группы за год', $stats['closed_year']],
            ['Крещения за год', $stats['baptisms_year']],
            ['Новички за квартал', $stats['newcomers_quarter']],
        ] as [$label, $value]
    ) {
        echo '<article class="metric"><span>' . (int) $value . '</span><p>' . e($label) . '</p></article>';
    }
    echo '</section>';
    page_footer();
}

function courses_page(array $user): void
{
    page_header('Учебные курсы и духовная воронка', $user);
    $scopeChurchId = (int) (effective_scope_church_id($user) ?? ($user['church_id'] ?? 0));
    $stages = fetch_all('SELECT * FROM spiritual_stages WHERE church_id = ? ORDER BY sort_order, name', [$scopeChurchId]);
    $courses = fetch_all('SELECT c.*, s.name AS stage_name FROM courses c LEFT JOIN spiritual_stages s ON s.id = c.required_stage_id WHERE c.church_id = ? ORDER BY c.sort_order, c.title', [$scopeChurchId]);
    $person = fetch_one('SELECT id FROM people WHERE user_id = ? AND deleted_at IS NULL LIMIT 1', [$user['id']]);
    echo '<section class="panel"><h2>Настраиваемые этапы</h2><div class="stage-strip">'; foreach ($stages as $stage) echo '<span>' . e($stage['name']) . '</span>'; echo '</div></section><section class="grid two">'; foreach ($courses as $course) { $lessons = fetch_all('SELECT l.*, cp.status FROM course_lessons l LEFT JOIN course_progress cp ON cp.lesson_id = l.id AND cp.person_id = ? WHERE l.course_id = ? ORDER BY l.sort_order', [$person['id'] ?? 0, $course['id']]); echo '<article class="panel"><p class="eyebrow">' . e($course['stage_name'] ?: 'открытый доступ') . '</p><h2>' . e($course['title']) . '</h2>'; foreach ($lessons as $lesson) echo '<form method="post" action="' . e(app_path('/course-pass')) . '" class="lesson-row">' . csrf_field() . '<input type="hidden" name="lesson_id" value="' . (int) $lesson['id'] . '"><strong>' . e($lesson['title']) . '</strong><span>' . e($lesson['status'] === 'passed' ? 'пройден' : 'открыт') . '</span><button class="ghost">Пройти</button></form>'; echo '</article>'; } echo '</section>'; page_footer();
}

function import_page(array $user): void
{
    require_action($user, 'import_data'); page_header('Импорт из CSV', $user); echo '<section class="panel"><h2>Импорт людей</h2><p class="muted">Формат: full_name,phone,telegram,address,group_id,first_visit_date. Телефон лучше в виде +7 977 818-97-73 или +79778189773, Telegram — @username или username.</p><form method="post" action="' . e(app_path('/import-people')) . '" class="stack">' . csrf_field() . '<label>CSV<textarea name="csv" rows="12" required>full_name,phone,telegram,address,group_id,first_visit_date</textarea></label><button class="primary">Импортировать</button></form></section>'; page_footer();
}

function privacy_page(array $user): void
{
    require_action($user, 'delete_people');
    page_header('Удаление персональных данных', $user);
    [$personSql, $params] = accessible_person_sql($user);
    $people = fetch_all("SELECT p.id, p.full_name, g.name AS group_name FROM people p JOIN home_groups g ON g.id = p.group_id WHERE $personSql AND g.is_active = 1 ORDER BY p.full_name", $params);
    $requests = fetch_all("SELECT d.*, p.full_name FROM data_deletion_requests d JOIN people p ON p.id = d.person_id JOIN home_groups g ON g.id = p.group_id WHERE $personSql AND g.is_active = 1 ORDER BY d.created_at DESC LIMIT 30", $params);
    echo '<section class="grid two"><article class="panel"><h2>Новый запрос</h2><form method="post" action="' . e(app_path('/deletion-request')) . '" class="stack">' . csrf_field() . '<label>Человек<select name="person_id">'; foreach ($people as $person) echo '<option value="' . (int) $person['id'] . '">' . e($person['full_name'] . ' · ' . $person['group_name']) . '</option>'; echo '</select></label><label>Причина<input name="reason" required></label><button class="primary">Создать запрос</button></form></article><article class="panel"><h2>Запросы</h2>'; foreach ($requests as $request) echo '<form method="post" action="' . e(app_path('/deletion-complete')) . '" class="list-row">' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $request['id'] . '"><div><strong>' . e($request['full_name']) . '</strong><span>' . e($request['status'] . ' · ' . $request['reason']) . '</span></div><button class="ghost" data-confirm="Удалить персональные данные?">Выполнить</button></form>'; echo '</article></section>'; page_footer();
}
function meeting_report_page(array $user): void
{
    require_action($user, 'edit_people');
    $id = (int) ($_GET['id'] ?? 0);
    $meeting = scoped_meeting($user, $id);
    if (!$meeting) { http_response_code(404); exit('Meeting not found'); }
    $report = fetch_one('SELECT * FROM meeting_reports WHERE meeting_id = ?', [$id]);
    $counts = fetch_one('SELECT SUM(status IN ("present","new")) AS present_count, SUM(status = "new") AS newcomers_count FROM attendance WHERE meeting_id = ?', [$id]) ?: [];
    page_header('Отчет после встречи', $user); echo '<section class="panel"><p class="eyebrow">' . e($meeting['meeting_date'] . ' · ' . ($meeting['group_name'] ?? '')) . '</p><form method="post" action="' . e(app_path('/meeting-report-save')) . '" class="stack">' . csrf_field() . '<input type="hidden" name="meeting_id" value="' . $id . '"><div class="form-grid"><label>Присутствовали<input name="attendance_count" type="number" value="' . (int) ($report['attendance_count'] ?? $counts['present_count'] ?? 0) . '"></label><label>Новые<input name="newcomers_count" type="number" value="' . (int) ($report['newcomers_count'] ?? $counts['newcomers_count'] ?? 0) . '"></label><label>Нужна помощь<select name="pastor_help_needed"><option value="0">Нет</option><option value="1"' . ((int) ($report['pastor_help_needed'] ?? $meeting['pastor_help_needed']) ? ' selected' : '') . '>Да</option></select></label></div><label>Отчет<textarea name="summary" rows="6" required>' . e($report['summary'] ?? $meeting['notes'] ?? '') . '</textarea></label><button class="primary">Сохранить и отправить</button></form></section>'; page_footer();
}
function handle_feature_post(string $path, string $method, array $user): void
{
    if ($method !== 'POST') return;
    if (str_starts_with($path, '/admin/')) {
        verify_csrf();
        if (!is_super_admin($user)) {
            ajax_redirect('/admin', 'Доступ только для суперадминистратора');
        }
        if ($path === '/admin/church-create') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $city = trim((string) ($_POST['city'] ?? '')) ?: null;
            $slugInput = trim((string) ($_POST['slug'] ?? ''));
            if ($name === '') {
                ajax_redirect('/admin', 'Укажите название церкви');
            }
            $slugBase = $slugInput !== '' ? $slugInput : (string) ($city ?: $name);
            $slug = unique_church_slug($slugBase);
            if (!church_slug_is_valid($slug)) {
                ajax_redirect('/admin', 'Некорректный slug');
            }
            $timezone = normalize_church_timezone((string) ($_POST['timezone'] ?? 'Europe/Moscow'));
            db()->prepare('INSERT INTO churches (name, city, slug, timezone) VALUES (?, ?, ?, ?)')
                ->execute([$name, $city, $slug, $timezone]);
            audit('church_create', 'church', (int) db()->lastInsertId(), ['slug' => $slug]);
            ajax_redirect('/admin', 'Церковь создана');
        }
        if ($path === '/admin/church-invite-create') {
            if (!church_invite_storage_ready()) {
                ajax_redirect('/admin', 'Примените миграцию church_invitations');
            }
            try {
                $invite = create_church_invite((int) $user['id'], trim((string) ($_POST['note'] ?? '')) ?: null);
            } catch (Throwable $e) {
                ajax_redirect('/admin', 'Не удалось создать ссылку');
            }
            audit('church_invite_created', 'church_invitation', (int) $invite['id'], [
                'permanent' => true,
            ]);
            ajax_redirect('/admin', 'Постоянная ссылка для пастора создана. Скопируйте её из списка активных приглашений.');
        }
        if ($path === '/admin/church-invite-revoke') {
            $inviteId = (int) ($_POST['id'] ?? 0);
            if (!revoke_church_invite($inviteId, (int) $user['id'])) {
                ajax_redirect('/admin', 'Не удалось отозвать ссылку');
            }
            ajax_redirect('/admin', 'Ссылка отозвана');
        }
        if ($path === '/admin/church-update') {
            $churchId = (int) ($_POST['id'] ?? 0);
            $result = save_church_settings($churchId, [
                'name' => $_POST['name'] ?? '',
                'city' => $_POST['city'] ?? '',
                'slug' => $_POST['slug'] ?? '',
                'timezone' => $_POST['timezone'] ?? 'Europe/Moscow',
                'personal_data_consent_text' => $_POST['personal_data_consent_text'] ?? null,
            ], true);
            if (!$result['ok']) {
                ajax_redirect('/admin?edit=' . $churchId, $result['error']);
            }
            audit('church_settings_save', 'church', $churchId, ['via' => 'admin']);
            ajax_redirect('/admin?edit=' . $churchId, 'Настройки церкви сохранены');
        }
        if ($path === '/admin/church-delete') {
            $churchId = (int) ($_POST['id'] ?? 0);
            $church = find_church_by_id($churchId, true);
            if (!$church || church_is_deleted($church)) {
                ajax_redirect('/admin', 'Церковь не найдена');
            }
            $confirmSlug = strtolower(trim((string) ($_POST['confirm_slug'] ?? '')));
            $expectedSlug = strtolower((string) ($church['slug'] ?? ''));
            if ($confirmSlug === '' || $confirmSlug !== $expectedSlug) {
                ajax_redirect('/admin', 'Для удаления введите точный slug церкви');
            }
            $result = soft_delete_church($churchId);
            if (!$result['ok']) {
                ajax_redirect('/admin', $result['error']);
            }
            audit('church_delete', 'church', $churchId, [
                'slug' => $expectedSlug,
                'name' => $church['name'] ?? null,
                'counts' => $result['counts'],
            ]);
            ajax_redirect('/admin', 'Церковь удалена');
        }
        if ($path === '/admin/default-logo-save') {
            if (!empty($_POST['clear_logo'])) {
                save_app_setting('default_church_logo', '', false, (int) $user['id']);
                audit('default_church_logo_clear', 'app_setting', null);
                ajax_redirect('/admin', 'Логотип по умолчанию сброшен');
            }
            $uploaded = isset($_FILES['logo_file']) && is_array($_FILES['logo_file']) ? $_FILES['logo_file'] : null;
            $pathValue = trim((string) ($_POST['logo'] ?? ''));
            if ($uploaded && (int) ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $stored = church_landing_store_uploaded_image($uploaded);
                if (!$stored['ok']) {
                    ajax_redirect('/admin', $stored['error']);
                }
                $pathValue = $stored['path'];
            }
            $normalized = $pathValue !== '' ? church_landing_normalize_media_src($pathValue) : null;
            if ($pathValue !== '' && $normalized === null) {
                ajax_redirect('/admin', 'Укажите корректный URL или загрузите файл');
            }
            save_app_setting('default_church_logo', (string) ($normalized ?? ''), false, (int) $user['id']);
            audit('default_church_logo_save', 'app_setting', null, ['path' => $normalized]);
            ajax_redirect('/admin', 'Логотип по умолчанию сохранён');
        }
        if ($path === '/admin/group-create') {
            $churchId = (int) ($_POST['church_id'] ?? $user['church_id'] ?? 0);
            if ($churchId <= 0 || !find_church_by_id($churchId)) {
                ajax_redirect('/admin', 'Церковь не указана');
            }
            $location = resolve_home_group_location_from_post();
            db()->prepare('INSERT INTO home_groups (church_id, name, address, latitude, longitude, max_people, opened_at, is_active) VALUES (?, ?, ?, ?, ?, ?, CURDATE(), 1)')
                ->execute([
                    $churchId,
                    trim((string) $_POST['name']),
                    $location['address'],
                    $location['latitude'],
                    $location['longitude'],
                    max(1, (int) $_POST['max_people']),
                ]);
            audit('admin_group_create', 'group', (int) db()->lastInsertId());
            ajax_redirect('/admin', 'Группа создана');
        }
        if ($path === '/admin/user-create') {
            $role = in_array($_POST['role'] ?? 'member', roles(), true) ? (string) $_POST['role'] : 'member';
            $email = function_exists('normalize_account_email')
                ? normalize_account_email((string) $_POST['email'])
                : mb_strtolower(trim((string) $_POST['email']));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                ajax_redirect('/admin', 'Укажите корректный email');
            }
            $login = unique_user_login(login_from_email($email));
            $churchId = (int) ($_POST['church_id'] ?? 0);
            if ($role === 'super_admin') {
                $churchId = $churchId > 0 ? $churchId : ((int) ($user['church_id'] ?? 0) ?: null);
            } elseif ($churchId <= 0 || !find_church_by_id($churchId)) {
                ajax_redirect('/admin', 'Укажите церковь для пользователя');
            }
            $churchScope = is_int($churchId) && $churchId > 0 ? $churchId : null;
            if (user_email_taken_in_church($email, $churchScope)) {
                ajax_redirect('/admin', 'Почта уже используется другим пользователем в этой церкви');
            }
            db()->prepare('INSERT INTO users (name, login, email, password_hash, role, church_id, group_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    trim((string) $_POST['name']),
                    $login,
                    $email,
                    password_hash((string) $_POST['password'], PASSWORD_DEFAULT),
                    $role,
                    $churchId ?: null,
                    null,
                ]);
            audit('admin_user_create', 'user', (int) db()->lastInsertId());
            ajax_redirect('/admin', 'Пользователь создан');
        }
        ajax_redirect('/admin', 'Неизвестное действие');
    }

    if ($path === '/knowledge-image-upload') {
        verify_csrf();
        if (!can_manage_training($user)) {
            json_response(['ok' => false, 'error' => 'Недостаточно прав'], 403);
        }
        $file = $_FILES['image'] ?? null;
        if (!is_array($file)) {
            json_response(['ok' => false, 'error' => 'Файл не выбран'], 422);
        }
        $stored = knowledge_store_uploaded_image($file);
        if (!$stored['ok']) {
            json_response(['ok' => false, 'error' => $stored['error']], 422);
        }
        audit('knowledge_image_upload', 'knowledge_image', null, ['path' => $stored['path']]);
        json_response([
            'ok' => true,
            'path' => $stored['path'],
            'markdown' => knowledge_markdown_for_path($stored['path']),
            'message' => 'Картинка загружена',
        ]);
    }

    if ($path === '/knowledge-save') {
        verify_csrf();
        if (!can_manage_training($user)) ajax_redirect('/knowledge', 'Недостаточно прав');
        $id = (int) ($_POST['id'] ?? 0);
        $type = in_array($_POST['type'] ?? 'training', ['training', 'faq', 'article', 'icebreaker', 'template'], true) ? (string) $_POST['type'] : 'training';
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $sort = (int) ($_POST['sort_order'] ?? 0);
        if ($title === '' || $body === '') ajax_redirect('/knowledge?edit=1', 'Заполните название и текст материала');

        $currentPath = null;
        if ($id > 0) {
            $existing = fetch_one('SELECT id, image_path FROM knowledge_items WHERE id = ? LIMIT 1', [$id]);
            if (!$existing) {
                ajax_redirect('/knowledge?edit=1', 'Материал не найден');
            }
            $currentPath = $existing['image_path'] ?? null;
        }

        $uploaded = isset($_FILES['image']) && is_array($_FILES['image']) ? $_FILES['image'] : null;
        $removeRequested = isset($_POST['remove_image']) && (string) $_POST['remove_image'] === '1';
        $imageResult = knowledge_resolve_item_image($currentPath, $uploaded, $removeRequested);
        if (!$imageResult['ok']) {
            ajax_redirect('/knowledge?edit=1', $imageResult['error']);
        }
        $imagePath = $imageResult['path'];

        if ($id > 0) {
            db()->prepare('UPDATE knowledge_items SET type = ?, title = ?, body = ?, image_path = ?, sort_order = ? WHERE id = ?')
                ->execute([$type, $title, $body, $imagePath, $sort, $id]);
            audit('knowledge_update', 'knowledge_item', $id, ['image_changed' => $imageResult['replaced'] || $removeRequested]);
            ajax_redirect('/knowledge?edit=1', 'Материал обновлен');
        }
        db()->prepare('INSERT INTO knowledge_items (type, title, body, image_path, sort_order) VALUES (?, ?, ?, ?, ?)')
            ->execute([$type, $title, $body, $imagePath, $sort]);
        audit('knowledge_create', 'knowledge_item', (int) db()->lastInsertId());
        ajax_redirect('/knowledge?edit=1', 'Материал добавлен');
    }

    if ($path === '/permissions-save') { verify_csrf(); require_super_admin($user); $stmt = db()->prepare('INSERT INTO role_permissions (role, action, allowed) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)'); foreach (roles() as $role) foreach (array_keys(permission_actions()) as $action) $stmt->execute([$role, $action, isset($_POST['perm'][$role][$action]) ? 1 : 0]); audit('permissions_save'); ajax_redirect('/permissions', 'Права сохранены'); }
    if ($path === '/settings-security') {
        verify_csrf();
        require_super_admin($user);
        $subject = normalize_vapid_subject((string) ($_POST['vapid_subject'] ?? ''));
        if (trim((string) ($_POST['vapid_subject'] ?? '')) !== '' && $subject === '') {
            ajax_redirect('/security', 'Контакт отправителя должен быть email или URL сайта (https://...)');
        }
        save_app_setting('vapid_public_key', trim((string) $_POST['vapid_public_key']), false, (int) $user['id']);
        save_app_setting('vapid_private_key', trim((string) $_POST['vapid_private_key']), true, (int) $user['id']);
        save_app_setting('vapid_subject', $subject, false, (int) $user['id']);
        audit('security_settings_save');
        ajax_redirect('/security', 'Настройки сохранены');
    }
    if ($path === '/vapid-generate') {
        verify_csrf();
        require_super_admin($user);
        try {
            $keys = generate_vapid_keys();
        } catch (Throwable $e) {
            ajax_redirect('/security', 'Не удалось сгенерировать VAPID-ключи: ' . $e->getMessage());
        }
        save_app_setting('vapid_public_key', $keys['publicKey'], false, (int) $user['id']);
        save_app_setting('vapid_private_key', $keys['privateKey'], true, (int) $user['id']);
        audit('vapid_generate');
        ajax_redirect('/security', 'Ключи сгенерированы. Укажите контакт отправителя и сохраните настройки.');
    }
    if ($path === '/two-factor-toggle') { verify_csrf(); $enabled = isset($_POST['enabled']) ? 1 : 0; $postedSecret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', (string) ($_POST['setup_secret'] ?? ''))); $secret = $enabled ? ($postedSecret !== '' && base32_decode_secret($postedSecret) !== '' ? $postedSecret : two_factor_secret()) : null; db()->prepare('UPDATE users SET two_factor_enabled = ?, two_factor_secret = ? WHERE id = ?')->execute([$enabled, $secret, $user['id']]); audit($enabled ? '2fa_enable' : '2fa_disable', 'user', (int) $user['id']); ajax_redirect('/profile', $enabled ? '2FA включена. Проверьте, что ключ сохранен в приложении-аутентификаторе.' : '2FA выключена'); }
    if ($path === '/person-field-update') {
        verify_csrf();
        require_action($user, 'edit_people');
        $id = (int) ($_POST['id'] ?? 0);
        $field = (string) ($_POST['field'] ?? '');
        $allowed = ['phone', 'telegram', 'email', 'address', 'birth_date', 'marital_status', 'first_visit_date', 'repentance_date', 'baptism_date', 'membership_date', 'ministry'];
        if (!in_array($field, $allowed, true)) {
            json_response(['ok' => false, 'error' => 'Неизвестное поле'], 422);
        }
        $person = scoped_person($user, $id);
        if (!$person) {
            json_response(['ok' => false, 'error' => 'Карточка не найдена или недоступна'], 404);
        }
        $parsed = parse_person_field_value($field, $_POST['value'] ?? null);
        if (!$parsed['ok']) {
            json_response(['ok' => false, 'error' => $parsed['error']], 422);
        }
        $old = fetch_one('SELECT ' . $field . ' FROM people WHERE id = ?', [$id]) ?: [];
        db()->prepare('UPDATE people SET ' . $field . ' = ? WHERE id = ?')->execute([$parsed['value'], $id]);
        log_person_changes($id, (int) $user['id'], $old, [$field => $parsed['value']]);
        audit('person_field_update', 'person', $id);
        $meta = person_field_presentation($field, $parsed['value']);
        json_response([
            'ok' => true,
            'message' => 'Сохранено',
            'field' => $field,
            'display' => $meta['display'],
            'edit_value' => $meta['edit_value'],
            'href' => $meta['href'],
        ]);
    }
    if ($path === '/person-update') {
        verify_csrf();
        require_action($user, 'edit_people');
        $id = (int) $_POST['id'];
        $person = scoped_person($user, $id);
        if (!$person) ajax_redirect('/care', 'Карточка не найдена или недоступна');
        $old = fetch_one(
            'SELECT phone, telegram, birth_date, marital_status, first_visit_date, repentance_date, baptism_date, membership_date, ministry, training_stage, is_potential_leader, notes, sensitive_notes_encrypted, spiritual_stage_id, retention_until FROM people WHERE id = ?',
            [$id]
        ) ?: [];
        if (array_key_exists('sensitive_notes', $_POST) && can_edit_person_field($user, 'sensitive_notes')) {
            $encrypted = trim((string) $_POST['sensitive_notes']) !== '' ? encrypt_sensitive_note((string) $_POST['sensitive_notes']) : null;
        } else {
            $encrypted = $old['sensitive_notes_encrypted'] ?? null;
        }
        $phone = array_key_exists('phone', $_POST) ? ChurchCare\normalize_phone($_POST['phone'] ?? null) : ($old['phone'] ?? null);
        $telegram = array_key_exists('telegram', $_POST) ? ChurchCare\normalize_telegram($_POST['telegram'] ?? null) : ($old['telegram'] ?? null);
        $dateFields = ['birth_date', 'first_visit_date', 'repentance_date', 'baptism_date', 'membership_date'];
        $dates = [];
        foreach ($dateFields as $dateField) {
            if (!array_key_exists($dateField, $_POST)) {
                $dates[$dateField] = $old[$dateField] ?? null;
                continue;
            }
            $raw = trim((string) ($_POST[$dateField] ?? ''));
            $dates[$dateField] = $raw !== '' ? $raw : null;
        }
        if (array_key_exists('marital_status', $_POST)) {
            $maritalRaw = trim((string) ($_POST['marital_status'] ?? ''));
            $oldMarital = trim((string) ($old['marital_status'] ?? ''));
            if ($maritalRaw === '') {
                $maritalStatus = null;
            } elseif (in_array($maritalRaw, marital_status_options(), true) || $maritalRaw === $oldMarital) {
                $maritalStatus = $maritalRaw;
            } else {
                ajax_redirect('/person?id=' . $id, 'Выберите семейное положение из списка');
            }
        } else {
            $maritalStatus = $old['marital_status'] ?? null;
        }
        $new = [
            'phone' => $phone,
            'telegram' => $telegram,
            'birth_date' => $dates['birth_date'],
            'marital_status' => $maritalStatus,
            'first_visit_date' => $dates['first_visit_date'],
            'repentance_date' => $dates['repentance_date'],
            'baptism_date' => $dates['baptism_date'],
            'membership_date' => $dates['membership_date'],
            'ministry' => array_key_exists('ministry', $_POST) ? trim((string) $_POST['ministry']) : ($old['ministry'] ?? null),
            'training_stage' => trim((string) ($_POST['training_stage'] ?? '')),
            'is_potential_leader' => isset($_POST['is_potential_leader']) ? '1' : '0',
            'notes' => trim((string) ($_POST['notes'] ?? '')),
            'sensitive_notes_encrypted' => (string) $encrypted,
            'spiritual_stage_id' => $_POST['spiritual_stage_id'] ?: null,
            'retention_until' => $_POST['retention_until'] ?: null,
        ];
        db()->prepare(
            'UPDATE people SET phone = ?, telegram = ?, birth_date = ?, marital_status = ?, first_visit_date = ?, repentance_date = ?, baptism_date = ?, membership_date = ?, ministry = ?, training_stage = ?, is_potential_leader = ?, notes = ?, sensitive_notes_encrypted = ?, spiritual_stage_id = ?, retention_until = ? WHERE id = ?'
        )->execute([
            $new['phone'],
            $new['telegram'],
            $new['birth_date'],
            $new['marital_status'] !== '' ? $new['marital_status'] : null,
            $new['first_visit_date'],
            $new['repentance_date'],
            $new['baptism_date'],
            $new['membership_date'],
            $new['ministry'] !== '' ? $new['ministry'] : null,
            $new['training_stage'],
            (int) $new['is_potential_leader'],
            $new['notes'],
            $encrypted,
            $new['spiritual_stage_id'],
            $new['retention_until'],
            $id,
        ]);
        log_person_changes($id, (int) $user['id'], $old, $new);
        audit('person_update', 'person', $id);
        ajax_redirect('/person?id=' . $id, 'Карточка обновлена');
    }
    if ($path === '/service-add') { verify_csrf(); require_action($user, 'manage_services'); $meeting = scoped_meeting($user, (int) $_POST['meeting_id']); $person = scoped_person($user, (int) $_POST['person_id']); if (!$meeting || !$person || (int) $person['group_id'] !== (int) $meeting['group_id']) ajax_redirect('/services', 'Недостаточно прав для выбранных данных'); db()->prepare('INSERT INTO service_schedule (meeting_id, person_id, service_role_id) VALUES (?, ?, ?)')->execute([(int) $meeting['id'], (int) $person['id'], (int) $_POST['service_role_id']]); audit('service_schedule_add'); ajax_redirect('/services', 'Служение назначено'); }
    if ($path === '/prayer-update') {
        verify_csrf();
        require_action($user, 'manage_prayers');
        $prayer = scoped_prayer($user, (int) $_POST['id']);
        if (!$prayer) {
            ajax_redirect('/prayers', 'Молитвенная нужда не найдена или недоступна');
        }
        $status = in_array($_POST['status'] ?? '', ['new', 'praying', 'testimony', 'closed'], true)
            ? (string) $_POST['status']
            : 'praying';
        $miracleBody = trim((string) ($_POST['miracle_body'] ?? ''));
        $miracleAnonymous = !empty($_POST['miracle_anonymous']);
        $existingMiracle = miracle_find_by_prayer_id((int) $prayer['id']);

        if ($status === 'testimony') {
            if (ChurchCare\miracle_body_required_for_testimony($existingMiracle, $miracleBody)) {
                ajax_redirect('/prayers', 'Для статуса «Свидетельство» расскажите, что сделал Господь');
            }
            if ($miracleBody !== '') {
                $title = ChurchCare\miracle_default_title_from_prayer((string) ($prayer['title'] ?? ''));
                $result = miracle_upsert_from_prayer($user, $prayer, $title, $miracleBody, $miracleAnonymous);
                audit(
                    $result['created'] ? 'miracle_add' : 'miracle_update',
                    'miracle',
                    $result['miracle_id'],
                    ['prayer_id' => (int) $prayer['id']]
                );
            } else {
                db()->prepare(
                    'UPDATE prayer_requests SET status = "testimony", next_reminder_at = NULL WHERE id = ?'
                )->execute([(int) $prayer['id']]);
            }
            audit('prayer_update', 'prayer', (int) $prayer['id'], ['status' => 'testimony']);
            ajax_redirect('/prayers', $miracleBody !== '' ? 'Свидетельство сохранено и опубликовано в Чудесах' : 'Статус нужды обновлен');
        }

        db()->prepare(
            'UPDATE prayer_requests SET status = ?,
                next_reminder_at = CASE WHEN ? IN ("closed","testimony") THEN NULL ELSE CURDATE() + INTERVAL 14 DAY END,
                closed_at = CASE WHEN ? = "closed" THEN NOW() ELSE closed_at END
             WHERE id = ?'
        )->execute([$status, $status, $status, (int) $prayer['id']]);
        audit('prayer_update', 'prayer', (int) $prayer['id']);
        ajax_redirect('/prayers', 'Статус нужды обновлен');
    }
    if ($path === '/miracle-from-prayer') {
        verify_csrf();
        require_action($user, 'manage_prayers');
        $prayer = scoped_prayer($user, (int) ($_POST['prayer_id'] ?? 0));
        if (!$prayer) {
            ajax_redirect('/prayers', 'Молитвенная нужда не найдена или недоступна');
        }
        $body = trim((string) ($_POST['miracle_body'] ?? ''));
        $titleInput = trim((string) ($_POST['title'] ?? ''));
        $title = $titleInput !== ''
            ? $titleInput
            : ChurchCare\miracle_default_title_from_prayer((string) ($prayer['title'] ?? ''));
        $anonymous = !empty($_POST['miracle_anonymous']);
        if (ChurchCare\miracle_prepare_payload($title, $body, $anonymous) === null) {
            $personId = (int) ($prayer['person_id'] ?? 0);
            $fallback = $personId > 0 ? '/person?id=' . $personId : '/prayers';
            ajax_redirect($fallback, 'Заполните текст свидетельства');
        }
        $result = miracle_upsert_from_prayer($user, $prayer, $title, $body, $anonymous);
        audit(
            $result['created'] ? 'miracle_add' : 'miracle_update',
            'miracle',
            $result['miracle_id'],
            ['prayer_id' => (int) $prayer['id']]
        );
        audit('prayer_update', 'prayer', (int) $prayer['id'], ['status' => 'testimony']);
        $personId = (int) ($prayer['person_id'] ?? 0);
        $redirect = $personId > 0 ? '/person?id=' . $personId : '/miracle?id=' . $result['miracle_id'];
        ajax_redirect($redirect, 'Свидетельство опубликовано в разделе Чудеса');
    }
    if ($path === '/miracle-add') {
        verify_csrf();
        require_action($user, 'manage_prayers');
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $anonymous = !empty($_POST['is_anonymous']);
        if (ChurchCare\miracle_prepare_payload($title, $body, $anonymous) === null) {
            ajax_redirect('/miracles', 'Заполните заголовок и текст свидетельства');
        }
        try {
            $miracleId = miracle_create_standalone($user, $title, $body, $anonymous);
        } catch (Throwable $e) {
            ajax_redirect('/miracles', 'Не удалось сохранить свидетельство');
        }
        audit('miracle_add', 'miracle', $miracleId);
        ajax_redirect('/miracle?id=' . $miracleId, 'Чудо опубликовано');
    }
    if ($path === '/miracle-archive') {
        verify_csrf();
        require_action($user, 'manage_prayers');
        $miracle = scoped_miracle($user, (int) ($_POST['id'] ?? 0));
        if (!$miracle) {
            ajax_redirect('/miracles', 'Свидетельство не найдено');
        }
        miracle_archive($miracle);
        audit('miracle_archive', 'miracle', (int) $miracle['id']);
        ajax_redirect('/miracles', 'Свидетельство перемещено в архив');
    }
    if ($path === '/course-pass') { verify_csrf(); $person = fetch_one('SELECT id FROM people WHERE user_id = ? AND deleted_at IS NULL LIMIT 1', [$user['id']]); if ($person) db()->prepare('INSERT INTO course_progress (person_id, lesson_id, status, passed_at) VALUES (?, ?, "passed", NOW()) ON DUPLICATE KEY UPDATE status = "passed", passed_at = NOW()')->execute([(int) $person['id'], (int) $_POST['lesson_id']]); audit('course_lesson_passed', 'lesson', (int) $_POST['lesson_id']); ajax_redirect('/courses', 'Урок отмечен'); }
    if ($path === '/meeting-report-save') { verify_csrf(); require_action($user, 'edit_people'); $meetingId = (int) $_POST['meeting_id']; $meeting = scoped_meeting($user, $meetingId); if (!$meeting) ajax_redirect('/calendar', 'Встреча не найдена или недоступна'); $sentTo = fetch_one('SELECT u.id FROM users u JOIN home_groups g ON g.id = ? JOIN people p ON p.id = g.leader_person_id WHERE u.id = p.user_id LIMIT 1', [(int) ($meeting['group_id'] ?? 0)]); $sentToId = $sentTo['id'] ?? null; db()->prepare('INSERT INTO meeting_reports (meeting_id, submitted_by, summary, attendance_count, newcomers_count, pastor_help_needed, sent_to_user_id, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE submitted_by = VALUES(submitted_by), summary = VALUES(summary), attendance_count = VALUES(attendance_count), newcomers_count = VALUES(newcomers_count), pastor_help_needed = VALUES(pastor_help_needed), sent_to_user_id = VALUES(sent_to_user_id), sent_at = NOW()')->execute([$meetingId, $user['id'], trim((string) $_POST['summary']), (int) $_POST['attendance_count'], (int) $_POST['newcomers_count'], (int) $_POST['pastor_help_needed'], $sentToId]); if ($sentToId) db()->prepare('INSERT INTO notification_events (user_id, title, body, url, channel, status) VALUES (?, "Отчет после встречи", ?, ?, "in_app", "pending")')->execute([$sentToId, mb_substr(trim((string) $_POST['summary']), 0, 240), '/meeting-report?id=' . $meetingId]); audit('meeting_report_save', 'meeting', $meetingId); ajax_redirect('/meeting-report?id=' . $meetingId, 'Отчет отправлен'); }
    if ($path === '/import-people') {
        verify_csrf();
        require_action($user, 'import_data');
        $lines = preg_split('/\R/u', trim((string) $_POST['csv'])) ?: [];
        $imported = 0;
        foreach ($lines as $index => $line) {
            $row = str_getcsv($line);
            if ($index === 0 && isset($row[0]) && strtolower(trim($row[0])) === 'full_name') continue;
            if (count($row) < 5 || trim((string) $row[0]) === '') continue;
            $group = scoped_group($user, (int) $row[4]);
            if (!$group) continue;
            $phoneRaw = trim((string) ($row[1] ?? ''));
            $telegramRaw = trim((string) ($row[2] ?? ''));
            $phone = ChurchCare\normalize_phone($phoneRaw) ?? ($phoneRaw !== '' ? $phoneRaw : null);
            $telegram = ChurchCare\normalize_telegram($telegramRaw)
                ?? (ChurchCare\telegram_username($telegramRaw) ?: null);
            db()->prepare('INSERT INTO people (full_name, phone, telegram, address, group_id, first_visit_date, training_stage) VALUES (?, ?, ?, ?, ?, ?, "новичок")')
                ->execute([
                    trim((string) $row[0]),
                    $phone,
                    $telegram,
                    trim((string) ($row[3] ?? '')) ?: null,
                    (int) $group['id'],
                    ($row[5] ?? '') ?: date('Y-m-d'),
                ]);
            $imported++;
        }
        db()->prepare('INSERT INTO import_batches (user_id, source_name, rows_total, rows_imported, details) VALUES (?, "people_csv", ?, ?, ?)')
            ->execute([$user['id'], count($lines), $imported, 'Импорт через экран CSV']);
        audit('people_import', 'import', null, ['rows' => $imported]);
        ajax_redirect('/import', 'Импортировано строк: ' . $imported);
    }
    if ($path === '/deletion-request') { verify_csrf(); require_action($user, 'delete_people'); $person = scoped_person($user, (int) $_POST['person_id']); if (!$person) ajax_redirect('/privacy', 'Карточка не найдена или недоступна'); db()->prepare('INSERT INTO data_deletion_requests (person_id, requested_by, reason, confirmation_token) VALUES (?, ?, ?, ?)')->execute([(int) $person['id'], $user['id'], trim((string) $_POST['reason']), bin2hex(random_bytes(16))]); audit('deletion_request', 'person', (int) $person['id']); ajax_redirect('/privacy', 'Запрос создан'); }
    if ($path === '/deletion-complete') { verify_csrf(); require_action($user, 'delete_people'); $request = scoped_deletion_request($user, (int) $_POST['id']); if ($request) { db()->prepare('UPDATE people SET full_name = CONCAT("Удаленная карточка #", id), phone = NULL, telegram = NULL, birth_date = NULL, address = NULL, marital_status = NULL, notes = NULL, sensitive_notes_encrypted = NULL, deleted_at = NOW() WHERE id = ?')->execute([(int) $request['person_id']]); db()->prepare('UPDATE data_deletion_requests SET status = "completed", completed_at = NOW() WHERE id = ?')->execute([(int) $request['id']]); audit('deletion_complete', 'person', (int) $request['person_id']); } ajax_redirect('/privacy', 'Персональные данные удалены'); }
}
