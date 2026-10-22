<?php

declare(strict_types=1);

/**
 * Public prayer request form: guests and logged-in users can submit a need
 * scoped to the current church tenant (/{slug}/prayer-request).
 */

/**
 * Church that owns a public prayer/miracle submission.
 * Prefers the bound tenant; falls back to platform default church.
 */
function prayer_public_target_church_id(): int
{
    $tenant = current_church();
    if ($tenant && !church_is_deleted($tenant)) {
        $id = (int) ($tenant['id'] ?? 0);
        if ($id > 0) {
            return $id;
        }
    }

    $church = function_exists('default_church') ? default_church() : null;
    $defaultChurchId = (int) ($church['id'] ?? (app_config('default_church_id') ?: 1));

    return $defaultChurchId > 0 ? $defaultChurchId : 1;
}

/**
 * Linked person may only be attached when they belong to the target church.
 *
 * @param array<string, mixed>|null $linked
 */
function prayer_public_linked_person_for_church(?array $linked, int $churchId): ?array
{
    if (!$linked || $churchId <= 0) {
        return null;
    }
    if ((int) ($linked['church_id'] ?? 0) !== $churchId) {
        return null;
    }

    return $linked;
}

/**
 * @return array{person_id:?int,group_id:?int,church_id:int,submitter_name:?string,is_anonymous:int,created_by:?int}|null
 */
function prayer_public_resolve_attribution(bool $anonymous, ?string $guestName): ?array
{
    $user = current_user();
    $churchId = prayer_public_target_church_id();

    if ($user) {
        $linked = prayer_public_linked_person_for_church(
            person_linked_to_user((int) $user['id']),
            $churchId
        );

        if ($linked) {
            $groupId = (int) ($linked['group_id'] ?? 0);

            return [
                'person_id' => (int) $linked['id'],
                'group_id' => $groupId > 0 ? $groupId : null,
                'church_id' => $churchId,
                'submitter_name' => null,
                'is_anonymous' => 0,
                'created_by' => (int) $user['id'],
            ];
        }

        $name = ChurchCare\prayer_normalize_submitter_name((string) ($user['name'] ?? ''));
        if ($name === null) {
            return null;
        }

        return [
            'person_id' => null,
            'group_id' => null,
            'church_id' => $churchId,
            'submitter_name' => $name,
            'is_anonymous' => 0,
            'created_by' => (int) $user['id'],
        ];
    }

    if ($anonymous) {
        return [
            'person_id' => null,
            'group_id' => null,
            'church_id' => $churchId,
            'submitter_name' => null,
            'is_anonymous' => 1,
            'created_by' => null,
        ];
    }

    $name = ChurchCare\prayer_normalize_submitter_name($guestName);
    if ($name === null) {
        return null;
    }

    return [
        'person_id' => null,
        'group_id' => null,
        'church_id' => $churchId,
        'submitter_name' => $name,
        'is_anonymous' => 0,
        'created_by' => null,
    ];
}

/**
 * @param array{person_id:?int,group_id:?int,church_id:int,submitter_name:?string,is_anonymous:int,created_by:?int} $attribution
 */
function prayer_public_insert(string $title, string $description, array $attribution): int
{
    $churchId = (int) $attribution['church_id'];
    if ($churchId <= 0) {
        throw new RuntimeException('Не удалось определить церковь для молитвенной нужды');
    }

    $pdo = db();
    $pdo->prepare(
        'INSERT INTO prayer_requests
            (person_id, group_id, church_id, title, description, submitter_name, is_anonymous, status, next_reminder_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, "new", CURDATE() + INTERVAL 14 DAY, ?)'
    )->execute([
        $attribution['person_id'],
        $attribution['group_id'],
        $churchId,
        $title,
        $description !== '' ? $description : null,
        $attribution['submitter_name'],
        $attribution['is_anonymous'],
        $attribution['created_by'],
    ]);

    return (int) $pdo->lastInsertId();
}

