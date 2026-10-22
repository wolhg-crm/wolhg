<?php

declare(strict_types=1);

/**
 * Miracles / testimonies: published stories of God's answers, often linked to prayer needs.
 */

function miracle_find_by_prayer_id(int $prayerId): ?array
{
    if ($prayerId <= 0) {
        return null;
    }

    return fetch_one('SELECT * FROM miracles WHERE prayer_request_id = ? LIMIT 1', [$prayerId]);
}

/**
 * @return array{0:string,1:list<mixed>}
 */
function miracles_list_sql(array $user, bool $includeArchived = false): array
{
    $churchId = (int) (effective_scope_church_id($user) ?? 0);
    $sql = 'm.church_id = ?';
    $params = [$churchId];
    if (!$includeArchived) {
        $sql .= ' AND m.status = "published"';
    }

    return [$sql, $params];
}

function scoped_miracle(array $user, int $miracleId): ?array
{
    $churchId = (int) (effective_scope_church_id($user) ?? 0);
    if ($churchId <= 0 || $miracleId <= 0) {
        return null;
    }

    return fetch_one(
        'SELECT m.*, p.full_name, g.name AS group_name, pr.title AS prayer_title
         FROM miracles m
         LEFT JOIN people p ON p.id = m.person_id
         LEFT JOIN home_groups g ON g.id = m.group_id
         LEFT JOIN prayer_requests pr ON pr.id = m.prayer_request_id
         WHERE m.church_id = ? AND m.id = ?
         LIMIT 1',
        [$churchId, $miracleId]
    );
}

/**
 * Resolve church_id for a prayer via its group (or the acting user as fallback).
 */
function miracle_church_id_for_prayer(array $prayer, array $user): int
{
    $groupId = (int) ($prayer['group_id'] ?? 0);
    if ($groupId > 0) {
        $group = fetch_one('SELECT church_id FROM home_groups WHERE id = ? LIMIT 1', [$groupId]);
        if ($group && (int) ($group['church_id'] ?? 0) > 0) {
            return (int) $group['church_id'];
        }
    }

    $personId = (int) ($prayer['person_id'] ?? 0);
    if ($personId > 0) {
        $person = fetch_one(
            'SELECT g.church_id FROM people p JOIN home_groups g ON g.id = p.group_id WHERE p.id = ? LIMIT 1',
            [$personId]
        );
        if ($person && (int) ($person['church_id'] ?? 0) > 0) {
            return (int) $person['church_id'];
        }
    }

    return (int) (effective_scope_church_id($user) ?? ($user['church_id'] ?? 0));
}

/**
 * Create or update a miracle linked to a prayer request and mark the prayer as testimony.
 *
 * @return array{miracle_id:int,created:bool}
 */
function miracle_upsert_from_prayer(array $user, array $prayer, string $title, string $body, bool $isAnonymous): array
{
    $payload = ChurchCare\miracle_prepare_payload($title, $body, $isAnonymous);
    if ($payload === null) {
        throw new InvalidArgumentException('Пустое свидетельство');
    }

    $prayerId = (int) $prayer['id'];
    $churchId = miracle_church_id_for_prayer($prayer, $user);
    if ($churchId <= 0) {
        throw new RuntimeException('Не удалось определить церковь для свидетельства');
    }

    $existing = miracle_find_by_prayer_id($prayerId);
    $pdo = db();

    if ($existing) {
        $pdo->prepare(
            'UPDATE miracles SET title = ?, body = ?, is_anonymous = ?, status = "published",
                published_at = COALESCE(published_at, NOW()),
                person_id = ?, group_id = ?, church_id = ?
             WHERE id = ?'
        )->execute([
            $payload['title'],
            $payload['body'],
            $payload['is_anonymous'],
            $prayer['person_id'] !== null ? (int) $prayer['person_id'] : null,
            $prayer['group_id'] !== null ? (int) $prayer['group_id'] : null,
            $churchId,
            (int) $existing['id'],
        ]);
        $miracleId = (int) $existing['id'];
        $created = false;
    } else {
        $pdo->prepare(
            'INSERT INTO miracles (church_id, group_id, person_id, prayer_request_id, title, body, submitter_name, is_anonymous, status, created_by, published_at)
             VALUES (?, ?, ?, ?, ?, ?, NULL, ?, "published", ?, NOW())'
        )->execute([
            $churchId,
            $prayer['group_id'] !== null ? (int) $prayer['group_id'] : null,
            $prayer['person_id'] !== null ? (int) $prayer['person_id'] : null,
            $prayerId,
            $payload['title'],
            $payload['body'],
            $payload['is_anonymous'],
            (int) $user['id'],
        ]);
        $miracleId = (int) $pdo->lastInsertId();
        $created = true;
    }

    $pdo->prepare(
        'UPDATE prayer_requests SET status = "testimony", next_reminder_at = NULL WHERE id = ?'
    )->execute([$prayerId]);

    return ['miracle_id' => $miracleId, 'created' => $created];
}

