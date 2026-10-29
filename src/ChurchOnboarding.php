<?php

declare(strict_types=1);

/**
 * Platform onboarding: super_admin issues a permanent reusable invite link;
 * guests use it repeatedly to create a church + founding pastor account.
 * Links stay valid until explicitly revoked.
 */

function church_invite_storage_ready(): bool
{
    return function_exists('db_table_exists') && db_table_exists('church_invitations');
}

function church_invite_uses_storage_ready(): bool
{
    return function_exists('db_table_exists') && db_table_exists('church_invitation_uses');
}

/**
 * SQL fragment: invite is active (not revoked; no expiry or still in the future).
 */
function church_invite_active_sql(string $alias = 'ci'): string
{
    return $alias . '.revoked_at IS NULL AND (' . $alias . '.expires_at IS NULL OR ' . $alias . '.expires_at > NOW())';
}

/**
 * @param array<string,mixed> $row
 */
function church_invite_row_is_active(array $row): bool
{
    if (!empty($row['revoked_at'])) {
        return false;
    }
    $expires = $row['expires_at'] ?? null;
    if ($expires === null || $expires === '') {
        return true;
    }

    $ts = strtotime((string) $expires);
    return $ts !== false && $ts > time();
}

/**
 * @return array{ok:bool,error:?string,name:string,email:string,login:string,password:string,church_name:string,city:?string,slug_base:string,timezone:string}
 */
