<?php

declare(strict_types=1);


function normalize_user_login(string $login): string
{
    $login = mb_strtolower(trim($login));
    $login = preg_replace('/[^a-z0-9._-]+/u', '-', $login) ?: '';
    $login = trim($login, '.-_');
    return $login !== '' ? mb_substr($login, 0, 80) : 'user';
}

function login_from_email(string $email): string
{
    $local = strstr($email, '@', true);
    return normalize_user_login($local !== false ? $local : $email);
}

function unique_user_login(string $base, ?int $excludeUserId = null): string
{
    $base = normalize_user_login($base);
    $login = $base;
    $suffix = 2;
    while (true) {
        $params = [$login];
        $sql = 'SELECT id FROM users WHERE login = ?';
        if ($excludeUserId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeUserId;
        }
        if (!fetch_one($sql . ' LIMIT 1', $params)) {
            return $login;
        }
        $tail = '-' . $suffix++;
        $login = mb_substr($base, 0, 80 - mb_strlen($tail)) . $tail;
    }
}

function email_owned_by_other(string $email, int $userId): bool
{
    return fetch_one('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1', [$email, $userId]) !== null;
}

function role_rank(string $role): int
{
    return [
        'member' => 10,
        'assistant' => 20,
        'leader' => 30,
        'district_leader' => 40,
        'pastor' => 50,
        'admin' => 60,
        'super_admin' => 70,
    ][$role] ?? 0;
}

/**
 * Roles an editor may assign at all (before transition checks).
 *
 * @return list<string>
 */
function roles_assignable_by(string $editorRole): array
{
    return match ($editorRole) {
        'leader' => ['member', 'assistant'],
        'district_leader' => ['member', 'assistant', 'leader'],
        // Pastor may appoint a church administrator (one-step promote: pastor → admin).
        'pastor' => ['member', 'assistant', 'leader', 'district_leader', 'pastor', 'admin'],
        'admin' => ['member', 'assistant', 'leader', 'district_leader', 'pastor', 'admin'],
        'super_admin' => function_exists('roles') ? roles() : ['member', 'assistant', 'leader', 'district_leader', 'pastor', 'admin', 'super_admin'],
        default => [],
    };
}

/**
 * Allowed source roles when changing a user TO $targetRole.
 * Explicit matrix for leader/district_leader; pastor/admin/super_admin =
 * promote one step within the assignable ladder + demote from any higher assignable role.
 *
 * @return list<string>
 */
function role_transition_sources(string $editorRole, string $targetRole): array
{
    if ($editorRole === 'leader') {
        return match ($targetRole) {
            'member' => ['assistant'],
            'assistant' => ['member'],
            default => [],
        };
    }

    if ($editorRole === 'district_leader') {
        return match ($targetRole) {
            'member' => ['assistant', 'leader'],
            'assistant' => ['member', 'leader'],
            'leader' => ['assistant', 'leader'],
            default => [],
        };
    }

    $assignable = roles_assignable_by($editorRole);
    if (!in_array($targetRole, $assignable, true)) {
        return [];
    }

    $sources = [];
    $targetRank = role_rank($targetRole);
    foreach ($assignable as $source) {
        $sourceRank = role_rank($source);
        if ($source === $targetRole) {
            $sources[] = $source;
            continue;
        }
        if ($sourceRank > $targetRank) {
            $sources[] = $source;
            continue;
        }
        // Promote only one step within the assignable ladder.
        if ($sourceRank < $targetRank) {
            $between = false;
            foreach ($assignable as $mid) {
                $midRank = role_rank($mid);
                if ($midRank > $sourceRank && $midRank < $targetRank) {
                    $between = true;
                    break;
                }
            }
            if (!$between) {
                $sources[] = $source;
            }
        }
    }

    return array_values(array_unique($sources));
}

function can_transition_role(string $editorRole, string $fromRole, string $toRole): bool
{
    $assignable = roles_assignable_by($editorRole);
    if (!in_array($toRole, $assignable, true)) {
        return false;
    }
    if ($fromRole === $toRole) {
        return true;
    }

    return in_array($fromRole, role_transition_sources($editorRole, $toRole), true);
}

function can_create_home_group(array $user): bool
{
    return in_array($user['role'], ['district_leader', 'pastor', 'admin', 'super_admin'], true);
}

function can_delete_home_group(array $user): bool
{
    return in_array($user['role'], ['district_leader', 'pastor', 'admin', 'super_admin'], true);
}

function can_edit_home_group_info(array $user, array $group): bool
{
    if (in_array($user['role'], ['district_leader', 'pastor', 'admin', 'super_admin'], true)) {
        return (int) $user['church_id'] === (int) ($group['church_id'] ?? 0);
    }

    if ($user['role'] === 'leader') {
        return (int) $user['group_id'] === (int) ($group['id'] ?? 0);
    }

    return false;
}

function can_assign_home_group_leader(array $user): bool
{
    return in_array($user['role'], ['district_leader', 'pastor', 'admin', 'super_admin'], true);
}

function person_group_memberships_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $ready = function_exists('db_table_exists') && db_table_exists('person_group_memberships');
    return $ready;
}

/**
 * @return list<int>
 */
function person_membership_group_ids(int $personId): array
{
    if ($personId <= 0) {
        return [];
    }
    if (person_group_memberships_ready()) {
        $rows = fetch_all('SELECT group_id FROM person_group_memberships WHERE person_id = ? ORDER BY is_primary DESC, group_id', [$personId]);
        $ids = array_map(static fn(array $row): int => (int) $row['group_id'], $rows);
        if ($ids !== []) {
            return $ids;
        }
    }
    $person = fetch_one('SELECT group_id FROM people WHERE id = ? LIMIT 1', [$personId]);
    $groupId = (int) ($person['group_id'] ?? 0);
    return $groupId > 0 ? [$groupId] : [];
}