/**
 * Standalone miracle (not linked to a prayer need).
 *
 * @return int miracle id
 */
function miracle_create_standalone(
    array $user,
    string $title,
    string $body,
    bool $isAnonymous,
    ?int $personId = null,
    ?int $groupId = null
): int {
    $payload = ChurchCare\miracle_prepare_payload($title, $body, $isAnonymous);
    if ($payload === null) {
        throw new InvalidArgumentException('Пустое свидетельство');
    }

    $churchId = (int) (effective_scope_church_id($user) ?? ($user['church_id'] ?? 0));
    if ($churchId <= 0) {
        throw new RuntimeException('Не удалось определить церковь для свидетельства');
    }

    if ($personId !== null && $personId > 0) {
        $person = scoped_person($user, $personId);
        if (!$person) {
            throw new RuntimeException('Человек недоступен');
        }
        $groupId = (int) ($person['group_id'] ?? 0) ?: $groupId;
    } elseif ($groupId !== null && $groupId > 0) {
        $group = scoped_group($user, $groupId);
        if (!$group) {
            throw new RuntimeException('Группа недоступна');
        }
    }

    $pdo = db();
    $pdo->prepare(
        'INSERT INTO miracles (church_id, group_id, person_id, prayer_request_id, title, body, submitter_name, is_anonymous, status, created_by, published_at)
         VALUES (?, ?, ?, NULL, ?, ?, NULL, ?, "published", ?, NOW())'
    )->execute([
        $churchId,
        $groupId !== null && $groupId > 0 ? $groupId : null,
        $personId !== null && $personId > 0 ? $personId : null,
        $payload['title'],
        $payload['body'],
        $payload['is_anonymous'],
        (int) $user['id'],
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * @param array{person_id:?int,group_id:?int,church_id:int,submitter_name:?string,is_anonymous:int,created_by:?int} $attribution
 */
function miracle_public_insert(string $title, string $body, array $attribution): int
{
    $payload = ChurchCare\miracle_prepare_payload($title, $body, (bool) $attribution['is_anonymous']);
    if ($payload === null) {
        throw new InvalidArgumentException('Пустое свидетельство');
    }

    $churchId = (int) $attribution['church_id'];
    if ($churchId <= 0) {
        throw new RuntimeException('Не удалось определить церковь для свидетельства');
    }

    $pdo = db();
    $pdo->prepare(
        'INSERT INTO miracles (church_id, group_id, person_id, prayer_request_id, title, body, submitter_name, is_anonymous, status, created_by, published_at)
         VALUES (?, ?, ?, NULL, ?, ?, ?, ?, "published", ?, NOW())'
    )->execute([
        $churchId,
        $attribution['group_id'],
        $attribution['person_id'],
        $payload['title'],
        $payload['body'],
        $attribution['is_anonymous'] ? null : $attribution['submitter_name'],
        $payload['is_anonymous'],
        $attribution['created_by'],
    ]);

    return (int) $pdo->lastInsertId();
}

function miracle_share_public_page(): void
{
    $church = current_church();
    if (!$church || church_is_deleted($church)) {
        http_response_code(404);
        exit('Страница не найдена');
    }

    $error = null;
    $sent = false;
    $sentMiracleId = 0;
    $user = current_user();
    $linked = $user
        ? prayer_public_linked_person_for_church(person_linked_to_user((int) $user['id']), (int) $church['id'])
        : null;
    $isLoggedIn = $user !== null;

    $titleValue = '';
    $bodyValue = '';
    $fullNameValue = '';
    $anonymousChecked = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $titleValue = trim((string) ($_POST['title'] ?? ''));
        $bodyValue = trim((string) ($_POST['body'] ?? ''));
        $fullNameValue = trim((string) ($_POST['full_name'] ?? ''));
        $anonymousChecked = !$isLoggedIn && !empty($_POST['is_anonymous']);

        if (!throttle_action('miracle-public:' . ($_SERVER['REMOTE_ADDR'] ?? 'local'), 8, 600)) {
            $error = 'Слишком много попыток. Попробуйте позже.';
        } elseif ($titleValue === '') {
            $error = 'Добавьте краткий заголовок свидетельства.';
        } elseif ($bodyValue === '') {
            $error = 'Расскажите, что сделал Господь.';
        } elseif (mb_strlen($titleValue) > 190) {
            $error = 'Заголовок слишком длинный.';
        } else {
            $attribution = prayer_public_resolve_attribution(
                $anonymousChecked,
                $isLoggedIn ? null : $fullNameValue
            );
            if ($attribution === null) {
                $error = $isLoggedIn
                    ? 'Не удалось определить ваше имя. Укажите имя в профиле или обратитесь к лидеру.'
                    : 'Укажите ФИО или отметьте «Отправить анонимно».';
            } else {
                try {
                    $sentMiracleId = miracle_public_insert($titleValue, $bodyValue, $attribution);
                    audit('miracle_public_add', 'miracle', $sentMiracleId, [
                        'anonymous' => (bool) $attribution['is_anonymous'],
                        'logged_in' => $isLoggedIn,
                        'church_id' => (int) $attribution['church_id'],
                    ]);
                    $sent = true;
                    $titleValue = '';
                    $bodyValue = '';
                    $fullNameValue = '';
                    $anonymousChecked = false;
                } catch (Throwable) {
                    $error = 'Не удалось сохранить свидетельство. Попробуйте ещё раз.';
                }
            }
        }
    }

    $loginHref = '/' . rawurlencode((string) $church['slug']) . '/login';
    $prayerHref = public_prayer_request_path($church);
    $joinHref = function_exists('homegroup_join_base_path')
        ? homegroup_join_base_path($church)
        : ('/' . rawurlencode((string) $church['slug']) . '/homegroup/join');
    $brandCity = trim((string) ($church['city'] ?? ''));
    if ($brandCity === '') {
        $brandCity = (string) ($church['name'] ?? '');
    }

    public_page_header('Поделиться свидетельством');
    echo '<div class="public-shell prayer-public-shell">';
    echo '<form class="login-card prayer-public-card" method="post" data-miracle-public-form>';
    echo render_brand_markup(['variant' => 'hero', 'church' => $church, 'subtitle' => $brandCity !== '' ? $brandCity : null]);
    echo '<h1>Поделиться свидетельством</h1>';
    echo '<p class="muted login-subtitle">Расскажите, что сделал Господь в вашей жизни. История появится в «Чудеса» церкви «' . e((string) $church['name']) . '».</p>';

    if ($sent) {
        echo '<div class="flash">Спасибо! Ваше свидетельство принято';
        if ($isLoggedIn && $sentMiracleId > 0) {
            echo ' — <a href="' . e(app_path('/miracle?id=' . $sentMiracleId)) . '">открыть</a>';
        }
        echo '.</div>';
    }
    if ($error) {
        echo '<div class="flash error">' . e($error) . '</div>';
    }

    echo csrf_field();
    echo '<label>Заголовок<input name="title" required maxlength="190" value="' . e($titleValue) . '" placeholder="Кратко: что сделал Господь"></label>';
    echo '<label>Свидетельство<textarea name="body" rows="7" required placeholder="За что молились и как ответил Господь">' . e($bodyValue) . '</textarea></label>';

    if ($isLoggedIn) {
        $displayName = $linked
            ? (string) $linked['full_name']
            : trim((string) ($user['name'] ?? ''));
        echo '<p class="prayer-public-from">Свидетельство будет опубликовано от вашего имени: <strong>' . e($displayName !== '' ? $displayName : 'пользователь') . '</strong>';
        if ($linked && !empty($linked['group_name'])) {
            echo ' <span class="muted">· ' . e((string) $linked['group_name']) . '</span>';
        }
        echo '</p>';
    } else {
        echo '<label class="checkbox-row"><input type="checkbox" name="is_anonymous" value="1" data-prayer-anonymous' . ($anonymousChecked ? ' checked' : '') . '> Отправить анонимно</label>';
        echo '<div data-prayer-guest-name' . ($anonymousChecked ? ' hidden' : '') . '>';
        echo '<label>ФИО<input name="full_name" maxlength="190" value="' . e($fullNameValue) . '" placeholder="Имя и фамилия" autocomplete="name"' . ($anonymousChecked ? '' : ' required') . ' data-prayer-guest-name-input></label>';
        echo '</div>';
    }

    echo '<button class="primary full" type="submit">Опубликовать свидетельство</button>';
    echo '<p class="muted prayer-public-links">';
    if ($isLoggedIn) {
        echo '<a href="' . e(app_path('/')) . '">В CRM</a> · <a href="' . e(app_path('/miracles')) . '">Чудеса</a> · <a href="' . e($prayerHref) . '">Молитвенная нужда</a>';
    } else {
        echo '<a href="' . e($loginHref) . '">Войти</a> · <a href="' . e($prayerHref) . '">Молитвенная нужда</a> · <a href="' . e($joinHref) . '">Заявка в домашнюю группу</a>';
    }
    echo '</p></form></div>';
    public_page_footer();
}

function miracle_archive(array $miracle): void
{
    db()->prepare(
        'UPDATE miracles SET status = "archived" WHERE id = ?'
    )->execute([(int) $miracle['id']]);
}

function miracle_render_body(string $body): string
{
    $escaped = e($body);
    return nl2br($escaped, false);
}

function miracle_format_date(?string $publishedAt, ?string $createdAt = null): string
{
    $raw = $publishedAt ?: $createdAt;
    if ($raw === null || $raw === '') {
        return '';
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return $raw;
    }

    return date('d.m.Y', $ts);
}

/**
 * @param array<string, mixed> $miracle
 */
function miracle_render_card(array $miracle, bool $showActions = false): void
{
    $id = (int) ($miracle['id'] ?? 0);
    $title = (string) ($miracle['title'] ?? '');
    $body = (string) ($miracle['body'] ?? '');
    $excerpt = ChurchCare\miracle_excerpt($body);
    $author = ChurchCare\miracle_author_label($miracle);
    $date = miracle_format_date(
        isset($miracle['published_at']) ? (string) $miracle['published_at'] : null,
        isset($miracle['created_at']) ? (string) $miracle['created_at'] : null
    );
    $status = (string) ($miracle['status'] ?? 'published');

    echo '<article class="miracle-card">';
    echo '<p class="eyebrow">Чудо</p>';
    echo '<h3><a href="' . e(app_path('/miracle?id=' . $id)) . '">' . e($title) . '</a></h3>';
    echo '<p class="miracle-card__meta">' . e($author);
    if ($date !== '') {
        echo ' · ' . e($date);
    }
    if ($status === 'archived') {
        echo ' · ' . e(ChurchCare\miracle_status_label($status));
    }
    echo '</p>';
    echo '<p class="miracle-card__excerpt">' . e($excerpt) . '</p>';
    echo '<a class="ghost" href="' . e(app_path('/miracle?id=' . $id)) . '">Читать свидетельство</a>';
    if ($showActions && $status === 'published') {
        echo '<form method="post" action="' . e(app_path('/miracle-archive')) . '" class="inline-form miracle-card__archive">' . csrf_field();
        echo '<input type="hidden" name="id" value="' . $id . '">';
        echo '<button class="ghost" type="submit">В архив</button></form>';
    }
    echo '</article>';
}

function miracles_page(array $user): void
{
    page_header('Чудеса', $user);
    $canManage = can_action($user, 'manage_prayers');
    $includeArchived = $canManage && (($_GET['archived'] ?? '') === '1');

    echo '<section class="panel miracle-intro">';
    echo '<p class="eyebrow">Свидетельства</p>';
    echo '<h2>Что сделал Господь</h2>';
    echo '<p class="muted">Здесь собраны чудеса и ответы на молитву в жизни людей церкви. Каждая история напоминает: Бог слышит и действует.</p>';
    echo '<div class="page-actions" style="margin:16px 0 0">';
    echo '<a class="ghost" href="' . e(public_miracle_share_path()) . '" target="_blank" rel="noopener">Публичная форма свидетельства</a>';
    if ($canManage) {
        if ($includeArchived) {
            echo '<a class="ghost" href="' . e(app_path('/miracles')) . '">Только опубликованные</a>';
        } else {
            echo '<a class="ghost" href="' . e(app_path('/miracles?archived=1')) . '">Показать архив</a>';
        }
    }
    echo '</div>';
    echo '</section>';

    if ($canManage) {
        echo '<section class="panel"><div class="panel-head"><h2>Добавить свидетельство</h2><span class="muted">Можно рассказать отдельно или привязать к молитвенной нужде на экране молитв / в карточке человека</span></div>';
        echo '<form method="post" action="' . e(app_path('/miracle-add')) . '" class="stack">' . csrf_field();
        echo '<label>Заголовок<input name="title" required maxlength="190" placeholder="Кратко: что сделал Господь"></label>';
        echo '<label>Свидетельство<textarea name="body" rows="6" required placeholder="Расскажите историю чуда: за что молились и как ответил Господь"></textarea></label>';
        echo '<label class="checkbox-row"><input type="checkbox" name="is_anonymous" value="1"> Опубликовать анонимно</label>';
        echo '<button class="primary" type="submit">Опубликовать чудо</button></form></section>';
    }

    [$where, $params] = miracles_list_sql($user, $includeArchived);
    $rows = fetch_all(
        "SELECT m.*, p.full_name, g.name AS group_name
         FROM miracles m
         LEFT JOIN people p ON p.id = m.person_id
         LEFT JOIN home_groups g ON g.id = m.group_id
         WHERE $where
         ORDER BY COALESCE(m.published_at, m.created_at) DESC, m.id DESC",
        $params
    );

    if (!$rows) {
        echo '<section class="panel"><p class="muted">Пока нет опубликованных чудес. Когда молитвенная нужда получит ответ — расскажите свидетельство, и оно появится здесь.</p>';
        if ($canManage) {
            echo '<p><a class="primary" href="' . e(app_path('/prayers')) . '">Перейти к молитвенным нуждам</a></p>';
        }
        echo '</section>';
        page_footer();
        return;
    }

    echo '<section class="miracle-grid">';
    foreach ($rows as $row) {
        miracle_render_card($row, $canManage && !$includeArchived);
    }
    echo '</section>';
    page_footer();
}

function miracle_page(array $user): void
{
    $id = (int) ($_GET['id'] ?? 0);
    $miracle = scoped_miracle($user, $id);
    if (!$miracle || (($miracle['status'] ?? '') === 'archived' && !can_action($user, 'manage_prayers'))) {
        http_response_code(404);
        exit('Свидетельство не найдено');
    }

    page_header((string) $miracle['title'], $user);
    $author = ChurchCare\miracle_author_label($miracle);
    $date = miracle_format_date(
        isset($miracle['published_at']) ? (string) $miracle['published_at'] : null,
        isset($miracle['created_at']) ? (string) $miracle['created_at'] : null
    );

    echo '<section class="panel miracle-detail">';
    echo '<p class="eyebrow">Чудо · свидетельство</p>';
    echo '<h2>' . e((string) $miracle['title']) . '</h2>';
    echo '<p class="miracle-card__meta">' . e($author);
    if ($date !== '') {
        echo ' · ' . e($date);
    }
    if (($miracle['status'] ?? '') === 'archived') {
        echo ' · ' . e(ChurchCare\miracle_status_label('archived'));
    }
    echo '</p>';
    echo '<div class="miracle-detail__body">' . miracle_render_body((string) $miracle['body']) . '</div>';

    if (!empty($miracle['prayer_request_id']) && !empty($miracle['prayer_title']) && can_action($user, 'manage_prayers')) {
        echo '<p class="muted">Связано с молитвенной нуждой: «' . e((string) $miracle['prayer_title']) . '»</p>';
        echo '<p><a class="ghost" href="' . e(app_path('/prayers')) . '">К молитвенным нуждам</a></p>';
    }

    echo '<p><a class="ghost" href="' . e(app_path('/miracles')) . '">Все чудеса</a></p>';
    echo '</section>';
    page_footer();
}