function church_invite_normalize_signup(array $input): array
{
    $name = trim((string) ($input['name'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $login = trim((string) ($input['login'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $churchName = trim((string) ($input['church_name'] ?? ''));
    $cityRaw = trim((string) ($input['city'] ?? ''));
    $city = $cityRaw !== '' ? $cityRaw : null;
    $slugInput = trim((string) ($input['slug'] ?? ''));
    $timezone = normalize_church_timezone((string) ($input['timezone'] ?? 'Europe/Moscow'));

    if ($name === '') {
        return church_invite_signup_error('Укажите ваше имя.', $name, $email, $login, $password, $churchName, $city, $slugInput, $timezone);
    }
    if (function_exists('normalize_account_email')) {
        $email = normalize_account_email($email);
    } elseif (function_exists('ChurchCare\\normalize_email')) {
        $normalizedEmail = \ChurchCare\normalize_email($email);
        $email = $normalizedEmail ?? mb_strtolower($email);
    } else {
        $email = mb_strtolower($email);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return church_invite_signup_error('Укажите корректный email.', $name, $email, $login, $password, $churchName, $city, $slugInput, $timezone);
    }
    if (mb_strlen($password) < 8) {
        return church_invite_signup_error('Пароль должен быть не короче 8 символов.', $name, $email, $login, $password, $churchName, $city, $slugInput, $timezone);
    }
    if ($churchName === '') {
        return church_invite_signup_error('Укажите название церкви.', $name, $email, $login, $password, $churchName, $city, $slugInput, $timezone);
    }

    $loginBase = $login !== '' ? $login : login_from_email($email);
    $loginNormalized = normalize_user_login($loginBase);
    if ($loginNormalized === '' || $loginNormalized === 'user') {
        return church_invite_signup_error('Укажите корректный логин (латиница, цифры, точка, дефис или подчёркивание).', $name, $email, $login, $password, $churchName, $city, $slugInput, $timezone);
    }

    $slugBase = $slugInput !== '' ? $slugInput : (string) ($city ?: $churchName);

    return [
        'ok' => true,
        'error' => null,
        'name' => $name,
        'email' => $email,
        'login' => $loginNormalized,
        'password' => $password,
        'church_name' => $churchName,
        'city' => $city,
        'slug_base' => $slugBase,
        'timezone' => $timezone,
    ];
}

/**
 * @return array{ok:bool,error:?string,name:string,email:string,login:string,password:string,church_name:string,city:?string,slug_base:string,timezone:string}
 */
function church_invite_signup_error(
    string $error,
    string $name,
    string $email,
    string $login,
    string $password,
    string $churchName,
    ?string $city,
    string $slugBase,
    string $timezone
): array {
    return [
        'ok' => false,
        'error' => $error,
        'name' => $name,
        'email' => $email,
        'login' => $login,
        'password' => $password,
        'church_name' => $churchName,
        'city' => $city,
        'slug_base' => $slugBase,
        'timezone' => $timezone,
    ];
}

/**
 * @return array{id:int,token:string,url:string,expires_at:?string}
 */
function create_church_invite(int $createdBy, ?string $note = null): array
{
    if ($createdBy <= 0) {
        throw new InvalidArgumentException('Некорректный автор приглашения.');
    }
    if (!church_invite_storage_ready()) {
        throw new RuntimeException('Таблица church_invitations ещё не создана. Примените миграцию.');
    }

    $token = bin2hex(random_bytes(24));
    $note = trim((string) $note);
    $note = $note !== '' ? mb_substr($note, 0, 190) : null;

    db()->prepare(
        'INSERT INTO church_invitations (token_hash, token_cipher, note, created_by, expires_at)
         VALUES (?, ?, ?, ?, NULL)'
    )->execute([
        hash('sha256', $token),
        encrypt_sensitive_note($token),
        $note,
        $createdBy,
    ]);

    $id = (int) db()->lastInsertId();

    return [
        'id' => $id,
        'token' => $token,
        'url' => public_url('/regchurch?token=' . urlencode($token)),
        'expires_at' => null,
    ];
}

function church_invite_public_url(string $token): string
{
    return public_url('/regchurch?token=' . urlencode($token));
}

/**
 * @return array<string,mixed>|null
 */
function church_invite_from_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    if (!church_invite_storage_ready()) {
        return null;
    }

    // Prefer revoked_at model (042+). Fall back for hosts that have not migrated yet.
    if (church_invite_has_revoked_column()) {
        return fetch_one(
            'SELECT * FROM church_invitations
             WHERE token_hash = ? AND ' . church_invite_active_sql('church_invitations') . '
             LIMIT 1',
            [hash('sha256', $token)]
        );
    }

    return fetch_one(
        'SELECT * FROM church_invitations
         WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
         LIMIT 1',
        [hash('sha256', $token)]
    );
}

function church_invite_has_revoked_column(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!function_exists('db_table_exists') || !db_table_exists('church_invitations')) {
        return $ready = false;
    }
    try {
        $row = fetch_one(
            'SELECT 1 AS ok FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            ['church_invitations', 'revoked_at']
        );
        return $ready = (bool) $row;
    } catch (Throwable) {
        return $ready = false;
    }
}

/**
 * @param array<string,mixed> $row
 * @return array{id:int,url:?string,expires_at:?string,note:?string,recoverable:bool,permanent:bool}
 */
function church_invite_recover_view(array $row): array
{
    $token = decrypt_sensitive_note($row['token_cipher'] ?? null);
    $recoverable = $token !== '' && preg_match('/^[a-f0-9]{48}$/', $token) === 1;
    $expires = $row['expires_at'] ?? null;
    $expiresStr = $expires !== null && $expires !== '' ? (string) $expires : null;

    return [
        'id' => (int) $row['id'],
        'url' => $recoverable ? church_invite_public_url($token) : null,
        'expires_at' => $expiresStr,
        'note' => isset($row['note']) && $row['note'] !== null && $row['note'] !== '' ? (string) $row['note'] : null,
        'recoverable' => $recoverable,
        'permanent' => $expiresStr === null,
    ];
}

/**
 * Active invites for the admin UI (permanent until revoked).
 *
 * @return list<array<string,mixed>>
 */
function church_invites_active_list(): array
{
    if (!church_invite_storage_ready()) {
        return [];
    }

    if (church_invite_has_revoked_column()) {
        $rows = fetch_all(
            'SELECT ci.*, u.name AS created_by_name, u.email AS created_by_email
             FROM church_invitations ci
             LEFT JOIN users u ON u.id = ci.created_by
             WHERE ' . church_invite_active_sql('ci') . '
             ORDER BY ci.id DESC
             LIMIT 50'
        );
    } else {
        $rows = fetch_all(
            'SELECT ci.*, u.name AS created_by_name, u.email AS created_by_email
             FROM church_invitations ci
             LEFT JOIN users u ON u.id = ci.created_by
             WHERE ci.used_at IS NULL AND ci.expires_at > NOW()
             ORDER BY ci.id DESC
             LIMIT 50'
        );
    }

    $out = [];
    foreach ($rows as $row) {
        $view = church_invite_recover_view($row);
        $out[] = array_merge($row, [
            'invite_url' => $view['url'],
            'recoverable' => $view['recoverable'],
            'permanent' => $view['permanent'],
        ]);
    }

    return $out;
}

/**
 * Recent signups via church invites.
 *
 * @return list<array<string,mixed>>
 */
function church_invites_recent_used_list(int $limit = 20): array
{
    if (!church_invite_storage_ready()) {
        return [];
    }
    $limit = max(1, min(100, $limit));

    if (church_invite_uses_storage_ready()) {
        return fetch_all(
            'SELECT u.created_at AS used_at,
                    ci.note,
                    creator.name AS created_by_name,
                    pastor.name AS pastor_name,
                    pastor.email AS pastor_email,
                    c.name AS church_name,
                    c.slug AS church_slug
             FROM church_invitation_uses u
             JOIN church_invitations ci ON ci.id = u.invite_id
             LEFT JOIN users creator ON creator.id = ci.created_by
             LEFT JOIN users pastor ON pastor.id = u.user_id
             LEFT JOIN churches c ON c.id = u.church_id
             ORDER BY u.created_at DESC
             LIMIT ' . $limit
        );
    }

    return fetch_all(
        'SELECT ci.*,
                creator.name AS created_by_name,
                pastor.name AS pastor_name,
                pastor.email AS pastor_email,
                c.name AS church_name,
                c.slug AS church_slug
         FROM church_invitations ci
         LEFT JOIN users creator ON creator.id = ci.created_by
         LEFT JOIN users pastor ON pastor.id = ci.used_by_user_id
         LEFT JOIN churches c ON c.id = ci.used_church_id
         WHERE ci.used_at IS NOT NULL AND ci.used_by_user_id IS NOT NULL
         ORDER BY ci.used_at DESC
         LIMIT ' . $limit
    );
}

function revoke_church_invite(int $inviteId, int $actorId): bool
{
    if ($inviteId <= 0 || $actorId <= 0 || !church_invite_storage_ready()) {
        return false;
    }

    if (church_invite_has_revoked_column()) {
        $stmt = db()->prepare(
            'UPDATE church_invitations
             SET revoked_at = NOW()
             WHERE id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$inviteId]);
    } else {
        $stmt = db()->prepare(
            'UPDATE church_invitations
             SET used_at = NOW()
             WHERE id = ? AND used_at IS NULL'
        );
        $stmt->execute([$inviteId]);
    }

    if ($stmt->rowCount() < 1) {
        return false;
    }

    audit('church_invite_revoked', 'church_invitation', $inviteId, ['by' => $actorId]);
    return true;
}

/**
 * Atomically create church + founding pastor. Invite stays valid for further signups.
 *
 * @return array{ok:bool,error:?string,user_id:?int,church_id:?int,slug:?string}
 */
function consume_church_invite(string $token, array $input): array
{
    $invite = church_invite_from_token($token);
    if (!$invite) {
        return ['ok' => false, 'error' => 'Ссылка недействительна или отозвана.', 'user_id' => null, 'church_id' => null, 'slug' => null];
    }

    $normalized = church_invite_normalize_signup($input);
    if (!$normalized['ok']) {
        return ['ok' => false, 'error' => $normalized['error'], 'user_id' => null, 'church_id' => null, 'slug' => null];
    }

    // Same email/login may already exist in another church — that is allowed.
    $slug = unique_church_slug($normalized['slug_base']);
    if (!church_slug_is_valid($slug)) {
        return ['ok' => false, 'error' => 'Не удалось подобрать корректный адрес церкви (slug). Укажите другой slug.', 'user_id' => null, 'church_id' => null, 'slug' => null];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (church_invite_has_revoked_column()) {
            $locked = fetch_one(
                'SELECT id FROM church_invitations WHERE id = ? AND ' . church_invite_active_sql('church_invitations') . ' LIMIT 1 FOR UPDATE',
                [(int) $invite['id']]
            );
        } else {
            $locked = fetch_one(
                'SELECT id FROM church_invitations WHERE id = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1 FOR UPDATE',
                [(int) $invite['id']]
            );
        }
        if (!$locked) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Ссылка недействительна или отозвана.', 'user_id' => null, 'church_id' => null, 'slug' => null];
        }

        $pdo->prepare('INSERT INTO churches (name, city, slug, timezone) VALUES (?, ?, ?, ?)')
            ->execute([$normalized['church_name'], $normalized['city'], $slug, $normalized['timezone']]);
        $churchId = (int) $pdo->lastInsertId();
        $login = unique_user_login($normalized['login'], null, $churchId);

        $pdo->prepare(
            'INSERT INTO users (name, login, email, password_hash, role, church_id, group_id)
             VALUES (?, ?, ?, ?, "pastor", ?, NULL)'
        )->execute([
            $normalized['name'],
            $login,
            $normalized['email'],
            password_hash($normalized['password'], PASSWORD_DEFAULT),
            $churchId,
        ]);
        $userId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT IGNORE INTO user_notification_settings (user_id) VALUES (?)')
            ->execute([$userId]);

        if (church_invite_uses_storage_ready()) {
            $pdo->prepare(
                'INSERT INTO church_invitation_uses (invite_id, user_id, church_id) VALUES (?, ?, ?)'
            )->execute([(int) $invite['id'], $userId, $churchId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    audit('church_invite_consumed', 'church', $churchId, [
        'invite_id' => (int) $invite['id'],
        'user_id' => $userId,
        'slug' => $slug,
    ]);
    audit('church_create', 'church', $churchId, ['slug' => $slug, 'via' => 'church_invite']);
    audit('user_create', 'user', $userId, ['role' => 'pastor', 'church_id' => $churchId, 'via' => 'church_invite']);

    return ['ok' => true, 'error' => null, 'user_id' => $userId, 'church_id' => $churchId, 'slug' => $slug];
}

function church_invite_page(): void
{
    $token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
    $invite = church_invite_from_token($token);
    $error = null;
    $posted = [
        'name' => (string) ($_POST['name'] ?? ''),
        'email' => (string) ($_POST['email'] ?? ''),
        'login' => (string) ($_POST['login'] ?? ''),
        'church_name' => (string) ($_POST['church_name'] ?? ''),
        'city' => (string) ($_POST['city'] ?? ''),
        'slug' => (string) ($_POST['slug'] ?? ''),
        'timezone' => (string) ($_POST['timezone'] ?? 'Europe/Moscow'),
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (!church_invite_storage_ready()) {
            $error = 'Регистрация временно недоступна: администратору нужно применить миграцию базы данных.';
        } elseif (!$invite) {
            $error = 'Ссылка недействительна или отозвана.';
        } elseif (!throttle_action('regchurch:' . ($_SERVER['REMOTE_ADDR'] ?? 'local'), 5, 600)) {
            $error = 'Слишком много попыток. Подождите несколько минут и попробуйте снова.';
        } else {
            try {
                $result = consume_church_invite($token, array_merge($posted, [
                    'password' => (string) ($_POST['password'] ?? ''),
                ]));
                if (!$result['ok']) {
                    $error = (string) $result['error'];
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) $result['user_id'];
                    flash('Церковь создана. Добро пожаловать — вы вошли как пастор.');
                    redirect('/' . $result['slug']);
                }
            } catch (Throwable $e) {
                $error = 'Не удалось создать церковь. Попробуйте ещё раз.';
            }
        }
    }

    $defaultLogin = $posted['login'] !== ''
        ? $posted['login']
        : ($posted['email'] !== '' ? login_from_email($posted['email']) : '');

    public_page_header('Создание церкви');
    echo '<form class="login-card" method="post"><h1>Создание церкви</h1>';
    if ($error) {
        echo '<div class="flash error">' . e($error) . '</div>';
    }
    if (!$invite && !$error) {
        echo '<div class="flash error">Ссылка недействительна или отозвана.</div>';
    }
    if ($invite) {
        echo '<p class="muted">По этой ссылке вы создадите новую церковь и учётную запись пастора.</p>';
        echo csrf_field() . '<input type="hidden" name="token" value="' . e($token) . '">';
        echo '<p><strong>Ваша учётная запись</strong></p>';
        echo '<label>Имя<input name="name" required value="' . e($posted['name']) . '"></label>';
        echo '<label>Email<input name="email" type="email" required value="' . e($posted['email']) . '"></label>';
        echo '<label>Логин<input name="login" required pattern="[a-zA-Z0-9._\\x2d]+" value="' . e($defaultLogin) . '"></label>';
        echo '<label>Пароль<input name="password" type="password" required minlength="8"></label>';
        echo '<p><strong>Церковь</strong></p>';
        echo '<label>Название церкви<input name="church_name" required value="' . e($posted['church_name']) . '"></label>';
        echo '<label>Город<input name="city" value="' . e($posted['city']) . '"></label>';
        echo '<label>Адрес в URL (slug)<input name="slug" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" placeholder="pushkino" value="' . e($posted['slug']) . '"><span class="muted">Латиница, цифры и дефис. Можно оставить пустым — подберём автоматически.</span></label>';
        echo '<label>Часовой пояс<input name="timezone" value="' . e($posted['timezone'] !== '' ? $posted['timezone'] : 'Europe/Moscow') . '" placeholder="Europe/Moscow"></label>';
        echo '<button class="primary full">Создать церковь и войти</button>';
    }
    echo '<p class="muted"><a href="/login">Войти</a></p></form>';
    public_page_footer();
}

function render_admin_church_invites_panel(): void
{
    if (!church_invite_storage_ready()) {
        echo '<section class="panel"><h2>Приглашение пастора</h2>';
        echo '<div class="flash error">Таблица church_invitations ещё не создана. Примените миграцию 041_church_invitations.sql.</div></section>';
        return;
    }

    $active = church_invites_active_list();
    $used = church_invites_recent_used_list(10);

    echo '<section class="panel"><h2>Приглашение пастора создать церковь</h2>';
    echo '<p class="muted">Постоянная ссылка: ей могут пользоваться разные пасторы, пока вы её не отзовёте. Каждый гость создаёт свою церковь и становится её пастором.</p>';
    if (!church_invite_has_revoked_column() || !church_invite_uses_storage_ready()) {
        echo '<div class="flash error">Примените миграцию 042_church_invite_permanent.sql, чтобы ссылки стали постоянными.</div>';
    }
    echo '<form method="post" action="' . e(platform_path('/admin/church-invite-create')) . '" class="stack">' . csrf_field();
    echo '<label>Заметка (необязательно)<input name="note" maxlength="190" placeholder="Общая ссылка для новых церквей"></label>';
    echo '<button class="primary">Создать постоянную ссылку</button></form>';

    if ($active !== []) {
        echo '<div class="table-wrap" style="margin-top:1rem"><table><thead><tr><th>Заметка</th><th>Ссылка</th><th>Срок</th><th></th></tr></thead><tbody>';
        foreach ($active as $row) {
            echo '<tr><td>' . e((string) ($row['note'] ?? '—')) . '</td><td>';
            if (!empty($row['invite_url'])) {
                echo '<code style="word-break:break-all">' . e((string) $row['invite_url']) . '</code>';
            } else {
                echo '<span class="muted">Ссылка невосстановима — создайте новую</span>';
            }
            $term = !empty($row['permanent']) || empty($row['expires_at'])
                ? 'бессрочно'
                : ('до ' . (string) $row['expires_at']);
            echo '</td><td>' . e($term) . '</td><td>';
            echo '<form method="post" action="' . e(platform_path('/admin/church-invite-revoke')) . '" class="compact">' . csrf_field();
            echo '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
            echo '<button class="ghost" data-confirm="Отозвать эту ссылку? Новые регистрации по ней станут невозможны.">Отозвать</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo '<p class="muted" style="margin-top:1rem">Активных ссылок нет.</p>';
    }

    if ($used !== []) {
        echo '<h3 style="margin-top:1.5rem">Недавние регистрации</h3>';
        echo '<div class="table-wrap"><table><thead><tr><th>Когда</th><th>Пастор</th><th>Церковь</th><th>Заметка ссылки</th></tr></thead><tbody>';
        foreach ($used as $row) {
            $churchLabel = trim((string) ($row['church_name'] ?? ''));
            $slug = (string) ($row['church_slug'] ?? '');
            if ($churchLabel !== '' && $slug !== '') {
                $churchLabel .= ' (/' . $slug . ')';
            } elseif ($slug !== '') {
                $churchLabel = '/' . $slug;
            } else {
                $churchLabel = '—';
            }
            echo '<tr><td>' . e((string) ($row['used_at'] ?? '')) . '</td>';
            echo '<td>' . e(trim((string) (($row['pastor_name'] ?? '') . ' ' . ($row['pastor_email'] ?? ''))) ?: '—') . '</td>';
            echo '<td>' . e($churchLabel) . '</td>';
            echo '<td>' . e((string) ($row['note'] ?? '—')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    echo '</section>';
}