function person_belongs_to_group(int $personId, int $groupId): bool
{
    if ($personId <= 0 || $groupId <= 0) {
        return false;
    }
    if (person_group_memberships_ready()) {
        $row = fetch_one('SELECT person_id FROM person_group_memberships WHERE person_id = ? AND group_id = ? LIMIT 1', [$personId, $groupId]);
        if ($row) {
            return true;
        }
    }
    $person = fetch_one('SELECT group_id FROM people WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$personId]);
    return (int) ($person['group_id'] ?? 0) === $groupId;
}

function person_sync_primary_group(int $personId, ?int $preferredGroupId = null): void
{
    if ($personId <= 0) {
        return;
    }
    if (!person_group_memberships_ready()) {
        if ($preferredGroupId !== null && $preferredGroupId > 0) {
            db()->prepare('UPDATE people SET group_id = ? WHERE id = ?')->execute([$preferredGroupId, $personId]);
        }
        return;
    }

    $memberships = fetch_all('SELECT group_id, is_primary FROM person_group_memberships WHERE person_id = ? ORDER BY is_primary DESC, group_id', [$personId]);
    if ($memberships === []) {
        db()->prepare('UPDATE people SET group_id = NULL, is_leader = 0 WHERE id = ?')->execute([$personId]);
        return;
    }

    $primaryId = null;
    if ($preferredGroupId !== null && $preferredGroupId > 0) {
        foreach ($memberships as $row) {
            if ((int) $row['group_id'] === $preferredGroupId) {
                $primaryId = $preferredGroupId;
                break;
            }
        }
    }
    if ($primaryId === null) {
        foreach ($memberships as $row) {
            if ((int) ($row['is_primary'] ?? 0) === 1) {
                $primaryId = (int) $row['group_id'];
                break;
            }
        }
    }
    if ($primaryId === null) {
        $primaryId = (int) $memberships[0]['group_id'];
    }

    db()->prepare('UPDATE person_group_memberships SET is_primary = CASE WHEN group_id = ? THEN 1 ELSE 0 END WHERE person_id = ?')
        ->execute([$primaryId, $personId]);
    db()->prepare('UPDATE people SET group_id = ? WHERE id = ?')->execute([$primaryId, $personId]);
}

function person_add_group_membership(int $personId, int $groupId, bool $makePrimary = false): void
{
    if ($personId <= 0 || $groupId <= 0) {
        throw new InvalidArgumentException('Некорректные данные членства');
    }
    $person = fetch_one('SELECT id, group_id FROM people WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$personId]);
    if (!$person) {
        throw new InvalidArgumentException('Карточка не найдена');
    }
    $group = fetch_one('SELECT id FROM home_groups WHERE id = ? AND is_active = 1 LIMIT 1', [$groupId]);
    if (!$group) {
        throw new InvalidArgumentException('Группа не найдена');
    }

    if (!person_group_memberships_ready()) {
        if ($makePrimary || (int) ($person['group_id'] ?? 0) <= 0) {
            db()->prepare('UPDATE people SET group_id = ? WHERE id = ?')->execute([$groupId, $personId]);
        }
        return;
    }

    db()->prepare(
        'INSERT INTO person_group_memberships (person_id, group_id, is_primary) VALUES (?, ?, 0)
         ON DUPLICATE KEY UPDATE person_id = person_id'
    )->execute([$personId, $groupId]);

    $shouldPrimary = $makePrimary || (int) ($person['group_id'] ?? 0) <= 0;
    person_sync_primary_group($personId, $shouldPrimary ? $groupId : null);
}

function person_remove_group_membership(int $personId, int $groupId): void
{
    if ($personId <= 0 || $groupId <= 0) {
        throw new InvalidArgumentException('Некорректные данные членства');
    }
    if (!person_group_memberships_ready()) {
        $person = fetch_one('SELECT group_id FROM people WHERE id = ? LIMIT 1', [$personId]);
        if ((int) ($person['group_id'] ?? 0) === $groupId) {
            db()->prepare('UPDATE people SET group_id = NULL WHERE id = ?')->execute([$personId]);
        }
        return;
    }

    $count = fetch_one('SELECT COUNT(*) AS c FROM person_group_memberships WHERE person_id = ?', [$personId]);
    if ((int) ($count['c'] ?? 0) <= 1 && person_belongs_to_group($personId, $groupId)) {
        throw new InvalidArgumentException('Нельзя удалить единственную группу человека');
    }

    db()->prepare('UPDATE home_groups SET leader_person_id = NULL WHERE id = ? AND leader_person_id = ?')->execute([$groupId, $personId]);
    db()->prepare('UPDATE home_groups SET assistant_person_id = NULL WHERE id = ? AND assistant_person_id = ?')->execute([$groupId, $personId]);
    db()->prepare('DELETE FROM person_group_memberships WHERE person_id = ? AND group_id = ?')->execute([$personId, $groupId]);
    person_sync_primary_group($personId);
}

/**
 * @return list<array<string, mixed>>
 */
function person_membership_rows(int $personId): array
{
    if ($personId <= 0) {
        return [];
    }
    if (person_group_memberships_ready()) {
        return fetch_all(
            'SELECT pgm.group_id, pgm.is_primary, g.name AS group_name, g.church_id
             FROM person_group_memberships pgm
             JOIN home_groups g ON g.id = pgm.group_id
             WHERE pgm.person_id = ?
             ORDER BY pgm.is_primary DESC, g.name',
            [$personId]
        );
    }
    $person = fetch_one(
        'SELECT p.group_id, g.name AS group_name, g.church_id
         FROM people p
         LEFT JOIN home_groups g ON g.id = p.group_id
         WHERE p.id = ? LIMIT 1',
        [$personId]
    );
    if (!$person || (int) ($person['group_id'] ?? 0) <= 0) {
        return [];
    }
    return [[
        'group_id' => (int) $person['group_id'],
        'is_primary' => 1,
        'group_name' => $person['group_name'] ?? '',
        'church_id' => $person['church_id'] ?? null,
    ]];
}

function people_in_group_sql(string $personAlias, string $groupIdPlaceholder = '?'): string
{
    if (person_group_memberships_ready()) {
        return "(
            $personAlias.group_id = $groupIdPlaceholder
            OR EXISTS (
                SELECT 1 FROM person_group_memberships _pgm
                WHERE _pgm.person_id = $personAlias.id AND _pgm.group_id = $groupIdPlaceholder
            )
        )";
    }
    return "$personAlias.group_id = $groupIdPlaceholder";
}

function resolve_home_group_location_from_post(): array
{
    return ChurchCare\resolve_group_location(
        isset($_POST['address']) ? (string) $_POST['address'] : null,
        static fn(string $query): ?array => yandex_geocode($query)
    );
}

function assign_home_group_leader(int $groupId, ?int $leaderPersonId): void
{
    $pdo = db();
    $current = fetch_one('SELECT leader_person_id FROM home_groups WHERE id = ? LIMIT 1', [$groupId]);
    $previousLeaderId = $current ? (int) ($current['leader_person_id'] ?? 0) : 0;

    if ($leaderPersonId === null || $leaderPersonId <= 0) {
        $pdo->prepare('UPDATE home_groups SET leader_person_id = NULL WHERE id = ?')->execute([$groupId]);
        if ($previousLeaderId > 0) {
            $pdo->prepare('UPDATE people SET is_leader = 0 WHERE id = ?')->execute([$previousLeaderId]);
        }
        return;
    }

    $person = fetch_one('SELECT id, user_id, group_id FROM people WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$leaderPersonId]);
    if (!$person || !person_belongs_to_group($leaderPersonId, $groupId)) {
        throw new InvalidArgumentException('Лидер должен быть участником этой группы');
    }

    $pdo->beginTransaction();
    try {
        if ($previousLeaderId > 0 && $previousLeaderId !== $leaderPersonId) {
            $pdo->prepare('UPDATE people SET is_leader = 0 WHERE id = ?')->execute([$previousLeaderId]);
            $oldUser = fetch_one('SELECT user_id FROM people WHERE id = ? LIMIT 1', [$previousLeaderId]);
            if ($oldUser && (int) ($oldUser['user_id'] ?? 0) > 0) {
                $pdo->prepare('UPDATE users SET role = "member" WHERE id = ? AND role = "leader"')->execute([(int) $oldUser['user_id']]);
            }
        }

        $pdo->prepare('UPDATE home_groups SET leader_person_id = ? WHERE id = ?')->execute([$leaderPersonId, $groupId]);
        $pdo->prepare('UPDATE people SET is_leader = 1, group_id = ? WHERE id = ?')->execute([$groupId, $leaderPersonId]);
        if (person_group_memberships_ready()) {
            $pdo->prepare(
                'INSERT INTO person_group_memberships (person_id, group_id, is_primary) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE is_primary = 1'
            )->execute([$leaderPersonId, $groupId]);
            $pdo->prepare('UPDATE person_group_memberships SET is_primary = CASE WHEN group_id = ? THEN 1 ELSE 0 END WHERE person_id = ?')
                ->execute([$groupId, $leaderPersonId]);
        }

        if ((int) ($person['user_id'] ?? 0) > 0) {
            $group = fetch_one('SELECT church_id FROM home_groups WHERE id = ? LIMIT 1', [$groupId]);
            $pdo->prepare('UPDATE users SET role = "leader", group_id = ?, church_id = ? WHERE id = ?')
                ->execute([$groupId, (int) ($group['church_id'] ?? 0), (int) $person['user_id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function delete_home_group(array $group): void
{
    $groupId = (int) $group['id'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE home_groups SET is_active = 0, closed_at = COALESCE(closed_at, CURDATE()) WHERE id = ?')
            ->execute([$groupId]);
        $pdo->prepare('UPDATE users SET group_id = NULL WHERE group_id = ?')
            ->execute([$groupId]);
        $pdo->prepare('UPDATE participant_requests SET recommended_group_id = NULL WHERE recommended_group_id = ?')
            ->execute([$groupId]);
        if (person_group_memberships_ready()) {
            $members = fetch_all('SELECT person_id FROM person_group_memberships WHERE group_id = ?', [$groupId]);
            $pdo->prepare('DELETE FROM person_group_memberships WHERE group_id = ?')->execute([$groupId]);
            foreach ($members as $member) {
                person_sync_primary_group((int) $member['person_id']);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function can_manage_users(array $user): bool
{
    return in_array($user['role'], ['leader', 'district_leader', 'pastor', 'admin', 'super_admin'], true);
}

function assignable_roles(array $user, ?string $currentRole = null): array
{
    $editorRole = (string) ($user['role'] ?? '');
    $pool = roles_assignable_by($editorRole);
    if ($pool === []) {
        return $currentRole ? [$currentRole] : [];
    }
    if ($currentRole === null || $currentRole === '') {
        return $pool;
    }

    $allowed = [];
    foreach ($pool as $role) {
        if (can_transition_role($editorRole, $currentRole, $role)) {
            $allowed[] = $role;
        }
    }

    return array_values(array_unique($allowed));
}

function user_scope_condition(array $user, string $alias = 'u'): array
{
    $role = (string) ($user['role'] ?? '');
    if (in_array($role, ['super_admin', 'admin', 'pastor', 'district_leader'], true)) {
        $churchId = function_exists('effective_scope_church_id') ? effective_scope_church_id($user) : null;
        if ($churchId !== null && $churchId > 0) {
            return ["$alias.church_id = ?", [$churchId]];
        }
        if ($role === 'super_admin') {
            return ['1=1', []];
        }
        $fallback = (int) ($user['church_id'] ?? 0);
        return $fallback > 0 ? ["$alias.church_id = ?", [$fallback]] : ['1=0', []];
    }
    if ($role === 'leader') {
        return ["$alias.group_id = ?", [$user['group_id']]];
    }
    return ["$alias.id = ?", [$user['id']]];
}

function can_edit_user_record(array $editor, array $target): bool
{
    if (!can_manage_users($editor)) {
        return (int) $editor['id'] === (int) $target['id'];
    }
    if (($editor['role'] ?? '') === 'super_admin') {
        return true;
    }
    if ($editor['role'] === 'admin') {
        return (int) ($editor['church_id'] ?? 0) === (int) ($target['church_id'] ?? -1);
    }
    if ($editor['role'] === 'pastor') {
        // Edit scope follows the assignable ladder (including peer pastors and church admins).
        return (int) $editor['church_id'] === (int) ($target['church_id'] ?? 0)
            && in_array((string) ($target['role'] ?? ''), roles_assignable_by('pastor'), true);
    }
    if ($editor['role'] === 'district_leader') {
        return (int) $editor['church_id'] === (int) ($target['church_id'] ?? 0) && role_rank((string) $target['role']) < role_rank('district_leader');
    }
    if ($editor['role'] === 'leader') {
        if (role_rank((string) $target['role']) > role_rank('assistant')) {
            return false;
        }
        $editorGroupId = (int) ($editor['group_id'] ?? 0);
        if ($editorGroupId <= 0) {
            return false;
        }
        if ((int) ($target['group_id'] ?? 0) === $editorGroupId) {
            return true;
        }
        if (function_exists('fetch_one')) {
            $person = person_linked_to_user((int) $target['id']);
            if ($person && person_belongs_to_group((int) $person['id'], $editorGroupId)) {
                return true;
            }
        }
        return false;
    }
    return false;
}

function can_view_user_record(array $viewer, array $target): bool
{
    if ((int) $viewer['id'] === (int) $target['id']) {
        return true;
    }

    return can_edit_user_record($viewer, $target);
}

function user_card_href(int $userId, ?array $viewer = null): string
{
    if ($viewer !== null && (int) ($viewer['id'] ?? 0) === $userId) {
        return app_path('/profile');
    }

    return app_path('/user?id=' . $userId);
}

/**
 * Pastoral person card linked to a CRM user, if any.
 *
 * @return array{id:int|string, full_name:string, group_id?:int|string, church_id?:int|string, group_name:string}|null
 */
function person_linked_to_user(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    return fetch_one(
        'SELECT p.id, p.full_name, p.group_id, g.church_id, g.name AS group_name
         FROM people p
         JOIN home_groups g ON g.id = p.group_id
         WHERE p.user_id = ? AND p.deleted_at IS NULL
         LIMIT 1',
        [$userId]
    );
}

/**
 * User row with church/group labels for account card UIs.
 */
function account_display_row(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    return fetch_one(
        'SELECT u.*, c.name AS church_name, g.name AS group_name
         FROM users u
         LEFT JOIN churches c ON c.id = u.church_id
         LEFT JOIN home_groups g ON g.id = u.group_id
         WHERE u.id = ?
         LIMIT 1',
        [$userId]
    );
}

/**
 * @param list<array{href:string,label:string}> $actions
 */
function render_account_hero(array $account, array $actions = [], string $badge = 'Учетная запись'): void
{
    $eyebrow = array_filter([
        $account['church_name'] ?? null,
        $account['group_name'] ?? null,
    ]);
    echo '<section class="person-hero panel account-hero"><div class="person-hero-main"><div>';
    echo '<p class="eyebrow">' . e(implode(' · ', $eyebrow) ?: 'Без группы') . '</p>';
    echo '<h2>' . e((string) ($account['name'] ?? '')) . '</h2>';
    echo '<p class="muted">' . e(role_label((string) ($account['role'] ?? 'member')));
    echo ' · ' . e((string) ($account['login'] ?? ''));
    echo ' · 2FA ' . (!empty($account['two_factor_enabled']) ? 'включена' : 'выключена');
    echo '</p></div></div><div class="person-hero-actions">';
    echo '<span class="status-badge registered">' . e($badge) . '</span>';
    foreach ($actions as $action) {
        $href = (string) ($action['href'] ?? '');
        $label = (string) ($action['label'] ?? '');
        if ($href === '' || $label === '') {
            continue;
        }
        echo '<a class="ghost" href="' . e($href) . '">' . e($label) . '</a>';
    }
    echo '</div></section>';
}

function render_account_person_panel(?array $person, bool $selfService = false, bool $asPanel = true): void
{
    if ($asPanel) {
        echo '<article class="panel"><h2>Пастырская карточка</h2><div class="stack">';
    }
    if ($person) {
        echo '<p class="muted">Связана карточка <strong>' . e((string) $person['full_name']) . '</strong>';
        if (!empty($person['group_name'])) {
            echo ' (' . e((string) $person['group_name']) . ')';
        }
        echo '.</p>';
        echo '<a class="primary" href="' . e(app_path('/person?id=' . (int) $person['id'])) . '">' . ($selfService ? 'Открыть мою карточку' : 'Открыть карточку') . '</a>';
    } elseif ($selfService) {
        echo '<p class="muted">Пастырская карточка пока не привязана к этой учетной записи. Контакты и посещаемость ведутся в карточке человека отдельно.</p>';
    } else {
        echo '<p class="muted">Пастырская карточка не привязана. Участник может войти в CRM, но контакты и посещаемость ведутся отдельно.</p>';
    }
    if ($asPanel) {
        echo '</div></article>';
    }
}

function scoped_user(array $viewer, int $userId): ?array
{
    [$sql, $params] = user_scope_condition($viewer, 'u');
    $params[] = $userId;
    $target = fetch_one(
        "SELECT u.*, c.name AS church_name, g.name AS group_name
         FROM users u
         LEFT JOIN churches c ON c.id = u.church_id
         LEFT JOIN home_groups g ON g.id = u.group_id
         WHERE $sql AND u.id = ?",
        $params
    );
    if (!$target || !can_view_user_record($viewer, $target)) {
        return null;
    }

    return $target;
}

function user_admin_return_path(array $target): string
{
    return match (trim((string) ($_POST['return'] ?? ''))) {
        'user' => user_card_href((int) $target['id']),
        'admin' => function_exists('platform_path') ? platform_path('/admin') : '/admin',
        'people' => people_hub_url([], 'people'),
        'accounts', 'users' => people_accounts_hub_path(),
        default => people_accounts_hub_path(),
    };
}

function can_delete_user(array $editor, array $target): bool
{
    if (!can_action($editor, 'manage_structure')) {
        return false;
    }
    if ((int) $editor['id'] === (int) $target['id']) {
        return false;
    }
    return can_edit_user_record($editor, $target);
}

function delete_user_account(int $userId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE people SET user_id = NULL WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('UPDATE tasks SET assigned_to = NULL WHERE assigned_to = ?')->execute([$userId]);
        $pdo->prepare('UPDATE meetings SET created_by = NULL WHERE created_by = ?')->execute([$userId]);
        $pdo->prepare('UPDATE prayer_requests SET created_by = NULL WHERE created_by = ?')->execute([$userId]);
        $pdo->prepare('UPDATE miracles SET created_by = NULL WHERE created_by = ?')->execute([$userId]);
        $pdo->prepare('UPDATE contact_logs SET user_id = NULL WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('UPDATE person_change_logs SET user_id = NULL WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('UPDATE meeting_reports SET submitted_by = NULL WHERE submitted_by = ?')->execute([$userId]);
        $pdo->prepare('UPDATE meeting_reports SET sent_to_user_id = NULL WHERE sent_to_user_id = ?')->execute([$userId]);
        $pdo->prepare('UPDATE data_deletion_requests SET requested_by = NULL WHERE requested_by = ?')->execute([$userId]);
        $pdo->prepare('UPDATE backup_runs SET user_id = NULL WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('UPDATE import_batches SET user_id = NULL WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('UPDATE app_settings SET updated_by = NULL WHERE updated_by = ?')->execute([$userId]);
        $pdo->prepare('UPDATE participant_requests SET handled_by = NULL WHERE handled_by = ?')->execute([$userId]);
        $pdo->prepare('UPDATE account_invitations SET created_by = NULL WHERE created_by = ?')->execute([$userId]);
        $pdo->prepare('UPDATE email_notifications SET user_id = NULL WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('UPDATE audit_logs SET user_id = NULL WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('UPDATE oauth_states SET user_id = NULL WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('UPDATE bot_inbound_messages SET user_id = NULL WHERE user_id = ?')->execute([$userId]);

        $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM social_identities WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM bot_accounts WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM bot_link_tokens WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM web_push_subscriptions WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM notification_events WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM user_notification_settings WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function delete_person_account_card(array $person): void
{
    $personId = (int) $person['id'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE home_groups SET leader_person_id = NULL WHERE leader_person_id = ?')->execute([$personId]);
        $pdo->prepare('UPDATE home_groups SET assistant_person_id = NULL WHERE assistant_person_id = ?')->execute([$personId]);
        $pdo->prepare('UPDATE meetings SET host_person_id = NULL WHERE host_person_id = ?')->execute([$personId]);
        $pdo->prepare('UPDATE prayer_requests SET person_id = NULL WHERE person_id = ?')->execute([$personId]);
        $pdo->prepare('UPDATE miracles SET person_id = NULL WHERE person_id = ?')->execute([$personId]);
        $pdo->prepare('UPDATE tasks SET person_id = NULL WHERE person_id = ?')->execute([$personId]);
        $pdo->prepare('DELETE FROM service_schedule WHERE person_id = ?')->execute([$personId]);
        $pdo->prepare('UPDATE participant_requests SET person_id = NULL WHERE person_id = ?')->execute([$personId]);
        $pdo->prepare('UPDATE account_invitations SET used_at = COALESCE(used_at, NOW()) WHERE person_id = ? AND used_at IS NULL')->execute([$personId]);
        $pdo->prepare('UPDATE people SET user_id = NULL, is_leader = 0, is_potential_leader = 0, deleted_at = NOW() WHERE id = ?')->execute([$personId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function queue_email_notification(?int $userId, string $toEmail, string $subject, string $body, string $type = 'system'): void
{
    if (!$toEmail) {
        return;
    }
    if (function_exists('demo_queue_email_allowed') && !demo_queue_email_allowed($userId)) {
        return;
    }

    db()->prepare('INSERT INTO email_notifications (user_id, to_email, subject, body, type, status) VALUES (?, ?, ?, ?, ?, "pending")')
        ->execute([$userId, $toEmail, $subject, $body, $type]);
}

function notify_role_change(array $target, string $role): void
{
    queue_email_notification((int) $target['id'], (string) $target['email'], 'Роль в CRM обновлена', 'Ваша роль в ' . app_name() . ' изменена на: ' . role_label($role), 'role_changed');
}

function create_member_account_for_person(array $person, string $email, ?string $login = null, ?string $password = null): array
{
    $email = trim($email);
    $group = fetch_one('SELECT * FROM home_groups WHERE id = ? LIMIT 1', [(int) $person['group_id']]);
    if (!$group) {
        throw new InvalidArgumentException('Группа для учетной записи не найдена');
    }
    $churchId = (int) ($group['church_id'] ?? 0);
    if (function_exists('church_is_demo_by_id') && church_is_demo_by_id($churchId)) {
        $email = demo_sanitize_account_email($email, $churchId);
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Укажите корректный email для учетной записи');
    }
    if (fetch_one('SELECT id FROM users WHERE email = ? LIMIT 1', [$email])) {
        throw new InvalidArgumentException('Почта уже используется другим пользователем');
    }

    $login = unique_user_login($login ?: login_from_email($email));
    $passwordHash = password_hash($password ?? bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
    db()->prepare('INSERT INTO users (name, login, email, password_hash, role, church_id, group_id) VALUES (?, ?, ?, ?, "member", ?, ?)')
        ->execute([
            $person['full_name'],
            $login,
            $email,
            $passwordHash,
            $churchId,
            (int) $group['id'],
        ]);

    $userId = (int) db()->lastInsertId();
    db()->prepare('UPDATE people SET user_id = ?, email = ? WHERE id = ?')->execute([$userId, $email, (int) $person['id']]);
    db()->prepare('INSERT IGNORE INTO user_notification_settings (user_id) VALUES (?)')->execute([$userId]);

    return fetch_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?: ['id' => $userId, 'login' => $login, 'email' => $email];
}

function create_password_setup_link(int $userId): string
{
    $token = bin2hex(random_bytes(24));
    db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))')
        ->execute([$userId, hash('sha256', $token)]);
    return '/reset-password?token=' . urlencode($token);
}

function can_invite_person_account(array $user): bool
{
    return can_manage_users($user);
}

function person_links_by_user_id(array $user): array
{
    [$groupSql, $groupParams] = accessible_group_sql($user, 'g');
    $rows = fetch_all(
        "SELECT p.id, p.user_id
         FROM people p
         JOIN home_groups g ON g.id = p.group_id
         WHERE $groupSql AND p.deleted_at IS NULL AND p.user_id IS NOT NULL",
        $groupParams
    );
    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['user_id']] = (int) $row['id'];
    }

    return $map;
}

/**
 * Church row for a pastoral person card (via home group), or null.
 *
 * @return array<string, mixed>|null
 */
function account_invite_church_for_person(int $personId): ?array
{
    if ($personId <= 0) {
        return null;
    }
    try {
        return fetch_one(
            'SELECT c.*
             FROM people p
             INNER JOIN home_groups g ON g.id = p.group_id
             INNER JOIN churches c ON c.id = g.church_id
             WHERE p.id = ? AND p.deleted_at IS NULL AND c.deleted_at IS NULL
             LIMIT 1',
            [$personId]
        );
    } catch (Throwable) {
        return null;
    }
}

/**
 * Public account-invite path. Prefers /{church-slug}/account-invite for tenant URLs.
 */
function account_invite_public_path(string $token, ?array $church = null): string
{
    $token = trim($token);
    if ($token === '') {
        return '';
    }
    $query = 'token=' . rawurlencode($token);
    $slug = strtolower(trim((string) ($church['slug'] ?? '')));
    $isDemo = (int) ($church['is_demo'] ?? 0) === 1
        || (function_exists('is_demo_church') && is_demo_church($church));
    if (
        $slug !== ''
        && function_exists('church_slug_is_valid')
        && church_slug_is_valid($slug)
        && !$isDemo
    ) {
        return '/' . $slug . '/account-invite?' . $query;
    }

    return '/account-invite?' . $query;
}

function create_account_invite_link(int $personId, int $createdBy): string
{
    $token = bin2hex(random_bytes(24));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE account_invitations SET used_at = NOW() WHERE person_id = ? AND used_at IS NULL')
            ->execute([$personId]);
        $pdo->prepare('INSERT INTO account_invitations (person_id, token_hash, token_cipher, created_by, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 14 DAY))')
            ->execute([$personId, hash('sha256', $token), encrypt_sensitive_note($token), $createdBy]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return account_invite_public_path($token, account_invite_church_for_person($personId));
}

function active_account_invite_for_person(int $personId): ?array
{
    $row = fetch_one(
        'SELECT id, token_cipher, expires_at FROM account_invitations WHERE person_id = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1',
        [$personId]
    );
    if (!$row) {
        return null;
    }

    $token = decrypt_sensitive_note($row['token_cipher'] ?? null);
    if ($token === '' || !preg_match('/^[a-f0-9]{48}$/', $token)) {
        return [
            'id' => (int) $row['id'],
            'url' => null,
            'expires_at' => (string) $row['expires_at'],
            'recoverable' => false,
        ];
    }

    $path = account_invite_public_path($token, account_invite_church_for_person($personId));

    return [
        'id' => (int) $row['id'],
        'url' => $path !== '' ? public_url($path) : null,
        'expires_at' => (string) $row['expires_at'],
        'recoverable' => true,
    ];
}


function role_invite_roles_for_user(array $user): array
{
    return match ((string) ($user['role'] ?? 'member')) {
        'leader' => ['assistant'],
        'district_leader' => ['assistant', 'leader'],
        'pastor' => ['assistant', 'leader', 'district_leader', 'admin'],
        'admin' => ['assistant', 'leader', 'district_leader', 'pastor'],
        'super_admin' => ['assistant', 'leader', 'district_leader', 'pastor', 'admin'],
        default => [],
    };
}

function role_invite_role_requires_group(string $role): bool
{
    return in_array($role, ['assistant', 'leader'], true);
}

function role_invite_can_issue_role(array $issuer, string $role): bool
{
    $hasHomeChurch = (int) ($issuer['church_id'] ?? 0) > 0;
    $isSuper = function_exists('is_super_admin') && is_super_admin($issuer);
    if (!$hasHomeChurch && !$isSuper) {
        return false;
    }
    if (!in_array($role, role_invite_roles_for_user($issuer), true)) {
        return false;
    }
    return !($issuer['role'] === 'leader' && role_invite_role_requires_group($role) && (int) ($issuer['group_id'] ?? 0) <= 0);
}

/**
 * Whether the issuer may create role invites that register people into $churchId.
 */
function role_invite_can_issue_for_church(array $issuer, int $churchId): bool
{
    if ($churchId <= 0) {
        return false;
    }
    if (function_exists('is_super_admin') && is_super_admin($issuer)) {
        return true;
    }

    return (int) ($issuer['church_id'] ?? 0) === $churchId;
}

/**
 * Church the invite binds to: current tenant when the issuer may manage it,
 * otherwise the issuer's home church (only when no tenant is bound).
 *
 * @return array<string, mixed>|null
 */
function role_invite_target_church(array $issuer): ?array
{
    $current = function_exists('current_church') ? current_church() : null;
    $tenantBound = is_array($current)
        && !(function_exists('church_is_deleted') && church_is_deleted($current))
        && (int) ($current['id'] ?? 0) > 0;

    if ($tenantBound) {
        $isDemo = (int) ($current['is_demo'] ?? 0) === 1
            || (function_exists('is_demo_church') && is_demo_church($current));
        if ($isDemo) {
            return null;
        }
        if (!role_invite_can_issue_for_church($issuer, (int) $current['id'])) {
            // On /{slug}/… never fall back to another church — that leaked Pushkino links on Korolev.
            return null;
        }

        return $current;
    }

    $homeId = (int) ($issuer['church_id'] ?? 0);
    if ($homeId <= 0) {
        return null;
    }
    $slug = strtolower(trim((string) ($issuer['church_slug'] ?? '')));
    if ($slug !== '' && function_exists('church_slug_is_valid') && church_slug_is_valid($slug)) {
        return [
            'id' => $homeId,
            'slug' => $slug,
            'is_demo' => 0,
            'deleted_at' => null,
        ];
    }
    if (function_exists('find_church_by_id')) {
        try {
            $church = find_church_by_id($homeId);
        } catch (Throwable) {
            $church = null;
        }
        if ($church) {
            $isDemo = (int) ($church['is_demo'] ?? 0) === 1
                || (function_exists('is_demo_church') && is_demo_church($church));

            return $isDemo ? null : $church;
        }
    }

    // No tenant and no resolvable church row: still bind to home church_id (platform /role-invite).
    return [
        'id' => $homeId,
        'slug' => '',
        'is_demo' => 0,
        'deleted_at' => null,
    ];
}

function role_invite_base64url_decode(string $value): ?string
{
    $remainder = strlen($value) % 4;
    if ($remainder > 0) {
        $value .= str_repeat('=', 4 - $remainder);
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return $decoded === false ? null : $decoded;
}

function role_invite_token(array $issuer, string $role): string
{
    if (!role_invite_can_issue_role($issuer, $role)) {
        return '';
    }
    $target = role_invite_target_church($issuer);
    $churchId = (int) ($target['id'] ?? 0);
    if ($churchId <= 0 || !role_invite_can_issue_for_church($issuer, $churchId)) {
        return '';
    }
    $payload = rtrim(strtr(base64_encode(json_encode([
        'v' => 2,
        'by' => (int) $issuer['id'],
        'role' => $role,
        'church_id' => $churchId,
    ], JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', $payload, (string) app_config('security.encryption_key'));
    return $payload . '.' . $signature;
}

/**
 * Slug for the invite target church (tenant page or issuer home).
 */
function role_invite_church_slug(array $issuer): string
{
    $target = role_invite_target_church($issuer);
    $slug = strtolower(trim((string) ($target['slug'] ?? '')));

    return ($slug !== '' && function_exists('church_slug_is_valid') && church_slug_is_valid($slug))
        ? $slug
        : '';
}

/**
 * Public path for a role-invite token. Prefers /{church-slug}/role-invite so each
 * church has its own registration URL; falls back to platform /role-invite.
 */
function role_invite_public_path(array $issuer, string $token): string
{
    $token = trim($token);
    if ($token === '') {
        return '';
    }
    $query = 'token=' . rawurlencode($token);
    $slug = role_invite_church_slug($issuer);
    if ($slug !== '') {
        return '/' . $slug . '/role-invite?' . $query;
    }

    return '/role-invite?' . $query;
}

function role_invite_context_from_token(string $token): ?array
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        return null;
    }
    [$payload, $signature] = $parts;
    $expected = hash_hmac('sha256', $payload, (string) app_config('security.encryption_key'));
    if (!hash_equals($expected, $signature)) {
        return null;
    }
    $json = role_invite_base64url_decode($payload);
    $data = $json !== null ? json_decode($json, true) : null;
    if (!is_array($data)) {
        return null;
    }
    $version = (int) ($data['v'] ?? 0);
    if (!in_array($version, [1, 2], true)) {
        return null;
    }
    $role = (string) ($data['role'] ?? '');
    $issuer = fetch_one('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) ($data['by'] ?? 0)]);
    if (!$issuer || !role_invite_can_issue_role($issuer, $role)) {
        return null;
    }

    if ($version >= 2) {
        $churchId = (int) ($data['church_id'] ?? 0);
        if ($churchId <= 0 || !role_invite_can_issue_for_church($issuer, $churchId)) {
            return null;
        }
    } else {
        $churchId = (int) ($issuer['church_id'] ?? 0);
        if ($churchId <= 0) {
            return null;
        }
    }

    $groupId = null;
    if (role_invite_role_requires_group($role)) {
        $issuerGroupId = (int) ($issuer['group_id'] ?? 0);
        // Only attach the issuer's group when it belongs to the invite target church.
        if ($issuerGroupId > 0 && (int) ($issuer['church_id'] ?? 0) === $churchId) {
            $groupId = $issuerGroupId;
        }
    }

    return [
        'issuer' => $issuer,
        'role' => $role,
        'church_id' => $churchId,
        'group_id' => $groupId,
    ];
}

/**
 * When the invite is opened under /{slug}/role-invite, the slug must match the token church.
 * Platform /role-invite (no tenant) remains valid for legacy shared links.
 */
function role_invite_matches_request_church(?array $context): bool
{
    if ($context === null) {
        return false;
    }
    $church = function_exists('current_church') ? current_church() : null;
    if (!$church || (function_exists('church_is_deleted') && church_is_deleted($church))) {
        return true;
    }

    return (int) ($church['id'] ?? 0) === (int) ($context['church_id'] ?? 0);
}

function role_invite_url(array $issuer, string $role): string
{
    $token = role_invite_token($issuer, $role);
    if ($token === '') {
        return '';
    }
    $path = role_invite_public_path($issuer, $token);

    return $path !== '' ? public_url($path) : '';
}

function role_invite_storage_ready(): bool
{
    return function_exists('db_table_exists') && db_table_exists('role_invitation_requests');
}

function role_invite_request_with_user(int $id): ?array
{
    if (!role_invite_storage_ready()) {
        return null;
    }

    return fetch_one(
        'SELECT r.*, u.name AS user_name, u.email AS user_email, u.login AS user_login,
                inviter.name AS invited_by_name, g.name AS group_name
         FROM role_invitation_requests r
         JOIN users u ON u.id = r.user_id
         LEFT JOIN users inviter ON inviter.id = r.invited_by
         LEFT JOIN home_groups g ON g.id = r.group_id
         WHERE r.id = ?
         LIMIT 1',
        [$id]
    );
}

function can_approve_role_invite_request(array $approver, array $request): bool
{
    if (($request['status'] ?? '') !== 'pending') {
        return false;
    }

    $role = (string) ($approver['role'] ?? '');
    if ($role === 'super_admin') {
        return true;
    }

    $sameChurch = (int) ($approver['church_id'] ?? 0) === (int) ($request['church_id'] ?? 0)
        && (int) ($approver['church_id'] ?? 0) > 0;

    if ($role === 'admin') {
        return $sameChurch;
    }

    if ($role === 'pastor') {
        // Pastor may approve any role they are allowed to invite (including administrator).
        return $sameChurch
            && in_array((string) ($request['requested_role'] ?? ''), role_invite_roles_for_user($approver), true);
    }

    return false;
}

function role_invite_pending_requests_for(array $approver): array
{
    if (!in_array($approver['role'], ['pastor', 'admin', 'super_admin'], true)) {
        return [];
    }
    if (!role_invite_storage_ready()) {
        return [];
    }

    $where = 'r.status = "pending"';
    $params = [];
    if (in_array($approver['role'], ['pastor', 'admin'], true)) {
        $where .= ' AND r.church_id = ?';
        $params[] = (int) $approver['church_id'];
    }

    return fetch_all(
        "SELECT r.*, u.name AS user_name, u.email AS user_email, u.login AS user_login,
                inviter.name AS invited_by_name, g.name AS group_name
         FROM role_invitation_requests r
         JOIN users u ON u.id = r.user_id
         LEFT JOIN users inviter ON inviter.id = r.invited_by
         LEFT JOIN home_groups g ON g.id = r.group_id
         WHERE $where
         ORDER BY r.created_at DESC",
        $params
    );
}

/**
 * Active home groups offered when approving a role invite.
 * Pass $forChurchId (usually the request's church) so the dropdown matches the
 * invite even if a super_admin is viewing another tenant URL.
 *
 * @return list<array{id:int|string,name:string,church_id:int|string}>
 */
function role_invite_approval_groups(array $approver, ?int $forChurchId = null): array
{
    $role = (string) ($approver['role'] ?? '');
    $churchId = null;

    if ($forChurchId !== null && $forChurchId > 0) {
        if ($role === 'super_admin') {
            $churchId = $forChurchId;
        } elseif (in_array($role, ['admin', 'pastor'], true) && (int) ($approver['church_id'] ?? 0) === $forChurchId) {
            $churchId = $forChurchId;
        } else {
            return [];
        }
    } elseif ($role === 'super_admin') {
        $tenantId = current_church_id();
        if ($tenantId) {
            $churchId = (int) $tenantId;
        } else {
            return fetch_all('SELECT id, name, church_id FROM home_groups WHERE is_active = 1 ORDER BY name');
        }
    } elseif (in_array($role, ['admin', 'pastor'], true)) {
        $churchId = (int) ($approver['church_id'] ?? 0);
    } else {
        return [];
    }

    if ($churchId === null || $churchId <= 0) {
        return [];
    }

    return fetch_all(
        'SELECT id, name, church_id FROM home_groups WHERE church_id = ? AND is_active = 1 ORDER BY name',
        [$churchId]
    );
}

function participant_requests_new_count(?array $user = null): int
{
    $churchId = null;
    if (is_array($user)) {
        $churchId = effective_scope_church_id($user);
    }
    if ($churchId === null) {
        $churchId = current_church_id();
    }
    if ($churchId !== null && $churchId > 0) {
        [$churchSql, $churchParams] = church_id_scope_sql('church_id', $churchId);
        $row = fetch_one(
            "SELECT COUNT(*) AS count FROM participant_requests WHERE status = \"new\" AND $churchSql",
            $churchParams
        );
    } else {
        $row = fetch_one('SELECT COUNT(*) AS count FROM participant_requests WHERE status = "new"');
    }
    return (int) ($row['count'] ?? 0);
}

function participant_requests_link(int $count): string
{
    $badge = $count > 0 ? ' <span class="status-badge pending">' . $count . '</span>' : '';
    return '<a class="ghost" href="' . e(app_path('/participant-requests')) . '">Заявки участников' . $badge . '</a>';
}

function role_requests_link(int $count): string
{
    $badge = $count > 0 ? ' <span class="status-badge pending">' . $count . '</span>' : '';
    return '<a class="ghost" href="' . e(app_path('/role-requests')) . '">Заявки на роли' . $badge . '</a>';
}

function role_invite_pending_count(array $approver): int
{
    return count(role_invite_pending_requests_for($approver));
}

function people_hub_view(?string $view): string
{
    return $view === 'accounts' ? 'accounts' : 'people';
}

/**
 * Logical /users path (no church slug). Prefer people_hub_url() for hrefs.
 *
 * @param array<string, mixed> $filters
 */
function people_hub_logical_path(array $filters = [], string $view = 'people'): string
{
    $filters = people_list_normalize_filters($filters);
    $view = people_hub_view($view);
    $params = [];
    if ($view === 'accounts') {
        $params['view'] = 'accounts';
    }
    if ($filters['q'] !== '') {
        $params['q'] = $filters['q'];
    }
    if ($filters['group_id'] !== null) {
        $params['group_id'] = $filters['group_id'];
    }
    if ($view === 'people' && $filters['registered'] !== '') {
        $params['registered'] = $filters['registered'];
    }
    if ($filters['role'] !== '') {
        $params['role'] = $filters['role'];
    }
    if ($view === 'people' && $filters['spiritual_stage_id'] !== null) {
        $params['spiritual_stage_id'] = $filters['spiritual_stage_id'];
    }
    if ($view === 'people' && $filters['flag'] !== '') {
        $params['flag'] = $filters['flag'];
    }

    return $params === [] ? '/users' : '/users?' . http_build_query($params);
}

/**
 * Tenant-aware /{slug}/users URL for people hub links and redirects.
 *
 * @param array<string, mixed> $filters
 */
function people_hub_url(array $filters = [], string $view = 'people'): string
{
    $path = people_hub_logical_path($filters, $view);

    return function_exists('app_path') ? app_path($path) : $path;
}

function people_accounts_hub_path(): string
{
    return people_hub_url([], 'accounts');
}

/**
 * @param array<string, mixed> $filters
 */
function people_list_advanced_filters_active(array $filters): bool
{
    $filters = people_list_normalize_filters($filters);
    return $filters['role'] !== ''
        || $filters['spiritual_stage_id'] !== null
        || $filters['flag'] !== '';
}

/**
 * @param array<string, mixed> $filters
 */
function people_list_accounts_filters_active(array $filters): bool
{
    $filters = people_list_normalize_filters($filters);
    return $filters['q'] !== ''
        || $filters['group_id'] !== null
        || $filters['role'] !== '';
}

function render_people_hub_inbox(array $user, int $participantCount, int $rolePendingCount, string $view = 'people'): void
{
    $links = [];
    if (can_create_home_group($user)) {
        $links[] = participant_requests_link($participantCount);
    }
    if (in_array($user['role'], ['pastor', 'admin', 'super_admin'], true)) {
        $links[] = role_requests_link($rolePendingCount);
    }
    if ($links === []) {
        return;
    }

    echo '<section class="panel people-hub-inbox"><div class="panel-head"><div><h2>Входящие</h2><p class="muted">Заявки, которые ждут вашего решения</p></div><div class="people-hub-inbox-actions">' . implode('', $links) . '</div></div></section>';
}

function render_people_hub_view_switch(array $filters, string $view): void
{
    $peopleHref = people_hub_url($filters, 'people');
    $accountsHref = people_hub_url($filters, 'accounts');
    echo '<nav class="people-view-switch" aria-label="Режим списка">';
    echo '<a class="people-view-switch__item' . ($view === 'people' ? ' is-active' : '') . '" href="' . e($peopleHref) . '"' . ($view === 'people' ? ' aria-current="page"' : '') . '>Люди</a>';
    echo '<a class="people-view-switch__item' . ($view === 'accounts' ? ' is-active' : '') . '" href="' . e($accountsHref) . '"' . ($view === 'accounts' ? ' aria-current="page"' : '') . '>Учётные записи</a>';
    echo '</nav>';
}

function render_role_invite_links_panel(array $user): void
{
    $roles = role_invite_roles_for_user($user);
    if (!$roles && !in_array($user['role'], ['pastor', 'admin', 'super_admin'], true)) {
        return;
    }

    echo '<details id="role-invites" class="panel collapsible-panel role-invite-panel" data-default-collapsed="1"><summary><div><h2>Приглашение на роль</h2><p class="muted">Ссылки этой церкви для регистрации. Роль включится только после подтверждения пастором или администратором.</p></div><span class="collapse-indicator" aria-hidden="true"></span></summary>';
    if ($roles) {
        echo '<div class="stack">';
        foreach ($roles as $role) {
            $url = role_invite_url($user, $role);
            if ($url === '') {
                continue;
            }
            echo '<div class="list-row"><div><strong>' . e(role_label($role)) . '</strong><span>Регистрация по ссылке создаст заявку на эту роль.</span></div><div class="copy-field"><input readonly data-copy-source value="' . e($url) . '"><button type="button" class="ghost" data-copy>Копировать</button></div></div>';
        }
        echo '</div>';
    } else {
        echo '<p class="muted">Для вашей роли нет ссылок, которые можно выдавать.</p>';
    }

    if (!role_invite_storage_ready()) {
        echo '<div class="flash error">Заявки на роли временно недоступны: нужно применить миграцию базы данных 016_role_invitation_requests.sql.</div>';
    }
    echo '</details>';
}

function role_invite_page(): void
{
    $token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
    $context = role_invite_context_from_token($token);
    if ($context !== null && !role_invite_matches_request_church($context)) {
        $context = null;
    }
    $error = null;
    $storageReady = role_invite_storage_ready();

    $inviteChurch = null;
    if ($context !== null) {
        $inviteChurch = function_exists('current_church') ? current_church() : null;
        if (!$inviteChurch || (int) ($inviteChurch['id'] ?? 0) !== (int) $context['church_id']) {
            $inviteChurch = function_exists('find_church_by_id')
                ? find_church_by_id((int) $context['church_id'])
                : null;
        }
        if ($inviteChurch && function_exists('set_current_church')) {
            set_current_church($inviteChurch);
        }
    }

    $loginHref = function_exists('app_path') ? app_path('/login') : '/login';
    $forgotHref = '/forgot-password';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $login = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (!$context) {
            $error = 'Ссылка недействительна или больше недоступна.';
        } elseif (!$storageReady) {
            $error = 'Регистрация по ссылке временно недоступна: администратору нужно применить миграцию базы данных.';
        } elseif ($name === '') {
            $error = 'Укажите имя.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Укажите корректный email.';
        } elseif (fetch_one('SELECT id FROM users WHERE email = ? LIMIT 1', [$email])) {
            $error = 'Пользователь с такой почтой уже есть. Войдите или восстановите пароль — новую регистрацию делать не нужно.';
        } elseif (mb_strlen($password) < 8) {
            $error = 'Пароль должен быть не короче 8 символов.';
        } else {
            $login = unique_user_login($login !== '' ? $login : login_from_email($email));
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO users (name, login, email, password_hash, role, church_id, group_id) VALUES (?, ?, ?, ?, "member", ?, ?)')
                    ->execute([$name, $login, $email, password_hash($password, PASSWORD_DEFAULT), $context['church_id'], $context['group_id']]);
                $userId = (int) $pdo->lastInsertId();
                $pdo->prepare('INSERT INTO role_invitation_requests (user_id, invited_by, requested_role, church_id, group_id) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$userId, (int) $context['issuer']['id'], $context['role'], $context['church_id'], $context['group_id']]);
                $pdo->prepare('INSERT IGNORE INTO user_notification_settings (user_id) VALUES (?)')->execute([$userId]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = 'Не удалось создать заявку. Попробуйте еще раз.';
            }

            if ($error === null) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $userId;
                audit('role_invite_registered', 'user', $userId, [
                    'requested_role' => $context['role'],
                    'invited_by' => (int) $context['issuer']['id'],
                    'church_id' => (int) $context['church_id'],
                ]);
                flash('Аккаунт создан. Запрошенная роль появится после подтверждения пастором или администратором.');
                if ($inviteChurch && function_exists('set_current_church')) {
                    set_current_church($inviteChurch);
                }
                redirect('/profile');
            }
        }
    }

    $churchLabel = $inviteChurch && function_exists('church_public_label')
        ? church_public_label($inviteChurch)
        : trim((string) ($inviteChurch['city'] ?? $inviteChurch['name'] ?? ''));

    public_page_header('Регистрация по ссылке');
    echo '<form class="login-card" method="post"><h1>Регистрация по ссылке</h1>';
    if ($error) {
        echo '<div class="flash error">' . e($error) . '</div>';
    }
    if (!$context && !$error) {
        echo '<div class="flash error">Ссылка недействительна или больше недоступна.</div>';
    }
    if ($context) {
        $defaultLogin = login_from_email((string) ($_POST['email'] ?? ''));
        echo csrf_field() . '<input type="hidden" name="token" value="' . e($token) . '">';
        if ($churchLabel !== '') {
            echo '<p class="muted">Церковь: <strong>' . e($churchLabel) . '</strong></p>';
        }
        echo '<p class="muted">Запрашиваемая роль: <strong>' . e(role_label((string) $context['role'])) . '</strong>. После регистрации заявку должен подтвердить пастор или администратор.</p>';
        echo '<label>Имя<input name="name" required value="' . e((string) ($_POST['name'] ?? '')) . '"></label>';
        echo '<label>Email<input name="email" type="email" required value="' . e((string) ($_POST['email'] ?? '')) . '"></label>';
        echo '<label>Логин<input name="login" required pattern="[a-zA-Z0-9._\x2d]+" value="' . e((string) ($_POST['login'] ?? $defaultLogin)) . '"></label>';
        echo '<label>Пароль<input name="password" type="password" required minlength="8"></label>';
        echo '<button class="primary full">Зарегистрироваться</button>';
    }
    echo '<p class="muted"><a href="' . e($loginHref) . '">Войти</a> · <a href="' . e($forgotHref) . '">Восстановить пароль</a></p></form>';
    public_page_footer();
}

function public_url(string $path): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $base = rtrim((string) (app_config('url') ?: ''), '/');
    if ($base === '') {
        $scheme = request_is_https() ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $base = $scheme . '://' . $host;
    }

    return $base . (str_starts_with($path, '/') ? $path : '/' . $path);
}

function public_page_header(string $title, array $options = []): void
{
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    html_pwa_meta();
    html_font_assets();
    if (!empty($options['extra_head'])) {
        echo (string) $options['extra_head'];
    }
    echo '<link rel="stylesheet" href="' . e(asset_url('/assets/app.css')) . '"><title>' . e($title) . '</title></head><body class="login-body"' . (geocoder_ready() ? ' data-geocoder-ready="1"' : '') . '>';
}

function public_page_footer(array $options = []): void
{
    echo '<script src="' . e(asset_url('/assets/app.js')) . '"></script>';
    if (!empty($options['extra_scripts'])) {
        echo (string) $options['extra_scripts'];
    }
    echo '</body></html>';
}

function church_personal_data_consent_text(?int $churchId): string
{
    $default = 'Я выражаю согласие на обработку моих персональных данных для присоединения к домашней группе.';
    if ($churchId === null || $churchId <= 0) {
        return $default;
    }

    $church = fetch_one('SELECT personal_data_consent_text FROM churches WHERE id = ? LIMIT 1', [$churchId]);
    $text = trim((string) ($church['personal_data_consent_text'] ?? ''));

    return $text !== '' ? $text : $default;
}

function forgot_password_page(): void
{
    $message = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $message = 'Если почта есть в системе, мы отправили ссылку восстановления.';
        if (throttle_action('forgot-password:' . ($_SERVER['REMOTE_ADDR'] ?? 'local'), 5, 600)) {
            $email = trim((string) ($_POST['email'] ?? ''));
            $user = fetch_one('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
            if ($user) {
                $token = bin2hex(random_bytes(24));
                db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 2 HOUR))')
                    ->execute([(int) $user['id'], hash('sha256', $token)]);
                $url = public_url('/reset-password?token=' . urlencode($token));
                queue_email_notification((int) $user['id'], $email, 'Восстановление пароля CRM', "Откройте ссылку для смены пароля: $url", 'password_reset');
                audit('password_reset_requested', 'user', (int) $user['id']);
            }
        }
    }

    public_page_header('Восстановление пароля');
    echo '<form class="login-card" method="post"><h1>Восстановление пароля</h1>';
    if ($message) echo '<div class="flash">' . e($message) . '</div>';
    echo csrf_field() . '<label>Почта<input name="email" type="email" required></label><button class="primary full">Отправить ссылку</button><p class="muted"><a href="/login">Вернуться ко входу</a></p></form>';
    public_page_footer();
}
function reset_password_page(): void
{
    $token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $row = fetch_one('SELECT pr.*, u.email FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW() LIMIT 1', [hash('sha256', $token)]);
        $password = (string) ($_POST['password'] ?? '');
        if (!$row) {
            $error = 'Ссылка устарела или уже использована.';
        } elseif (mb_strlen($password) < 8) {
            $error = 'Пароль должен быть не короче 8 символов.';
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), (int) $row['user_id']]);
            db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);
            queue_email_notification((int) $row['user_id'], (string) $row['email'], 'Пароль CRM изменен', 'Пароль вашей учетной записи был изменен.', 'password_changed');
            audit('password_reset_completed', 'user', (int) $row['user_id']);
            flash('Пароль изменен. Войдите с новым паролем.');
            redirect('/login');
        }
    }

    public_page_header('Новый пароль');
    echo '<form class="login-card" method="post"><h1>Новый пароль</h1>';
    if ($error) echo '<div class="flash error">' . e($error) . '</div>';
    echo csrf_field() . '<input type="hidden" name="token" value="' . e($token) . '"><label>Новый пароль<input name="password" type="password" required minlength="8"></label><button class="primary full">Сохранить пароль</button></form>';
    public_page_footer();
}

function account_invite_page(): void
{
    $token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
    $hash = hash('sha256', $token);
    $invite = fetch_one(
        'SELECT ai.*, p.full_name, p.email, p.user_id, p.group_id, g.church_id AS person_church_id
         FROM account_invitations ai
         JOIN people p ON p.id = ai.person_id
         JOIN home_groups g ON g.id = p.group_id
         WHERE ai.token_hash = ? AND ai.used_at IS NULL AND ai.expires_at > NOW()
         LIMIT 1',
        [$hash]
    );
    $error = null;

    $inviteChurch = null;
    if ($invite) {
        $inviteChurch = account_invite_church_for_person((int) $invite['person_id']);
        $current = function_exists('current_church') ? current_church() : null;
        if (
            $current
            && !(function_exists('church_is_deleted') && church_is_deleted($current))
            && (int) ($current['id'] ?? 0) !== (int) ($invite['person_church_id'] ?? 0)
        ) {
            $invite = null;
            $inviteChurch = null;
        } elseif ($inviteChurch && function_exists('set_current_church')) {
            set_current_church($inviteChurch);
        }
    }

    $loginHref = function_exists('app_path') ? app_path('/login') : '/login';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $email = trim((string) ($_POST['email'] ?? ''));
        $login = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (!$invite) {
            $error = 'Ссылка устарела или уже использована.';
        } elseif ((int) ($invite['user_id'] ?? 0) > 0) {
            $error = 'Для этой карточки учетная запись уже создана.';
        } elseif (mb_strlen($password) < 8) {
            $error = 'Пароль должен быть не короче 8 символов.';
        } else {
            try {
                db()->beginTransaction();
                $person = ['id' => (int) $invite['person_id'], 'group_id' => (int) $invite['group_id'], 'full_name' => $invite['full_name']];
                $account = create_member_account_for_person($person, $email, $login, $password);
                db()->prepare('UPDATE account_invitations SET used_at = NOW() WHERE id = ?')->execute([(int) $invite['id']]);
                db()->commit();
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $account['id'];
                audit('account_invite_accepted', 'user', (int) $account['id'], ['person_id' => (int) $invite['person_id']]);
                if ($inviteChurch && function_exists('set_current_church')) {
                    set_current_church($inviteChurch);
                }
                redirect('/');
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Не удалось создать учетную запись.';
            }
        }
    }

    $churchLabel = $inviteChurch && function_exists('church_public_label')
        ? church_public_label($inviteChurch)
        : trim((string) ($inviteChurch['city'] ?? $inviteChurch['name'] ?? ''));

    public_page_header('Создание учетной записи');
    echo '<form class="login-card" method="post"><h1>Создание учетной записи</h1>';
    if ($error) {
        echo '<div class="flash error">' . e($error) . '</div>';
    }
    if (!$invite && !$error) {
        echo '<div class="flash error">Ссылка устарела или уже использована.</div>';
    }
    $defaultEmail = $invite['email'] ?? '';
    $defaultLogin = $defaultEmail ? login_from_email((string) $defaultEmail) : '';
    echo csrf_field() . '<input type="hidden" name="token" value="' . e($token) . '">';
    if ($invite && $churchLabel !== '') {
        echo '<p class="muted">Церковь: <strong>' . e($churchLabel) . '</strong></p>';
    }
    if ($invite) {
        echo '<p class="muted">Карточка: ' . e((string) $invite['full_name']) . '</p>';
    }
    echo '<label>Email<input name="email" type="email" required value="' . e((string) $defaultEmail) . '"></label>';
    echo '<label>Логин<input name="login" required pattern="[a-zA-Z0-9._\x2d]+" value="' . e($defaultLogin) . '"></label>';
    echo '<label>Пароль<input name="password" type="password" required minlength="8"></label>';
    echo '<button class="primary full">Создать учетную запись</button>';
    echo '<p class="muted"><a href="' . e($loginHref) . '">Вернуться ко входу</a></p></form>';
    public_page_footer();
}

function has_settings_hub_items(array $user): bool
{
    return can_action($user, 'delete_people')
        || can_action($user, 'manage_structure')
        || can_action($user, 'manage_church_page')
        || is_super_admin($user);
}

function user_page(array $viewer): void
{
    require_action($viewer, 'edit_people');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(404);
        exit('Пользователь не найден');
    }
    if ($id === (int) $viewer['id']) {
        redirect('/profile');
    }

    $target = scoped_user($viewer, $id);
    if (!$target) {
        http_response_code(404);
        exit('Пользователь не найден');
    }

    $userId = (int) $target['id'];
    $formId = 'user-update-' . $userId;
    $person = person_linked_to_user($userId);
    [$groupSql, $groupParams] = accessible_group_sql($viewer, 'g');
    $groups = fetch_all("SELECT g.* FROM home_groups g WHERE $groupSql AND g.is_active = 1 ORDER BY g.name", $groupParams);
    $socialLinks = fetch_all('SELECT provider, profile_name, external_user_id FROM social_identities WHERE user_id = ? ORDER BY provider', [$userId]);
    $botAccounts = bot_accounts_for_user($userId);

    page_header($target['name'], $viewer);

    $heroActions = [['href' => people_accounts_hub_path(), 'label' => 'Люди']];
    if ($person) {
        $heroActions[] = ['href' => app_path('/person?id=' . (int) $person['id']), 'label' => 'Пастырская карточка'];
    }
    render_account_hero($target, $heroActions);

    echo '<section class="grid two"><article class="panel"><h2>Данные учетной записи</h2>';
    echo '<form id="' . e($formId) . '" method="post" action="' . e(app_path('/user-update')) . '" class="stack">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . $userId . '"><input type="hidden" name="return" value="user">';
    echo '<label>Имя<input name="name" value="' . e($target['name']) . '" required></label>';
    echo '<label>Логин<input name="login" value="' . e($target['login']) . '" pattern="[a-zA-Z0-9._\x2d]+" required></label>';
    echo '<label>Email<input name="email" type="email" value="' . e($target['email']) . '" required></label>';
    echo '<label>Роль<select name="role">' . visible_role_options($viewer, (string) $target['role']) . '</select></label>';
    echo '<label>Основная группа CRM<select name="group_id"><option value="">—</option>';
    foreach ($groups as $group) {
        echo '<option value="' . (int) $group['id'] . '"' . ((int) ($target['group_id'] ?? 0) === (int) $group['id'] ? ' selected' : '') . '>' . e($group['name']) . '</option>';
    }
    echo '</select></label>';
    echo '<p class="muted">Роль меняется по правилам иерархии. Домашние группы для посещений и заботы настраиваются в пастырской карточке — человек может ходить сразу в несколько групп.</p>';
    echo '<div class="quick-actions"><button class="primary">Сохранить</button></div></form>';
    echo '</article>';
    render_account_person_panel($person, false);
    echo '</section>';

    echo '<section class="panel"><h2>Управление</h2><div class="quick-actions">';
    echo '<form method="post" action="' . e(app_path('/user-password-reset')) . '" class="compact">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . $userId . '"><input type="hidden" name="return" value="user">';
    echo '<button class="ghost">Отправить ссылку восстановления пароля</button></form>';
    if (can_delete_user($viewer, $target)) {
        echo '<form method="post" action="' . e(app_path('/user-delete')) . '" class="compact" data-user-delete>' . csrf_field();
        echo '<input type="hidden" name="id" value="' . $userId . '"><input type="hidden" name="return" value="users">';
        echo '<button class="ghost" data-confirm="Удалить пользователя ' . e($target['name']) . '? Это действие нельзя отменить.">Удалить пользователя</button></form>';
    }
    echo '</div></section>';

    if ($socialLinks || $botAccounts) {
        echo '<section class="grid two">';
        if ($socialLinks) {
            echo '<article class="panel"><h2>Вход через соцсети</h2><dl class="details">';
            foreach ($socialLinks as $row) {
                $defaults = social_provider_defaults((string) $row['provider']);
                $label = $defaults['label'] ?? (string) $row['provider'];
                render_person_static_field($label, $row['profile_name'] ?: $row['external_user_id']);
            }
            echo '</dl></article>';
        }
        if ($botAccounts) {
            echo '<article class="panel"><h2>Бот-уведомления</h2><dl class="details">';
            foreach ($botAccounts as $row) {
                [$providerLabel, $accountValue] = bot_account_display_pair($row);
                render_person_static_field($providerLabel, $accountValue);
            }
            echo '</dl></article>';
        }
        echo '</section>';
    }

    page_footer();
}

function profile_page(array $user): void
{
    $account = account_display_row((int) $user['id']) ?: $user;
    $person = person_linked_to_user((int) $user['id']);
    $prefs = fetch_one('SELECT * FROM user_notification_settings WHERE user_id = ? LIMIT 1', [$user['id']]) ?: ['email_enabled' => 1, 'webpush_enabled' => 1];
    $setupKey = (int) $account['two_factor_enabled'] ? '' : two_factor_secret();

    page_header('Моя учетная запись', $user);

    $heroActions = [];
    if ($person) {
        $heroActions[] = ['href' => app_path('/person?id=' . (int) $person['id']), 'label' => 'Пастырская карточка'];
    }
    if (can_manage_users($user)) {
        $heroActions[] = ['href' => people_hub_url([], 'people'), 'label' => 'Люди'];
    }
    render_account_hero($account, $heroActions, 'Моя учетная запись');

    echo '<section class="grid two"><article class="panel"><h2>Данные учетной записи</h2>';
    echo '<form method="post" action="' . e(app_path('/profile-update')) . '" class="stack">' . csrf_field();
    echo '<label>Имя<input name="name" value="' . e((string) $account['name']) . '" required></label>';
    echo '<label>Логин<input name="login" value="' . e((string) $account['login']) . '" required pattern="[a-zA-Z0-9._\x2d]+"></label>';
    echo '<label>Почта<input name="email" type="email" value="' . e((string) $account['email']) . '" required></label>';
    echo '<p class="muted">Роль: <strong>' . e(role_label((string) $account['role'])) . '</strong>';
    if (!empty($account['group_name'])) {
        echo ' · группа: <strong>' . e((string) $account['group_name']) . '</strong>';
    }
    echo '. Роль и группу меняет руководитель.</p>';
    echo '<button class="primary">Сохранить</button></form></article>';
    render_account_person_panel($person, true);
    echo '</section>';

    echo '<section class="grid two"><article class="panel"><h2>Смена пароля</h2><form method="post" action="' . e(app_path('/password-change')) . '" class="stack">' . csrf_field() . '<label>Текущий пароль<input name="current_password" type="password" required></label><label>Новый пароль<input name="new_password" type="password" minlength="8" required></label><button class="primary">Сменить пароль</button></form></article>';
    $twoFactorStatus = (int) $account['two_factor_enabled'] ? '2FA включена для вашего пользователя.' : '2FA выключена. Сохраните ключ в приложении-аутентификаторе перед включением.';
    echo '<article class="panel"><h2>Безопасность</h2><p class="muted">' . e($twoFactorStatus) . ' Одноразовые коды не показываются в CRM.</p>';
    if ($setupKey !== '') {
        echo '<p class="muted">Ключ настройки: <strong>' . e($setupKey) . '</strong></p>';
    }
    echo '<form method="post" action="' . e(app_path('/two-factor-toggle')) . '" class="stack">' . csrf_field() . '<input type="hidden" name="setup_secret" value="' . e($setupKey) . '"><label class="checkbox-row"><input type="checkbox" name="enabled" value="1"' . ((int) $account['two_factor_enabled'] ? ' checked' : '') . '> Включить двухфакторную проверку</label><button class="primary">Сохранить 2FA</button></form></article></section>';

    echo '<section class="panel"><h2>Уведомления</h2><form method="post" action="' . e(app_path('/notification-settings')) . '" class="stack profile-notifications">' . csrf_field() . '<label class="checkbox-row"><input type="checkbox" name="email_enabled" value="1"' . ((int) $prefs['email_enabled'] ? ' checked' : '') . '> Email-уведомления</label><label class="checkbox-row"><input type="checkbox" name="webpush_enabled" value="1"' . ((int) $prefs['webpush_enabled'] ? ' checked' : '') . '> WebPush-уведомления</label><div class="quick-actions"><button class="primary">Сохранить уведомления</button><button class="ghost" id="enable-push" type="button">Подключить WebPush в браузере</button></div></form></section>';

    render_social_accounts_panel($user);
    render_bot_accounts_panel($user);
    mcp_flash_new_key_banner();
    mcp_render_api_keys_panel($user);
    page_footer();
}
function visible_role_options(array $editor, string $currentRole): string
{
    $options = '';
    foreach (assignable_roles($editor, $currentRole) as $role) {
        $options .= '<option value="' . e($role) . '"' . ($role === $currentRole ? ' selected' : '') . '>' . e(role_label($role)) . '</option>';
    }
    return $options;
}

/**
 * @return array{
 *   q: string,
 *   group_id: ?int,
 *   registered: string,
 *   role: string,
 *   spiritual_stage_id: ?int,
 *   flag: string
 * }
 */
function people_list_default_filters(): array
{
    return [
        'q' => '',
        'group_id' => null,
        'registered' => '',
        'role' => '',
        'spiritual_stage_id' => null,
        'flag' => '',
    ];
}

/**
 * @param ?array<string, mixed> $filters
 * @return array{
 *   q: string,
 *   group_id: ?int,
 *   registered: string,
 *   role: string,
 *   spiritual_stage_id: ?int,
 *   flag: string
 * }
 */
function people_list_normalize_filters(?array $filters): array
{
    $normalized = people_list_default_filters();
    if ($filters === null) {
        return $normalized;
    }

    $q = trim((string) ($filters['q'] ?? ''));
    $normalized['q'] = mb_substr($q, 0, 120);

    $groupId = (int) ($filters['group_id'] ?? 0);
    $normalized['group_id'] = $groupId > 0 ? $groupId : null;

    $registered = (string) ($filters['registered'] ?? '');
    $normalized['registered'] = in_array($registered, ['yes', 'no'], true) ? $registered : '';

    $role = (string) ($filters['role'] ?? '');
    $normalized['role'] = $role !== '' && function_exists('roles') && in_array($role, roles(), true) ? $role : '';

    $stageId = (int) ($filters['spiritual_stage_id'] ?? 0);
    $normalized['spiritual_stage_id'] = $stageId > 0 ? $stageId : null;

    $flag = (string) ($filters['flag'] ?? '');
    $normalized['flag'] = in_array($flag, ['leader', 'potential'], true) ? $flag : '';

    return $normalized;
}

/**
 * @param array<string, mixed> $input
 * @param array{
 *   group_ids?: list<int>,
 *   stage_ids?: list<int>,
 *   roles?: list<string>
 * } $allowed
 * @return array{
 *   q: string,
 *   group_id: ?int,
 *   registered: string,
 *   role: string,
 *   spiritual_stage_id: ?int,
 *   flag: string
 * }
 */
function parse_people_list_filters(array $input, array $allowed = []): array
{
    $filters = people_list_normalize_filters([
        'q' => $input['q'] ?? '',
        'group_id' => $input['group_id'] ?? null,
        'registered' => $input['registered'] ?? '',
        'role' => $input['role'] ?? '',
        'spiritual_stage_id' => $input['spiritual_stage_id'] ?? null,
        'flag' => $input['flag'] ?? '',
    ]);

    $allowedRoles = $allowed['roles'] ?? (function_exists('roles') ? roles() : []);
    if ($filters['role'] !== '' && $allowedRoles !== [] && !in_array($filters['role'], $allowedRoles, true)) {
        $filters['role'] = '';
    }

    $allowedGroups = $allowed['group_ids'] ?? null;
    if ($filters['group_id'] !== null && is_array($allowedGroups) && $allowedGroups !== [] && !in_array($filters['group_id'], $allowedGroups, true)) {
        $filters['group_id'] = null;
    }

    $allowedStages = $allowed['stage_ids'] ?? null;
    if ($filters['spiritual_stage_id'] !== null && is_array($allowedStages) && $allowedStages !== [] && !in_array($filters['spiritual_stage_id'], $allowedStages, true)) {
        $filters['spiritual_stage_id'] = null;
    }

    return $filters;
}

/**
 * @param array{
 *   q: string,
 *   group_id: ?int,
 *   registered: string,
 *   role: string,
 *   spiritual_stage_id: ?int,
 *   flag: string
 * } $filters
 */
function people_list_filters_active(array $filters): bool
{
    $filters = people_list_normalize_filters($filters);
    return $filters['q'] !== ''
        || $filters['group_id'] !== null
        || $filters['registered'] !== ''
        || $filters['role'] !== ''
        || $filters['spiritual_stage_id'] !== null
        || $filters['flag'] !== '';
}

function people_list_like_pattern(string $query): string
{
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
    return '%' . $escaped . '%';
}

/**
 * @param array<string, mixed> $filters
 * @return array{0:string,1:list<mixed>}
 */
function people_list_filter_sql(array $filters, string $personAlias = 'p', string $userAlias = 'u'): array
{
    $filters = people_list_normalize_filters($filters);
    $sql = [];
    $params = [];

    if ($filters['q'] !== '') {
        $like = people_list_like_pattern($filters['q']);
        $sql[] = '('
            . "$personAlias.full_name LIKE ? ESCAPE '\\\\' OR "
            . "$personAlias.phone LIKE ? ESCAPE '\\\\' OR "
            . "$personAlias.email LIKE ? ESCAPE '\\\\' OR "
            . "$personAlias.telegram LIKE ? ESCAPE '\\\\' OR "
            . "$personAlias.address LIKE ? ESCAPE '\\\\' OR "
            . "$userAlias.login LIKE ? ESCAPE '\\\\' OR "
            . "$userAlias.name LIKE ? ESCAPE '\\\\' OR "
            . "$userAlias.email LIKE ? ESCAPE '\\\\'"
            . ')';
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
    }

    if ($filters['group_id'] !== null) {
        if (person_group_memberships_ready()) {
            $sql[] = "($personAlias.group_id = ? OR EXISTS (SELECT 1 FROM person_group_memberships pgm WHERE pgm.person_id = $personAlias.id AND pgm.group_id = ?))";
            $params[] = $filters['group_id'];
            $params[] = $filters['group_id'];
        } else {
            $sql[] = "$personAlias.group_id = ?";
            $params[] = $filters['group_id'];
        }
    }

    if ($filters['registered'] === 'yes') {
        $sql[] = "$personAlias.user_id IS NOT NULL";
    } elseif ($filters['registered'] === 'no') {
        $sql[] = "$personAlias.user_id IS NULL";
    }

    if ($filters['role'] !== '') {
        $sql[] = "$userAlias.role = ?";
        $params[] = $filters['role'];
    }

    if ($filters['spiritual_stage_id'] !== null) {
        $sql[] = "$personAlias.spiritual_stage_id = ?";
        $params[] = $filters['spiritual_stage_id'];
    }

    if ($filters['flag'] === 'leader') {
        $sql[] = "$personAlias.is_leader = 1";
    } elseif ($filters['flag'] === 'potential') {
        $sql[] = "$personAlias.is_potential_leader = 1";
    }

    if ($sql === []) {
        return ['', []];
    }

    return [' AND ' . implode(' AND ', $sql), $params];
}

/**
 * @param array<string, mixed> $filters
 * @return array{0:string,1:list<mixed>}
 */
function users_list_filter_sql(array $filters, string $userAlias = 'u'): array
{
    $filters = people_list_normalize_filters($filters);
    $sql = [];
    $params = [];

    if ($filters['q'] !== '') {
        $like = people_list_like_pattern($filters['q']);
        $sql[] = '('
            . "$userAlias.name LIKE ? ESCAPE '\\\\' OR "
            . "$userAlias.login LIKE ? ESCAPE '\\\\' OR "
            . "$userAlias.email LIKE ? ESCAPE '\\\\'"
            . ')';
        array_push($params, $like, $like, $like);
    }

    if ($filters['group_id'] !== null) {
        $sql[] = "$userAlias.group_id = ?";
        $params[] = $filters['group_id'];
    }

    if ($filters['role'] !== '') {
        $sql[] = "$userAlias.role = ?";
        $params[] = $filters['role'];
    }

    if ($sql === []) {
        return ['', []];
    }

    return [' AND ' . implode(' AND ', $sql), $params];
}

/**
 * @param list<array<string, mixed>> $groups
 * @param list<array<string, mixed>> $stages
 * @param array{
 *   q: string,
 *   group_id: ?int,
 *   registered: string,
 *   role: string,
 *   spiritual_stage_id: ?int,
 *   flag: string
 * } $filters
 */
function render_people_list_filters(array $groups, array $stages, array $filters, int $resultCount, string $view = 'people'): void
{
    $filters = people_list_normalize_filters($filters);
    $view = people_hub_view($view);
    $isPeople = $view === 'people';
    $active = $isPeople ? people_list_filters_active($filters) : people_list_accounts_filters_active($filters);
    $advancedOpen = $isPeople && people_list_advanced_filters_active($filters);
    $roleOptions = function_exists('roles') ? roles() : [];
    $countLabel = $isPeople
        ? people_list_count_label($resultCount, 'человек', 'человека', 'человек')
        : people_list_count_label($resultCount, 'учётная запись', 'учётные записи', 'учётных записей');

    echo '<section class="panel people-filters" data-people-filters data-people-view="' . e($view) . '">';
    echo '<div class="panel-head"><h2>Поиск и фильтры</h2>';
    echo '<p class="people-filters-meta">Найдено: ' . $resultCount . ' ' . $countLabel . '</p>';
    echo '</div>';

    echo '<form method="get" action="' . e(app_path('/users')) . '" class="stack" data-people-filters-form>';
    if ($view === 'accounts') {
        echo '<input type="hidden" name="view" value="accounts">';
    }
    echo '<div class="form-grid people-filters-grid">';
    echo '<label class="people-filters-search">Поиск<input name="q" value="' . e($filters['q']) . '" placeholder="' . ($isPeople ? 'Имя, телефон, email, Telegram, логин...' : 'Имя, логин, email...') . '" autocomplete="off"></label>';
    echo '<label>Группа<select name="group_id"><option value="">Все группы</option>';
    foreach ($groups as $group) {
        $groupId = (int) $group['id'];
        echo '<option value="' . $groupId . '"' . ($filters['group_id'] === $groupId ? ' selected' : '') . '>' . e((string) $group['name']) . '</option>';
    }
    echo '</select></label>';
    if ($isPeople) {
        echo '<label>Учётная запись<select name="registered">';
        foreach (['' => 'Все', 'yes' => 'Есть учётка', 'no' => 'Нет учётки'] as $value => $label) {
            echo '<option value="' . e((string) $value) . '"' . ($filters['registered'] === (string) $value ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        echo '</select></label>';
    } else {
        echo '<label>Роль в CRM<select name="role"><option value="">Все роли</option>';
        foreach ($roleOptions as $role) {
            echo '<option value="' . e($role) . '"' . ($filters['role'] === $role ? ' selected' : '') . '>' . e(role_label($role)) . '</option>';
        }
        echo '</select></label>';
    }
    echo '</div>';

    if ($isPeople) {
        echo '<details class="people-filters-more"' . ($advancedOpen ? ' open' : '') . '><summary>Ещё фильтры</summary>';
        echo '<div class="form-grid people-filters-advanced">';
        echo '<label>Роль в CRM<select name="role"><option value="">Все роли</option>';
        foreach ($roleOptions as $role) {
            echo '<option value="' . e($role) . '"' . ($filters['role'] === $role ? ' selected' : '') . '>' . e(role_label($role)) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Духовная воронка<select name="spiritual_stage_id"><option value="">Все этапы</option>';
        foreach ($stages as $stage) {
            $stageId = (int) $stage['id'];
            echo '<option value="' . $stageId . '"' . ($filters['spiritual_stage_id'] === $stageId ? ' selected' : '') . '>' . e((string) $stage['name']) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Лидер / потенциал<select name="flag"><option value="">Не важно</option>';
        foreach (['leader' => 'Лидер группы', 'potential' => 'Потенциальный лидер'] as $value => $label) {
            echo '<option value="' . e($value) . '"' . ($filters['flag'] === $value ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        echo '</select></label>';
        echo '</div></details>';
    }

    echo '<div class="people-filters-actions"><button class="primary" type="submit">Найти</button>';
    if ($active) {
        echo '<a class="ghost" href="' . e(people_hub_url([], $view)) . '">Сбросить</a>';
    }
    echo '</div></form></section>';
}

/**
 * @param list<int> $personIds
 * @return array<int, list<array{group_id:int, group_name:string, is_primary:int}>>
 */
function person_memberships_map(array $personIds): array
{
    $personIds = array_values(array_unique(array_filter(array_map('intval', $personIds), static fn(int $id): bool => $id > 0)));
    if ($personIds === []) {
        return [];
    }

    $map = [];
    if (person_group_memberships_ready()) {
        $placeholders = implode(',', array_fill(0, count($personIds), '?'));
        $rows = fetch_all(
            "SELECT pgm.person_id, pgm.group_id, pgm.is_primary, g.name AS group_name
             FROM person_group_memberships pgm
             JOIN home_groups g ON g.id = pgm.group_id
             WHERE pgm.person_id IN ($placeholders)
             ORDER BY pgm.is_primary DESC, g.name",
            $personIds
        );
        foreach ($rows as $row) {
            $personId = (int) $row['person_id'];
            $map[$personId][] = [
                'group_id' => (int) $row['group_id'],
                'group_name' => (string) $row['group_name'],
                'is_primary' => (int) ($row['is_primary'] ?? 0),
            ];
        }
        return $map;
    }

    $placeholders = implode(',', array_fill(0, count($personIds), '?'));
    $rows = fetch_all(
        "SELECT p.id AS person_id, p.group_id, g.name AS group_name
         FROM people p
         LEFT JOIN home_groups g ON g.id = p.group_id
         WHERE p.id IN ($placeholders) AND p.group_id IS NOT NULL",
        $personIds
    );
    foreach ($rows as $row) {
        $personId = (int) $row['person_id'];
        $map[$personId][] = [
            'group_id' => (int) $row['group_id'],
            'group_name' => (string) ($row['group_name'] ?? ''),
            'is_primary' => 1,
        ];
    }
    return $map;
}

/**
 * @param list<array<string, mixed>> $groups
 */
function render_people_hub_group_select(string $name, array $groups, ?int $selectedGroupId, string $emptyLabel = '—'): string
{
    $html = '<select name="' . e($name) . '">';
    $html .= '<option value="">' . e($emptyLabel) . '</option>';
    foreach ($groups as $group) {
        $groupId = (int) $group['id'];
        $html .= '<option value="' . $groupId . '"' . ($selectedGroupId === $groupId ? ' selected' : '') . '>' . e((string) $group['name']) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

function people_list_count_label(int $count, string $one, string $few, string $many): string
{
    $mod10 = $count % 10;
    $mod100 = $count % 100;
    if ($mod10 === 1 && $mod100 !== 11) {
        return $one;
    }
    if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
        return $few;
    }
    return $many;
}

function users_page(array $user): void
{
    require_action($user, 'edit_people');
    $view = people_hub_view((string) ($_GET['view'] ?? 'people'));
    page_header('Люди', $user);

    [$groupSql, $groupParams] = accessible_group_sql($user, 'g');
    $groups = fetch_all("SELECT g.* FROM home_groups g WHERE $groupSql AND g.is_active = 1 ORDER BY g.name", $groupParams);
    $groupIds = array_map(static fn(array $group): int => (int) $group['id'], $groups);
    $stages = fetch_all(
        'SELECT id, name FROM spiritual_stages WHERE church_id = ? ORDER BY sort_order, name',
        [(int) (effective_scope_church_id($user) ?? ($user['church_id'] ?? 0))]
    );
    $stageIds = array_map(static fn(array $stage): int => (int) $stage['id'], $stages);
    $filters = parse_people_list_filters($_GET, [
        'group_ids' => $groupIds,
        'stage_ids' => $stageIds,
        'roles' => roles(),
    ]);
    if ($view === 'accounts') {
        $filters['registered'] = '';
        $filters['spiritual_stage_id'] = null;
        $filters['flag'] = '';
    }

    $participantRequestCount = participant_requests_new_count();
    $rolePendingCount = role_invite_pending_count($user);
    $peopleCountForMeta = 0;
    $usersCountForMeta = 0;

    if ($view === 'people') {
        [$peopleFilterSql, $peopleFilterParams] = people_list_filter_sql($filters, 'p', 'u');
        $people = fetch_all(
            "SELECT p.*, g.name AS group_name, u.login AS user_login, u.role AS user_role
             FROM people p
             JOIN home_groups g ON g.id = p.group_id
             LEFT JOIN users u ON u.id = p.user_id
             WHERE $groupSql AND g.is_active = 1 AND p.deleted_at IS NULL$peopleFilterSql
             ORDER BY g.name, p.full_name",
            array_merge($groupParams, $peopleFilterParams)
        );
        $peopleCountForMeta = count($people);
        $filtersActive = people_list_filters_active($filters);

        echo '<p class="people-hub-summary muted">В вашей зоне: ' . $peopleCountForMeta . ' '
            . people_list_count_label($peopleCountForMeta, 'человек', 'человека', 'человек');
        if (can_create_home_group($user) && $participantRequestCount > 0) {
            echo ' · новых заявок: ' . $participantRequestCount;
        }
        echo '</p>';

        render_people_hub_inbox($user, $participantRequestCount, $rolePendingCount, $view);
        render_people_hub_view_switch($filters, $view);
        render_people_list_filters($groups, $stages, $filters, $peopleCountForMeta, $view);

        $canEditPeople = can_edit_group_data($user);
        $membershipMap = person_memberships_map(array_map(static fn(array $person): int => (int) $person['id'], $people));
        $linkedUserIds = [];
        foreach ($people as $person) {
            $uid = (int) ($person['user_id'] ?? 0);
            if ($uid > 0) {
                $linkedUserIds[] = $uid;
            }
        }
        $linkedUsersById = [];
        if ($linkedUserIds !== []) {
            $placeholders = implode(',', array_fill(0, count(array_unique($linkedUserIds)), '?'));
            $uniqueIds = array_values(array_unique($linkedUserIds));
            foreach (fetch_all("SELECT * FROM users WHERE id IN ($placeholders)", $uniqueIds) as $linkedUser) {
                $linkedUsersById[(int) $linkedUser['id']] = $linkedUser;
            }
        }

        echo '<section class="panel people-list-panel"><div class="panel-head"><h2>Люди</h2><p class="muted">Основную домашнюю группу и роль можно менять прямо в списке</p></div>';
        echo '<div class="table-wrap people-list-desktop"><table><thead><tr><th>Человек</th><th>Контакты</th><th>Домашняя группа</th><th>Роль</th><th></th></tr></thead><tbody>';
        foreach ($people as $person) {
            $contacts = array_filter([
                $person['email'] ?: null,
                ChurchCare\format_phone($person['phone'] ?? null) ?: null,
                ChurchCare\format_telegram($person['telegram'] ?? null) ?: null,
            ]);
            $personId = (int) $person['id'];
            $registered = (int) ($person['user_id'] ?? 0) > 0;
            $memberships = $membershipMap[$personId] ?? [];
            $extraCount = max(0, count($memberships) - 1);
            $linkedUser = $registered ? ($linkedUsersById[(int) $person['user_id']] ?? null) : null;
            $canEditLinkedUser = $linkedUser !== null && can_edit_user_record($user, $linkedUser);

            echo '<tr data-person-row="' . $personId . '"><td><a href="' . e(app_path('/person?id=' . $personId)) . '"><strong>' . e($person['full_name']) . '</strong></a>';
            echo '<div class="table-sub"><span class="status-badge ' . ($registered ? 'registered' : 'unregistered') . '">' . ($registered ? 'Есть учётка' : 'Нет учётки') . '</span>';
            if ($extraCount > 0) {
                echo ' <span class="muted">ещё +' . $extraCount . '</span>';
            }
            echo '</div></td>';
            echo '<td>' . e(implode(' · ', $contacts) ?: '—') . '</td><td>';
            if ($canEditPeople) {
                echo '<form method="post" action="' . e(app_path('/person-set-group')) . '" class="inline-access-form">' . csrf_field()
                    . '<input type="hidden" name="person_id" value="' . $personId . '"><input type="hidden" name="return" value="people">'
                    . render_people_hub_group_select('group_id', $groups, (int) ($person['group_id'] ?? 0) ?: null, 'Выберите группу')
                    . '<button class="ghost" type="submit">OK</button></form>';
            } else {
                echo e((string) $person['group_name']);
            }
            echo '</td><td>';
            if ($canEditLinkedUser && $linkedUser) {
                $accessFormId = 'user-access-person-' . $personId;
                echo '<form id="' . e($accessFormId) . '" method="post" action="' . e(app_path('/user-access-update')) . '" class="inline-access-form">' . csrf_field()
                    . '<input type="hidden" name="id" value="' . (int) $linkedUser['id'] . '"><input type="hidden" name="return" value="people">';
                echo '<select name="role">' . visible_role_options($user, (string) $linkedUser['role']) . '</select>';
                echo '<button class="ghost" type="submit">OK</button></form>';
            } elseif ($registered) {
                echo e(role_label((string) ($person['user_role'] ?? 'member')));
            } else {
                echo '<span class="muted">—</span>';
            }
            echo '</td><td class="person-row-actions">';
            if (can_action($user, 'delete_people')) {
                echo '<form method="post" action="' . e(app_path('/person-delete')) . '" class="compact" data-person-delete>' . csrf_field() . '<input type="hidden" name="id" value="' . $personId . '"><button class="ghost" data-confirm="Удалить карточку ' . e($person['full_name']) . '? Карточка будет скрыта из CRM.">Удалить</button></form>';
            }
            echo '</td></tr>';
        }
        if (!$people) {
            echo '<tr><td colspan="5" class="muted">' . ($filtersActive ? 'По выбранным фильтрам люди не найдены. <a href="' . e(people_hub_url([], 'people')) . '">Сбросить фильтры</a>' : 'Людей пока нет.') . '</td></tr>';
        }
        echo '</tbody></table></div>';

        echo '<div class="people-list-mobile">';
        foreach ($people as $person) {
            $contacts = array_values(array_filter([
                ChurchCare\format_phone($person['phone'] ?? null) ?: null,
                ChurchCare\format_telegram($person['telegram'] ?? null) ?: null,
                $person['email'] ?: null,
            ]));
            $personId = (int) $person['id'];
            $registered = (int) ($person['user_id'] ?? 0) > 0;
            $shortContacts = array_slice($contacts, 0, 2);
            $linkedUser = $registered ? ($linkedUsersById[(int) $person['user_id']] ?? null) : null;
            $canEditLinkedUser = $linkedUser !== null && can_edit_user_record($user, $linkedUser);
            echo '<article class="people-card" data-person-row="' . $personId . '">';
            echo '<a class="people-card-main" href="' . e(app_path('/person?id=' . $personId)) . '"><strong>' . e($person['full_name']) . '</strong>';
            echo '<span class="people-card-meta">' . e((string) $person['group_name']);
            if ($shortContacts !== []) {
                echo ' · ' . e(implode(' · ', $shortContacts));
            }
            echo '</span></a>';
            echo '<div class="people-card-side"><span class="status-badge ' . ($registered ? 'registered' : 'unregistered') . '">' . ($registered ? 'Есть учётка' : 'Нет учётки') . '</span>';
            if ($canEditPeople) {
                echo '<form method="post" action="' . e(app_path('/person-set-group')) . '" class="inline-access-form">' . csrf_field()
                    . '<input type="hidden" name="person_id" value="' . $personId . '"><input type="hidden" name="return" value="people">'
                    . render_people_hub_group_select('group_id', $groups, (int) ($person['group_id'] ?? 0) ?: null, 'Группа')
                    . '<button class="ghost" type="submit">OK</button></form>';
            }
            if ($canEditLinkedUser && $linkedUser) {
                echo '<form method="post" action="' . e(app_path('/user-access-update')) . '" class="inline-access-form">' . csrf_field()
                    . '<input type="hidden" name="id" value="' . (int) $linkedUser['id'] . '"><input type="hidden" name="return" value="people">'
                    . '<select name="role">' . visible_role_options($user, (string) $linkedUser['role']) . '</select>'
                    . '<button class="ghost" type="submit">OK</button></form>';
            }
            if (can_action($user, 'delete_people')) {
                echo '<form method="post" action="' . e(app_path('/person-delete')) . '" class="compact" data-person-delete>' . csrf_field() . '<input type="hidden" name="id" value="' . $personId . '"><button class="ghost" data-confirm="Удалить карточку ' . e($person['full_name']) . '? Карточка будет скрыта из CRM.">Удалить</button></form>';
            }
            echo '</div></article>';
        }
        if (!$people) {
            echo '<p class="muted">' . ($filtersActive ? 'По выбранным фильтрам люди не найдены. <a href="' . e(people_hub_url([], 'people')) . '">Сбросить фильтры</a>' : 'Людей пока нет.') . '</p>';
        }
        echo '</div></section>';
    } else {
        [$userSql, $userParams] = user_scope_condition($user, 'u');
        [$usersFilterSql, $usersFilterParams] = users_list_filter_sql($filters, 'u');
        $users = fetch_all(
            "SELECT u.*, c.name AS church_name, g.name AS group_name
             FROM users u
             LEFT JOIN churches c ON c.id = u.church_id
             LEFT JOIN home_groups g ON g.id = u.group_id
             WHERE $userSql$usersFilterSql
             ORDER BY FIELD(u.role, 'super_admin','admin','pastor','district_leader','leader','assistant','member'), u.name",
            array_merge($userParams, $usersFilterParams)
        );
        $editableUsers = [];
        foreach ($users as $row) {
            if (can_edit_user_record($user, $row)) {
                $editableUsers[] = $row;
            }
        }
        $usersCountForMeta = count($editableUsers);
        $filtersActive = people_list_accounts_filters_active($filters);
        $personLinks = person_links_by_user_id($user);

        echo '<p class="people-hub-summary muted">В вашей зоне: ' . $usersCountForMeta . ' '
            . people_list_count_label($usersCountForMeta, 'учётная запись', 'учётные записи', 'учётных записей');
        if ($rolePendingCount > 0) {
            echo ' · заявок на роли: ' . $rolePendingCount;
        }
        echo '</p>';

        render_people_hub_inbox($user, $participantRequestCount, $rolePendingCount, $view);
        render_people_hub_view_switch($filters, $view);
        render_role_invite_links_panel($user);
        render_people_list_filters($groups, $stages, $filters, $usersCountForMeta, $view);

        echo '<section class="panel"><div class="panel-head"><h2>Учётные записи</h2><p class="muted">Роль и основную группу CRM можно менять прямо в списке</p></div><div class="table-wrap"><table><thead><tr><th>Пользователь</th><th>Роль</th><th>Группа CRM</th><th>Карточка</th><th></th></tr></thead><tbody>';
        foreach ($editableUsers as $row) {
            $userId = (int) $row['id'];
            $personId = $personLinks[$userId] ?? null;
            $formId = 'user-access-' . $userId;
            echo '<tr id="user-' . $userId . '" data-user-row="' . $userId . '"><td><a href="' . e(user_card_href($userId, $user)) . '"><strong>' . e($row['name']) . '</strong></a>';
            echo '<div class="table-sub muted">' . e((string) ($row['login'] ?? '')) . '</div>';
            echo '<form id="' . e($formId) . '" method="post" action="' . e(app_path('/user-access-update')) . '">' . csrf_field()
                . '<input type="hidden" name="id" value="' . $userId . '"><input type="hidden" name="return" value="accounts"></form></td>';
            echo '<td><select name="role" form="' . e($formId) . '">' . visible_role_options($user, (string) $row['role']) . '</select></td>';
            echo '<td><select name="group_id" form="' . e($formId) . '"><option value="">—</option>';
            foreach ($groups as $group) {
                echo '<option value="' . (int) $group['id'] . '"' . ((int) ($row['group_id'] ?? 0) === (int) $group['id'] ? ' selected' : '') . '>' . e((string) $group['name']) . '</option>';
            }
            echo '</select></td><td>';
            if ($personId) {
                echo '<a class="ghost" href="' . e(app_path('/person?id=' . $personId)) . '">Карточка</a>';
            } else {
                echo '<span class="muted">Нет карточки</span>';
            }
            echo '</td><td class="user-row-actions"><button class="primary" form="' . e($formId) . '">Сохранить</button>';
            if (can_delete_user($user, $row)) {
                echo '<form method="post" action="' . e(app_path('/user-delete')) . '" class="compact" data-user-delete>' . csrf_field() . '<input type="hidden" name="id" value="' . $userId . '"><input type="hidden" name="return" value="users"><button class="ghost" data-confirm="Удалить пользователя ' . e($row['name']) . '? Это действие нельзя отменить.">Удалить</button></form>';
            }
            echo '</td></tr>';
        }
        if (!$editableUsers) {
            echo '<tr><td colspan="5" class="muted">' . ($filtersActive ? 'По выбранным фильтрам учётные записи не найдены. <a href="' . e(people_hub_url([], 'accounts')) . '">Сбросить фильтры</a>' : 'Учётных записей в вашей зоне ответственности пока нет.') . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    page_footer();
}

function participant_requests_page(array $user): void
{
    require_action($user, 'edit_people');
    if (!can_create_home_group($user)) { http_response_code(403); exit('Недостаточно прав'); }
    page_header('Заявки новых участников', $user);
    [$groupSql, $groupParams] = accessible_group_sql($user, 'g');
    $groups = fetch_all("SELECT g.* FROM home_groups g WHERE $groupSql AND g.is_active = 1 ORDER BY g.name", $groupParams);
    $churchId = effective_scope_church_id($user);
    if ($churchId !== null && $churchId > 0) {
        [$churchSql, $churchParams] = church_id_scope_sql('church_id', $churchId);
        $requests = fetch_all(
            "SELECT * FROM participant_requests WHERE status = \"new\" AND $churchSql ORDER BY created_at DESC",
            $churchParams
        );
    } else {
        $requests = fetch_all('SELECT * FROM participant_requests WHERE status = "new" ORDER BY created_at DESC');
    }

    echo '<div class="page-actions"><a class="ghost" href="' . e(app_path('/users')) . '">← Люди</a></div>';
    echo '<section class="panel participant-requests-panel"><div class="panel-head"><h2>Новые заявки</h2></div>';
    foreach ($requests as $request) {
        $displayName = trim((string) (($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? '')));
        if ($displayName === '') {
            $displayName = (string) ($request['full_name'] ?? '');
        }
        $addressLine = ChurchCare\participant_request_address_line($request);
        $source = (string) ($request['source_provider'] ?? 'site');
        $sourceLabel = $source === 'site' ? 'Сайт' : $source;
        $contactBits = array_filter([
            $request['email'] ?: null,
            ChurchCare\format_phone($request['phone'] ?? null) ?: null,
            ChurchCare\format_telegram($request['telegram'] ?? null) ?: null,
            $addressLine,
        ]);
        $targetGroupId = (int) ($request['target_group_id'] ?? 0);
        if ($targetGroupId <= 0) {
            $targetGroupId = (int) ($request['recommended_group_id'] ?? 0);
        }
        $targetGroupName = null;
        if ($targetGroupId > 0) {
            $targetGroup = fetch_one('SELECT name FROM home_groups WHERE id = ? LIMIT 1', [$targetGroupId]);
            $targetGroupName = $targetGroup['name'] ?? null;
        }
        $requestId = (int) $request['id'];
        echo '<article class="participant-request-card" data-participant-request="' . $requestId . '">';
        echo '<div class="participant-request-info"><strong>' . e($displayName) . '</strong>';
        echo '<span>' . e(implode(' · ', $contactBits) ?: 'Контакты не указаны') . '</span>';
        echo '<span class="muted">Источник: ' . e($sourceLabel) . '</span>';
        if ($targetGroupName) {
            echo '<span class="muted">Предложенная группа: ' . e($targetGroupName) . '</span>';
        }
        if (!empty($request['consent_given'])) {
            echo '<span class="muted">Согласие на обработку данных получено</span>';
        }
        echo '</div>';
        echo '<div class="participant-request-actions">';
        echo '<form method="post" action="' . e(app_path('/participant-approve')) . '" class="stack compact">' . csrf_field() . '<input type="hidden" name="id" value="' . $requestId . '">';
        echo '<label>Email для входа<input name="email" type="email" required value="' . e($request['email']) . '"></label>';
        echo '<label>Группа<select name="group_id">';
        foreach ($groups as $group) {
            echo '<option value="' . (int) $group['id'] . '"' . ($targetGroupId === (int) $group['id'] ? ' selected' : '') . '>' . e($group['name']) . '</option>';
        }
        echo '</select></label>';
        echo '<div class="quick-actions"><button class="primary">Создать карточку и аккаунт</button></div></form>';
        echo '<form method="post" action="' . e(app_path('/participant-reject')) . '" class="compact">' . csrf_field() . '<input type="hidden" name="id" value="' . $requestId . '"><button class="ghost" data-confirm="Отклонить заявку от ' . e($displayName) . '?">Отклонить</button></form>';
        echo '</div></article>';
    }
    if (!$requests) {
        $joinHref = function_exists('crm_public_group_join_url')
            ? crm_public_group_join_url(0, function_exists('current_church') ? current_church() : null)
            : (function_exists('homegroup_join_base_path')
                ? homegroup_join_base_path(function_exists('current_church') ? current_church() : null)
                : '/homegroup/join');
        echo '<p class="muted">Новых заявок нет. Они появляются из публичной формы <a href="' . e($joinHref) . '" target="_blank" rel="noopener">' . e($joinHref) . '</a> и из входа через соцсети в режиме заявки.</p>';
    }
    echo '</section>';
    page_footer();
}

function role_requests_page(array $user): void
{
    require_action($user, 'edit_people');
    if (!in_array($user['role'], ['pastor', 'admin', 'super_admin'], true)) {
        http_response_code(403);
        exit('Недостаточно прав');
    }

    page_header('Заявки на роли', $user);
    echo '<div class="page-actions"><a class="ghost" href="' . e(app_path('/users')) . '">← Люди</a></div>';

    if (!role_invite_storage_ready()) {
        echo '<section class="panel"><div class="flash error">Заявки на роли временно недоступны: нужно применить миграцию базы данных 016_role_invitation_requests.sql.</div></section>';
        page_footer();
        return;
    }

    $pending = role_invite_pending_requests_for($user);

    echo '<section class="panel role-requests-panel"><div class="panel-head"><div><h2>Ожидают подтверждения</h2><p class="muted">После регистрации по приглашению роль включается только после вашего решения</p></div>';
    if ($pending !== []) {
        echo '<span class="status-badge pending">' . count($pending) . '</span>';
    }
    echo '</div>';

    foreach ($pending as $request) {
        $requestId = (int) $request['id'];
        $role = (string) $request['requested_role'];
        $requestChurchId = (int) ($request['church_id'] ?? 0);
        $groups = role_invite_approval_groups($user, $requestChurchId > 0 ? $requestChurchId : null);
        echo '<article class="participant-request-card role-request-card" data-role-request="' . $requestId . '">';
        echo '<div class="participant-request-info"><strong>' . e((string) $request['user_name']) . '</strong>';
        echo '<span>' . e((string) ($request['user_email'] ?? '')) . '</span>';
        echo '<span class="muted">Роль: ' . e(role_label($role)) . ' · пригласил: ' . e((string) ($request['invited_by_name'] ?? '—')) . '</span>';
        if (!empty($request['group_name'])) {
            echo '<span class="muted">Группа: ' . e((string) $request['group_name']) . '</span>';
        }
        echo '</div>';
        echo '<div class="participant-request-actions">';
        echo '<form method="post" action="' . e(app_path('/role-invite-approve')) . '" class="stack compact">' . csrf_field() . '<input type="hidden" name="id" value="' . $requestId . '">';
        if (role_invite_role_requires_group($role)) {
            echo '<label>Группа<select name="group_id" required>';
            if ($groups === []) {
                echo '<option value="" selected disabled>Нет активных групп в этой церкви</option>';
            }
            foreach ($groups as $group) {
                if ($requestChurchId > 0 && (int) $group['church_id'] !== $requestChurchId) {
                    continue;
                }
                $selected = (int) ($request['group_id'] ?? 0) === (int) $group['id'] ? ' selected' : '';
                echo '<option value="' . (int) $group['id'] . '"' . $selected . '>' . e((string) $group['name']) . '</option>';
            }
            echo '</select></label>';
        }
        echo '<div class="quick-actions"><button class="primary">Подтвердить</button></div></form>';
        echo '<form method="post" action="' . e(app_path('/role-invite-reject')) . '" class="compact">' . csrf_field() . '<input type="hidden" name="id" value="' . $requestId . '"><button class="ghost" data-confirm="Отклонить заявку на роль?">Отклонить</button></form>';
        echo '</div></article>';
    }

    if ($pending === []) {
        echo '<p class="muted">Новых заявок на роли нет. Они появляются после регистрации по ссылке из блока «Приглашение на роль» на странице <a href="' . e(people_accounts_hub_path()) . '">Учётные записи</a>.</p>';
    }
    echo '</section>';
    page_footer();
}

function settings_hub_page(array $user): void
{
    if (!has_settings_hub_items($user)) {
        redirect('/profile');
    }

    page_header('Настройки', $user);
    echo '<section class="grid three">';
    $realUser = real_user() ?? $user;
    if (can_use_role_preview_for_user($realUser)) {
        echo '<a class="panel action-card" href="' . e(app_path('/role-preview')) . '"><h2>Просмотр роли</h2><p>Посмотрите интерфейс и доступы глазами другой роли без выхода из администратора.</p></a>';
    }
    if (is_super_admin($user) && !(function_exists('is_demo_session') && is_demo_session())) {
        echo '<a class="panel action-card" href="' . e(app_path('/security')) . '"><h2>Безопасность</h2><p>Ключи WebPush (VAPID).</p></a>';
    }
    if (can_action($user, 'delete_people') && !(function_exists('is_demo_session') && is_demo_session())) {
        echo '<a class="panel action-card" href="' . e(app_path('/privacy')) . '"><h2>Удаление данных</h2><p>Запрос, подтверждение, журнал и мягкое обезличивание.</p></a>';
    }
    if (is_super_admin($user) && !(function_exists('is_demo_session') && is_demo_session())) echo '<a class="panel action-card" href="' . e(app_path('/permissions')) . '"><h2>Права</h2><p>Глобальная матрица разрешений по ролям.</p></a>';
    if (can_action($user, 'manage_church_page')) echo '<a class="panel action-card" href="' . e(app_path('/church-page')) . '"><h2>Страница церкви</h2><p>Публичный лендинг: описание, кнопки, расписание, контакты и фото.</p></a>';
    if (can_action($user, 'manage_structure')) echo '<a class="panel action-card" href="' . e(app_path('/church-settings')) . '"><h2>Настройки церкви</h2><p>Согласие на обработку персональных данных и публичная страница домашних групп.</p></a>';
    if (is_super_admin($user) && !(function_exists('is_demo_session') && is_demo_session())) echo '<a class="panel action-card" href="' . e(platform_path('/admin')) . '"><h2>Платформа</h2><p>Церкви, slug и суперадминистрирование.</p></a>';
    echo '</section>';
    page_footer();
}

function church_settings_page(array $user): void
{
    require_action($user, 'manage_structure');
    $churchId = current_church_id() ?? (int) ($user['church_id'] ?? 0);
    $church = $churchId > 0 ? find_church_by_id($churchId) : null;
    if (!$church) {
        http_response_code(404);
        exit('Church not found');
    }
    if (!user_can_access_church($user, (int) $church['id'])) {
        http_response_code(403);
        exit('Недостаточно прав');
    }

    page_header('Настройки церкви', $user);
    echo '<section class="grid two"><article class="panel"><h2>Основное</h2><form method="post" action="' . e(app_path('/church-settings-save')) . '" class="stack">' . csrf_field();
    echo '<label>Название церкви<input name="name" required value="' . e($church['name']) . '"></label>';
    echo '<label>Город<input name="city" value="' . e($church['city']) . '"></label>';
    echo '<label>Slug<input name="slug" value="' . e((string) ($church['slug'] ?? '')) . '"' . (is_super_admin($user) ? ' required pattern="[a-z0-9]+(?:-[a-z0-9]+)*"' : ' readonly') . '><span class="muted">URL церкви: /' . e((string) ($church['slug'] ?? '')) . '/…</span></label>';
    echo '<label>Часовой пояс<input name="timezone" value="' . e($church['timezone']) . '"></label><button class="primary">Сохранить настройки церкви</button></form></article>';
    echo '<article class="panel"><h2>Согласие на обработку персональных данных</h2><form method="post" action="' . e(app_path('/church-settings-save')) . '" class="stack">' . csrf_field() . '<input type="hidden" name="name" value="' . e($church['name']) . '"><input type="hidden" name="city" value="' . e($church['city']) . '"><input type="hidden" name="slug" value="' . e((string) ($church['slug'] ?? '')) . '"><input type="hidden" name="timezone" value="' . e($church['timezone']) . '"><label>Текст согласия<textarea name="personal_data_consent_text" rows="14" required>' . e(church_personal_data_consent_text((int) $church['id'])) . '</textarea></label><p class="muted">Этот текст показывается в публичной форме заявки в домашнюю группу. Его можно адаптировать под юридическое лицо, адрес и перечень данных.</p><button class="primary">Сохранить текст согласия</button></form></article></section>';
    echo '<section class="panel"><div class="panel-head"><div><h2>Публичная страница</h2><p class="muted">Лендинг церкви — <code>' . e(public_church_about_path($church)) . '</code>. Карта этой церкви — <code>' . e(public_homegroup_catalog_path(current_church())) . '</code>; общая карта всех церквей — <code>/homegroup</code>. Формы нужды и свидетельства — <code>' . e(public_prayer_request_path($church)) . '</code> и <code>' . e(public_miracle_share_path($church)) . '</code>.</p></div><div class="quick-actions"><a class="ghost" href="' . e(public_church_about_path($church)) . '" target="_blank" rel="noopener">Лендинг церкви</a><a class="ghost" href="' . e(app_path('/church-page')) . '">Редактировать лендинг</a><a class="ghost" href="' . e(public_homegroup_catalog_path(current_church())) . '" target="_blank" rel="noopener">Карта групп церкви</a><a class="ghost" href="/homegroup" target="_blank" rel="noopener">Все церкви</a><a class="ghost" href="' . e(homegroup_join_base_path($church)) . '" target="_blank" rel="noopener">Форма заявки</a><a class="ghost" href="' . e(public_prayer_request_path($church)) . '" target="_blank" rel="noopener">Молитвенная нужда</a><a class="ghost" href="' . e(public_miracle_share_path($church)) . '" target="_blank" rel="noopener">Свидетельство</a></div></div></section>';
    page_footer();
}

function care_hub_page(array $user): void
{
    redirect_member_to_own_card($user);
    page_header('Сопровождение', $user);
    echo '<section class="grid three"><a class="panel action-card" href="' . e(app_path('/calendar')) . '"><h2>Календарь</h2><p>Встречи и пастырские контакты.</p></a><a class="panel action-card" href="' . e(app_path('/prayers')) . '"><h2>Молитвы</h2><p>Нужды, статусы и напоминания.</p></a><a class="panel action-card" href="' . e(public_prayer_request_path()) . '" target="_blank" rel="noopener"><h2>Оставить нужду</h2><p>Публичная форма молитвенной нужды этой церкви.</p></a><a class="panel action-card" href="' . e(app_path('/miracles')) . '"><h2>Чудеса</h2><p>Свидетельства о том, что сделал Господь.</p></a><a class="panel action-card" href="' . e(public_miracle_share_path()) . '" target="_blank" rel="noopener"><h2>Поделиться чудом</h2><p>Публичная форма свидетельства этой церкви.</p></a><a class="panel action-card" href="' . e(app_path('/newcomers')) . '"><h2>Новички</h2><p>Сценарии удержания и контакты.</p></a><a class="panel action-card" href="' . e(app_path('/services')) . '"><h2>Служения</h2><p>Планирование назначений.</p></a></section>';
    page_footer();
}

function analytics_hub_page(array $user): void
{
    redirect_member_to_own_card($user);
    page_header('Аналитика', $user);
    echo '<section class="grid three"><a class="panel action-card" href="' . e(app_path('/stats')) . '"><h2>Здоровье групп</h2><p>Риски, посещаемость и health score.</p></a><a class="panel action-card" href="' . e(app_path('/growth')) . '"><h2>Карта роста</h2><p>Церковь, группы и будущие лидеры.</p></a>';
    if (can_action($user, 'view_church_analytics')) echo '<a class="panel action-card" href="' . e(app_path('/regional')) . '"><h2>Обзор групп</h2><p>Проблемные группы и просьбы о помощи.</p></a><a class="panel action-card" href="' . e(app_path('/districts')) . '"><h2>Округа</h2><p>Границы, полигоны и окружные лидеры.</p></a><a class="panel action-card" href="' . e(app_path('/church-analytics')) . '"><h2>Церковь</h2><p>Квартал, год, группы, крещения и рост.</p></a>';
    echo '</section>';
    page_footer();
}

function handle_account_post(string $path, string $method, array $user): void
{
    if ($method !== 'POST') return;

    if ($path === '/group-create') {
        verify_csrf();
        if (!can_create_home_group($user)) ajax_redirect('/groups', 'Недостаточно прав');
        $churchId = (int) (effective_scope_church_id($user) ?? ($user['church_id'] ?? 0));
        if ($churchId <= 0) ajax_redirect('/groups', 'Церковь не назначена');
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            ajax_redirect('/groups', 'Укажите название группы');
        }
        $location = resolve_home_group_location_from_post();
        $settings = ChurchCare\home_group_settings_from_post($_POST);
        db()->prepare('INSERT INTO home_groups (church_id, name, address, short_address, weekday, age_category, group_types, meeting_weekday, meeting_time_start, meeting_time_end, max_people, latitude, longitude, opened_at, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 1)')
            ->execute([
                $churchId,
                $name,
                $location['address'],
                $settings['short_address'],
                $settings['weekday'],
                $settings['age_category'],
                ChurchCare\home_group_types_json($settings['group_types']),
                $settings['meeting_weekday'],
                $settings['meeting_time_start'],
                $settings['meeting_time_end'],
                max(1, (int) ($_POST['max_people'] ?? 16)),
                $location['latitude'],
                $location['longitude'],
            ]);
        $groupId = (int) db()->lastInsertId();
        $manualDistrictId = array_key_exists('district_id', $_POST) && $_POST['district_id'] !== ''
            ? (int) $_POST['district_id']
            : null;
        home_group_apply_district(
            $groupId,
            $churchId,
            $location['latitude'] !== null ? (float) $location['latitude'] : null,
            $location['longitude'] !== null ? (float) $location['longitude'] : null,
            $manualDistrictId,
            true,
            null
        );
        queue_email_notification((int) $user['id'], (string) $user['email'], 'Создана домашняя группа', 'Создана новая домашняя группа: ' . $name, 'group_created');
        audit('group_create', 'group', $groupId);
        ajax_redirect('/group?id=' . $groupId, 'Домашняя группа создана');
    }

    if ($path === '/group-update') {
        verify_csrf();
        $group = scoped_group($user, (int) ($_POST['id'] ?? 0));
        if (!$group || !can_edit_home_group_info($user, $group)) {
            ajax_redirect('/groups', 'Недостаточно прав');
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            ajax_redirect('/group?id=' . (int) $group['id'], 'Укажите название группы');
        }
        $location = resolve_home_group_location_from_post();
        $settings = ChurchCare\home_group_settings_from_post($_POST);
        db()->prepare('UPDATE home_groups SET name = ?, address = ?, short_address = ?, weekday = ?, age_category = ?, group_types = ?, meeting_weekday = ?, meeting_time_start = ?, meeting_time_end = ?, max_people = ?, latitude = ?, longitude = ? WHERE id = ?')
            ->execute([
                $name,
                $location['address'],
                $settings['short_address'],
                $settings['weekday'],
                $settings['age_category'],
                ChurchCare\home_group_types_json($settings['group_types']),
                $settings['meeting_weekday'],
                $settings['meeting_time_start'],
                $settings['meeting_time_end'],
                max(1, (int) ($_POST['max_people'] ?? $group['max_people'])),
                $location['latitude'],
                $location['longitude'],
                (int) $group['id'],
            ]);

        if (can_assign_home_group_leader($user) && array_key_exists('leader_person_id', $_POST)) {
            $leaderPersonId = $_POST['leader_person_id'] !== '' ? (int) $_POST['leader_person_id'] : null;
            try {
                assign_home_group_leader((int) $group['id'], $leaderPersonId);
            } catch (InvalidArgumentException $e) {
                ajax_redirect('/group?id=' . (int) $group['id'], $e->getMessage());
            }
        }

        $manualDistrictId = null;
        $hasManualDistrict = array_key_exists('district_id', $_POST);
        if ($hasManualDistrict) {
            $manualDistrictId = $_POST['district_id'] !== '' ? (int) $_POST['district_id'] : 0;
            // 0 means clear / leave auto — treat empty as auto-resolve
            if ($manualDistrictId === 0) {
                $manualDistrictId = null;
            }
        }
        home_group_apply_district(
            (int) $group['id'],
            (int) $group['church_id'],
            $location['latitude'] !== null ? (float) $location['latitude'] : null,
            $location['longitude'] !== null ? (float) $location['longitude'] : null,
            $manualDistrictId,
            true,
            $group
        );

        audit('group_update', 'group', (int) $group['id']);
        ajax_redirect('/group?id=' . (int) $group['id'], 'Информация о группе сохранена');
    }

    if ($path === '/group-delete') {
        verify_csrf();
        if (!can_delete_home_group($user)) ajax_redirect('/groups', 'Недостаточно прав');
        $group = scoped_group($user, (int) $_POST['id']);
        if (!$group) ajax_redirect('/groups', 'Группа не найдена или уже удалена');
        delete_home_group($group);
        audit('group_delete', 'group', (int) $group['id'], ['name' => $group['name']]);
        ajax_redirect('/groups', 'Домашняя группа удалена');
    }

    if ($path === '/church-settings-save') {
        verify_csrf();
        require_action($user, 'manage_structure');
        $churchId = current_church_id() ?? (int) $user['church_id'];
        if ($churchId <= 0) {
            ajax_redirect('/church-settings', 'Церковь не назначена');
        }
        if (!user_can_access_church($user, $churchId)) {
            ajax_redirect('/church-settings', 'Недостаточно прав');
        }
        $input = [
            'name' => $_POST['name'] ?? '',
            'city' => $_POST['city'] ?? '',
            'timezone' => $_POST['timezone'] ?? 'Europe/Moscow',
        ];
        if (array_key_exists('personal_data_consent_text', $_POST)) {
            $input['personal_data_consent_text'] = $_POST['personal_data_consent_text'];
        }
        if (is_super_admin($user) && array_key_exists('slug', $_POST)) {
            $input['slug'] = $_POST['slug'];
        }
        $result = save_church_settings($churchId, $input, is_super_admin($user));
        if (!$result['ok']) {
            ajax_redirect('/church-settings', $result['error']);
        }
        audit('church_settings_save', 'church', $churchId);
        ajax_redirect('/church-settings', 'Настройки церкви сохранены');
    }

    if ($path === '/profile-update') {
        verify_csrf();
        if (function_exists('is_demo_session') && is_demo_session()) {
            ajax_redirect('/profile', 'В демо нельзя менять логин и email');
        }
        $email = trim((string) $_POST['email']);
        if (email_owned_by_other($email, (int) $user['id'])) ajax_redirect('/profile', 'Почта уже используется другим пользователем');
        $login = unique_user_login((string) ($_POST['login'] ?? login_from_email($email)), (int) $user['id']);
        db()->prepare('UPDATE users SET name = ?, login = ?, email = ? WHERE id = ?')->execute([trim((string) $_POST['name']), $login, $email, $user['id']]);
        audit('profile_update', 'user', (int) $user['id']);
        ajax_redirect('/profile', 'Профиль обновлен');
    }

    if ($path === '/password-change') {
        verify_csrf();
        if (function_exists('is_demo_session') && is_demo_session()) {
            ajax_redirect('/profile', 'В демо смена пароля отключена');
        }
        $fresh = fetch_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$user['id']]);
        if (!$fresh || !password_verify((string) $_POST['current_password'], (string) $fresh['password_hash']) || mb_strlen((string) $_POST['new_password']) < 8) {
            ajax_redirect('/profile', 'Пароль не изменен: проверьте текущий пароль и длину нового');
        }
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash((string) $_POST['new_password'], PASSWORD_DEFAULT), $user['id']]);
        queue_email_notification((int) $user['id'], (string) $user['email'], 'Пароль CRM изменен', 'Пароль вашей учетной записи был изменен из профиля.', 'password_changed');
        audit('password_change', 'user', (int) $user['id']);
        ajax_redirect('/profile', 'Пароль изменен');
    }

    if ($path === '/notification-settings') {
        verify_csrf();
        db()->prepare('INSERT INTO user_notification_settings (user_id, email_enabled, webpush_enabled) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE email_enabled = VALUES(email_enabled), webpush_enabled = VALUES(webpush_enabled)')
            ->execute([$user['id'], isset($_POST['email_enabled']) ? 1 : 0, isset($_POST['webpush_enabled']) ? 1 : 0]);
        audit('notification_settings_update', 'user', (int) $user['id']);
        ajax_redirect('/profile', 'Настройки уведомлений сохранены');
    }

    if ($path === '/role-invite-approve') {
        verify_csrf();
        $roleRequestsPath = '/role-requests';
        if (!role_invite_storage_ready()) {
            ajax_redirect($roleRequestsPath, 'Заявки на роли недоступны: примените миграцию базы данных');
        }
        $request = role_invite_request_with_user((int) ($_POST['id'] ?? 0));
        if (!$request || !can_approve_role_invite_request($user, $request)) {
            ajax_redirect($roleRequestsPath, 'Недостаточно прав для подтверждения заявки');
        }
        $role = (string) $request['requested_role'];
        $groupId = $request['group_id'] !== null ? (int) $request['group_id'] : null;
        if (role_invite_role_requires_group($role)) {
            $groupId = (int) ($_POST['group_id'] ?? $groupId ?? 0);
            $group = fetch_one('SELECT id, church_id FROM home_groups WHERE id = ? AND church_id = ? AND is_active = 1 LIMIT 1', [$groupId, (int) $request['church_id']]);
            if (!$group) {
                ajax_redirect($roleRequestsPath, 'Выберите доступную группу для этой роли');
            }
        }

        db()->beginTransaction();
        try {
            db()->prepare('UPDATE users SET role = ?, church_id = ?, group_id = ? WHERE id = ?')
                ->execute([$role, (int) $request['church_id'], $groupId, (int) $request['user_id']]);
            db()->prepare('UPDATE role_invitation_requests SET status = "approved", group_id = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND status = "pending"')
                ->execute([$groupId, (int) $user['id'], (int) $request['id']]);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            ajax_redirect($roleRequestsPath, 'Не удалось подтвердить заявку');
        }

        notify_role_change(['id' => (int) $request['user_id'], 'email' => (string) $request['user_email']], $role);
        audit('role_invite_approved', 'user', (int) $request['user_id'], ['role' => $role, 'request_id' => (int) $request['id']]);
        ajax_redirect($roleRequestsPath, 'Роль подтверждена: ' . role_label($role));
    }

    if ($path === '/role-invite-reject') {
        verify_csrf();
        $roleRequestsPath = '/role-requests';
        if (!role_invite_storage_ready()) {
            ajax_redirect($roleRequestsPath, 'Заявки на роли недоступны: примените миграцию базы данных');
        }
        $request = role_invite_request_with_user((int) ($_POST['id'] ?? 0));
        if (!$request || !can_approve_role_invite_request($user, $request)) {
            ajax_redirect($roleRequestsPath, 'Недостаточно прав для отклонения заявки');
        }
        db()->prepare('UPDATE role_invitation_requests SET status = "rejected", rejected_by = ?, rejected_at = NOW() WHERE id = ? AND status = "pending"')
            ->execute([(int) $user['id'], (int) $request['id']]);
        audit('role_invite_rejected', 'user', (int) $request['user_id'], ['role' => (string) $request['requested_role'], 'request_id' => (int) $request['id']]);
        ajax_redirect($roleRequestsPath, 'Заявка отклонена');
    }

    if ($path === '/user-update') {
        verify_csrf();
        require_action($user, 'edit_people');
        $target = fetch_one('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) $_POST['id']]);
        if (!$target || !can_edit_user_record($user, $target)) ajax_redirect('/users', 'Недостаточно прав');
        $role = (string) ($_POST['role'] ?? $target['role']);
        $currentRole = (string) $target['role'];
        if (!can_transition_role((string) $user['role'], $currentRole, $role)) {
            $role = $currentRole;
        }
        $groupId = $_POST['group_id'] ?: null;
        if ($groupId !== null) {
            $group = scoped_group($user, (int) $groupId);
            $groupId = $group ? (int) $group['id'] : null;
        }
        if (in_array($role, ['leader', 'assistant'], true) && !$groupId) {
            $groupId = $target['group_id'];
        }
        $email = trim((string) $_POST['email']);
        if (email_owned_by_other($email, (int) $target['id'])) ajax_redirect(user_admin_return_path($target), 'Почта уже используется другим пользователем');
        $login = unique_user_login((string) ($_POST['login'] ?? login_from_email($email)), (int) $target['id']);
        db()->prepare('UPDATE users SET name = ?, login = ?, email = ?, role = ?, group_id = ? WHERE id = ?')
            ->execute([trim((string) $_POST['name']), $login, $email, $role, $groupId, (int) $target['id']]);
        $person = fetch_one('SELECT id, group_id FROM people WHERE user_id = ? LIMIT 1', [(int) $target['id']]);
        if ($person && $groupId) {
            person_add_group_membership((int) $person['id'], (int) $groupId, true);
        }
        if ($role === 'leader' && $groupId && $person) {
            assign_home_group_leader((int) $groupId, (int) $person['id']);
        }
        if ($role !== $target['role']) notify_role_change($target, $role);
        audit('user_update', 'user', (int) $target['id'], ['role' => $role, 'group_id' => $groupId]);
        ajax_redirect(user_admin_return_path($target), 'Пользователь обновлен');
    }

    if ($path === '/user-access-update') {
        verify_csrf();
        require_action($user, 'edit_people');
        $target = fetch_one('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) ($_POST['id'] ?? 0)]);
        if (!$target || !can_edit_user_record($user, $target)) {
            ajax_redirect('/users', 'Недостаточно прав');
        }
        $currentRole = (string) $target['role'];
        $role = (string) ($_POST['role'] ?? $currentRole);
        if (!can_transition_role((string) $user['role'], $currentRole, $role)) {
            ajax_redirect(user_admin_return_path($target), 'Эту роль нельзя назначить с текущей');
        }

        $groupPosted = array_key_exists('group_id', $_POST);
        $groupId = $groupPosted ? ($_POST['group_id'] ?: null) : ($target['group_id'] ?? null);
        if ($groupId !== null) {
            $group = scoped_group($user, (int) $groupId);
            $groupId = $group ? (int) $group['id'] : null;
        }
        if (in_array($role, ['leader', 'assistant'], true) && !$groupId) {
            $groupId = $target['group_id'];
        }

        db()->prepare('UPDATE users SET role = ?, group_id = ? WHERE id = ?')
            ->execute([$role, $groupId, (int) $target['id']]);
        $person = fetch_one('SELECT id FROM people WHERE user_id = ? LIMIT 1', [(int) $target['id']]);
        if ($person && $groupId) {
            person_add_group_membership((int) $person['id'], (int) $groupId, true);
        }
        if ($role === 'leader' && $groupId && $person) {
            try {
                assign_home_group_leader((int) $groupId, (int) $person['id']);
            } catch (InvalidArgumentException $e) {
                ajax_redirect(user_admin_return_path($target), $e->getMessage());
            }
        }
        if ($role !== $currentRole) {
            notify_role_change($target, $role);
        }
        audit('user_access_update', 'user', (int) $target['id'], ['role' => $role, 'group_id' => $groupId]);
        ajax_redirect(user_admin_return_path($target), 'Роль и группа сохранены');
    }

    if ($path === '/user-password-reset') {
        verify_csrf();
        require_action($user, 'edit_people');
        $target = fetch_one('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) $_POST['id']]);
        if (!$target || !can_edit_user_record($user, $target)) ajax_redirect('/users', 'Недостаточно прав');
        $token = bin2hex(random_bytes(24));
        db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 2 HOUR))')->execute([(int) $target['id'], hash('sha256', $token)]);
        $url = public_url('/reset-password?token=' . urlencode($token));
        queue_email_notification((int) $target['id'], (string) $target['email'], 'Восстановление пароля CRM', 'Ссылка для смены пароля: ' . $url, 'password_reset');
        audit('password_reset_sent_by_admin', 'user', (int) $target['id']);
        ajax_redirect(user_admin_return_path($target), 'Ссылка восстановления поставлена в email-очередь');
    }

    if ($path === '/user-delete') {
        verify_csrf();
        require_action($user, 'manage_structure');
        $returnTo = ($_POST['return'] ?? '') === 'admin' ? '/admin' : '/users';
        $target = fetch_one('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) $_POST['id']]);
        if (!$target || !can_delete_user($user, $target)) ajax_redirect($returnTo, 'Недостаточно прав');
        if ((int) $target['id'] === (int) $user['id']) {
            ajax_redirect($returnTo, 'Нельзя удалить учётную запись, под которой вы вошли');
        }
        if (function_exists('is_demo_session') && is_demo_session() && (int) $target['id'] === (int) (real_user()['id'] ?? 0)) {
            ajax_redirect($returnTo, 'Нельзя удалить учётную запись текущей демо-сессии');
        }
        if (in_array($target['role'], ['admin', 'super_admin'], true)) {
            $adminCount = (int) (fetch_one('SELECT COUNT(*) AS c FROM users WHERE role IN ("admin", "super_admin")')['c'] ?? 0);
            if ($adminCount <= 1) {
                ajax_redirect($returnTo, 'Нельзя удалить последнего администратора платформы');
            }
        }
        $targetId = (int) $target['id'];
        audit('user_delete', 'user', $targetId, ['login' => $target['login'], 'email' => $target['email'], 'role' => $target['role']]);
        delete_user_account($targetId);
        if (wants_ajax()) {
            json_response([
                'ok' => true,
                'message' => 'Пользователь удален',
                'remove' => '[data-user-row="' . $targetId . '"]',
            ]);
        }
        ajax_redirect($returnTo, 'Пользователь удален');
    }

    if ($path === '/person-account-invite') {
        verify_csrf();
        if (!can_invite_person_account($user)) {
            ajax_redirect('/person?id=' . (int) ($_POST['person_id'] ?? 0), 'Недостаточно прав');
        }
        $person = scoped_person($user, (int) $_POST['person_id']);
        if (!$person) ajax_redirect('/users', 'Карточка не найдена или недоступна');
        if ((int) ($person['user_id'] ?? 0) > 0) ajax_redirect('/person?id=' . (int) $person['id'], 'У этого человека уже есть учетная запись');
        create_account_invite_link((int) $person['id'], (int) $user['id']);
        audit('account_invite_created', 'person', (int) $person['id']);
        ajax_redirect('/person?id=' . (int) $person['id'], 'Ссылка для создания учетной записи готова');
    }

    if ($path === '/participant-approve') {
        verify_csrf();
        require_action($user, 'edit_people');
        if (!can_create_home_group($user)) ajax_redirect('/participant-requests', 'Недостаточно прав');
        $request = fetch_one('SELECT * FROM participant_requests WHERE id = ? AND status = "new" LIMIT 1', [(int) $_POST['id']]);
        if (!$request) ajax_redirect('/participant-requests', 'Заявка не найдена');
        $group = scoped_group($user, (int) $_POST['group_id']);
        if (!$group) ajax_redirect('/participant-requests', 'Группа не найдена или недоступна');
        $email = trim((string) ($_POST['email'] ?? $request['email'] ?? ''));

        try {
            db()->beginTransaction();
            db()->prepare('INSERT INTO people (group_id, full_name, phone, telegram, address, latitude, longitude, first_visit_date, training_stage, email) VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), "новичок", ?)')
                ->execute([
                    (int) $group['id'],
                    $request['full_name'],
                    ChurchCare\normalize_phone($request['phone'] ?? null) ?? ($request['phone'] ?: null),
                    ChurchCare\normalize_telegram($request['telegram'] ?? null) ?? (ChurchCare\telegram_username($request['telegram'] ?? null) ?: null),
                    ChurchCare\participant_request_address_line($request),
                    isset($request['latitude']) ? (float) $request['latitude'] : null,
                    isset($request['longitude']) ? (float) $request['longitude'] : null,
                    $email,
                ]);
            $personId = (int) db()->lastInsertId();
            $person = ['id' => $personId, 'group_id' => (int) $group['id'], 'full_name' => $request['full_name']];
            person_add_group_membership($personId, (int) $group['id'], true);
            $account = create_member_account_for_person($person, $email);
            $setupPath = create_password_setup_link((int) $account['id']);
            db()->prepare('UPDATE participant_requests SET status = "approved", person_id = ?, handled_by = ?, handled_at = NOW(), email = ? WHERE id = ?')->execute([$personId, $user['id'], $email, (int) $request['id']]);
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $message = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Не удалось создать карточку и учетную запись';
            ajax_redirect('/participant-requests', $message);
        }

        queue_email_notification((int) $account['id'], $email, 'Доступ к ' . app_name(), 'Вас добавили в домашнюю группу. Задайте пароль по ссылке: ' . public_url($setupPath), 'participant_approved');
        audit('participant_request_approved', 'person', $personId, ['user_id' => (int) $account['id']]);
        ajax_redirect('/person?id=' . $personId, 'Карточка и учетная запись участника созданы');
    }

    if ($path === '/participant-reject') {
        verify_csrf();
        require_action($user, 'edit_people');
        if (!can_create_home_group($user)) {
            ajax_redirect('/participant-requests', 'Недостаточно прав');
        }
        $request = fetch_one('SELECT * FROM participant_requests WHERE id = ? AND status = "new" LIMIT 1', [(int) ($_POST['id'] ?? 0)]);
        if (!$request) {
            ajax_redirect('/participant-requests', 'Заявка не найдена');
        }
        db()->prepare('UPDATE participant_requests SET status = "declined", handled_by = ?, handled_at = NOW() WHERE id = ?')
            ->execute([(int) $user['id'], (int) $request['id']]);
        audit('participant_request_declined', 'participant_request', (int) $request['id']);
        ajax_redirect('/participant-requests', 'Заявка отклонена');
    }
}