function prayer_request_public_page(): void
{
    $church = current_church();
    if (!$church || church_is_deleted($church)) {
        http_response_code(404);
        exit('Страница не найдена');
    }

    $error = null;
    $sent = false;
    $user = current_user();
    $linked = $user
        ? prayer_public_linked_person_for_church(person_linked_to_user((int) $user['id']), (int) $church['id'])
        : null;
    $isLoggedIn = $user !== null;

    $titleValue = '';
    $descriptionValue = '';
    $fullNameValue = '';
    $anonymousChecked = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $titleValue = trim((string) ($_POST['title'] ?? ''));
        $descriptionValue = trim((string) ($_POST['description'] ?? ''));
        $fullNameValue = trim((string) ($_POST['full_name'] ?? ''));
        $anonymousChecked = !$isLoggedIn && !empty($_POST['is_anonymous']);

        if (!throttle_action('prayer-public:' . ($_SERVER['REMOTE_ADDR'] ?? 'local'), 8, 600)) {
            $error = 'Слишком много попыток. Попробуйте позже.';
        } elseif ($titleValue === '') {
            $error = 'Напишите, о чём нужно молиться.';
        } elseif (mb_strlen($titleValue) > 190) {
            $error = 'Краткое описание нужды слишком длинное.';
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
                $prayerId = prayer_public_insert($titleValue, $descriptionValue, $attribution);
                audit('prayer_public_add', 'prayer', $prayerId, [
                    'anonymous' => (bool) $attribution['is_anonymous'],
                    'logged_in' => $isLoggedIn,
                    'church_id' => (int) $attribution['church_id'],
                ]);
                $sent = true;
                $titleValue = '';
                $descriptionValue = '';
                $fullNameValue = '';
                $anonymousChecked = false;
            }
        }
    }

    $loginHref = '/' . rawurlencode((string) $church['slug']) . '/login';
    $miracleHref = public_miracle_share_path($church);
    $joinHref = function_exists('homegroup_join_base_path')
        ? homegroup_join_base_path($church)
        : ('/' . rawurlencode((string) $church['slug']) . '/homegroup/join');
    $brandCity = trim((string) ($church['city'] ?? ''));
    if ($brandCity === '') {
        $brandCity = (string) ($church['name'] ?? '');
    }

    public_page_header('Молитвенная нужда');
    echo '<div class="public-shell prayer-public-shell">';
    echo '<form class="login-card prayer-public-card" method="post" data-prayer-public-form>';
    echo render_brand_markup(['variant' => 'hero', 'church' => $church, 'subtitle' => $brandCity !== '' ? $brandCity : null]);
    echo '<h1>Молитвенная нужда</h1>';
    echo '<p class="muted login-subtitle">Напишите, о чём нужна молитва. Служители церкви «' . e((string) $church['name']) . '» увидят нужду и будут молиться.</p>';

    if ($sent) {
        echo '<div class="flash">Спасибо! Ваша молитвенная нужда принята.</div>';
    }
    if ($error) {
        echo '<div class="flash error">' . e($error) . '</div>';
    }

    echo csrf_field();
    echo '<label>О чём молиться<input name="title" required maxlength="190" value="' . e($titleValue) . '" placeholder="Кратко: здоровье, работа, семья…"></label>';
    echo '<label>Подробности<textarea name="description" rows="5" placeholder="Можно рассказать подробнее (необязательно)">' . e($descriptionValue) . '</textarea></label>';

    if ($isLoggedIn) {
        $displayName = $linked
            ? (string) $linked['full_name']
            : trim((string) ($user['name'] ?? ''));
        echo '<p class="prayer-public-from">Нужда будет записана от вашего имени: <strong>' . e($displayName !== '' ? $displayName : 'пользователь') . '</strong>';
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

    echo '<button class="primary full" type="submit">Отправить нужду</button>';
    echo '<p class="muted prayer-public-links">';
    if ($isLoggedIn) {
        echo '<a href="' . e(app_path('/')) . '">В CRM</a> · <a href="' . e(app_path('/prayers')) . '">Молитвенные нужды</a> · <a href="' . e(app_path('/miracles')) . '">Чудеса</a>';
    } else {
        echo '<a href="' . e($loginHref) . '">Войти</a> · <a href="' . e($miracleHref) . '">Свидетельство</a> · <a href="' . e($joinHref) . '">Заявка в домашнюю группу</a>';
    }
    echo '</p></form></div>';
    public_page_footer();
}
