<?php

declare(strict_types=1);

/**
 * MCP tool catalog and handlers. Every tool checks API-key scopes AND CRM role permissions.
 */

/**
 * @return list<array{name: string, description: string, inputSchema: array<string, mixed>, scope: string}>
 */
function mcp_tool_definitions(): array
{
    return [
        [
            'name' => 'get_me',
            'description' => 'Вернуть текущего пользователя CRM (роль, церковь, группа, scopes ключа).',
            'scope' => 'read',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'list_groups',
            'description' => 'Список доступных домашних групп в scope роли.',
            'scope' => 'read',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'list_people',
            'description' => 'Список людей в доступных группах (без чувствительных заметок). Опционально фильтр по group_id.',
            'scope' => 'read',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'group_id' => ['type' => 'integer', 'description' => 'ID домашней группы'],
                    'limit' => ['type' => 'integer', 'description' => 'Максимум записей (1–200, по умолчанию 50)'],
                ],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'get_person',
            'description' => 'Карточка человека по id (без sensitive_notes).',
            'scope' => 'read',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'person_id' => ['type' => 'integer', 'description' => 'ID карточки people'],
                ],
                'required' => ['person_id'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'get_care_radar',
            'description' => 'Радар заботы: люди с риском по посещаемости/контакту (не green).',
            'scope' => 'read',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'group_id' => ['type' => 'integer', 'description' => 'Ограничить группой'],
                    'min_level' => [
                        'type' => 'string',
                        'enum' => ['yellow', 'orange', 'red'],
                        'description' => 'Минимальный уровень риска (по умолчанию yellow)',
                    ],
                    'limit' => ['type' => 'integer', 'description' => 'Максимум (1–100, по умолчанию 30)'],
                ],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'list_inactive_people',
            'description' => 'Кто давно не ходил: то же, что радар, с явным акцентом на отсутствие.',
            'scope' => 'read',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'group_id' => ['type' => 'integer'],
                    'min_level' => [
                        'type' => 'string',
                        'enum' => ['yellow', 'orange', 'red'],
                        'description' => 'По умолчанию orange (не был ~месяц+)',
                    ],
                    'limit' => ['type' => 'integer'],
                ],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'get_group_stats',
            'description' => 'Статистика здоровья домашних групп (90 дней): посещаемость, новички, оценка.',
            'scope' => 'read',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'group_id' => ['type' => 'integer', 'description' => 'Одна группа; иначе все доступные'],
                ],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'get_church_stats',
            'description' => 'Церковная аналитика: активные/новые/закрытые группы, крещения, новички. Нужны scope church и право view_church_analytics.',
            'scope' => 'church',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'list_meetings',
            'description' => 'Список встреч доступных групп.',
            'scope' => 'read',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'group_id' => ['type' => 'integer'],
                    'from_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'to_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'limit' => ['type' => 'integer', 'description' => '1–100, по умолчанию 30'],
                ],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'get_meeting',
            'description' => 'Встреча и текущая посещаемость.',
            'scope' => 'read',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'meeting_id' => ['type' => 'integer'],
                ],
                'required' => ['meeting_id'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'create_meeting',
            'description' => 'Создать встречу домашней группы (нужны scope attendance и роль assistant+).',
            'scope' => 'attendance',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'group_id' => ['type' => 'integer'],
                    'meeting_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'topic' => ['type' => 'string'],
                ],
                'required' => ['group_id', 'meeting_date'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'save_meeting_attendance',
            'description' => 'Зафиксировать кто пришёл: полный список present_person_ids; остальные участники группы = absent.',
            'scope' => 'attendance',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'meeting_id' => ['type' => 'integer'],
                    'present_person_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'ID людей, отмеченных присутствующими',
                    ],
                    'atmosphere' => ['type' => 'string'],
                    'had_conflicts' => ['type' => 'boolean'],
                    'pastor_help_needed' => ['type' => 'boolean'],
                    'notes' => ['type' => 'string'],
                ],
                'required' => ['meeting_id', 'present_person_ids'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'update_profile',
            'description' => 'Обновить свою учётную запись: name, login, email.',
            'scope' => 'people',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'login' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'update_person',
            'description' => 'Обновить поля пастырской карточки (нужны scope people, edit_people / can_edit_person_field).',
            'scope' => 'people',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'person_id' => ['type' => 'integer'],
                    'phone' => ['type' => 'string'],
                    'telegram' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'address' => ['type' => 'string'],
                    'birth_date' => ['type' => 'string'],
                    'marital_status' => ['type' => 'string'],
                    'first_visit_date' => ['type' => 'string'],
                    'ministry' => ['type' => 'string'],
                    'notes' => ['type' => 'string'],
                ],
                'required' => ['person_id'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'change_user_role',
            'description' => 'Повысить или понизить роль пользователя по матрице can_transition_role.',
            'scope' => 'roles',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'user_id' => ['type' => 'integer'],
                    'role' => [
                        'type' => 'string',
                        'enum' => ['member', 'assistant', 'leader', 'district_leader', 'pastor', 'admin', 'super_admin'],
                    ],
                    'group_id' => ['type' => 'integer', 'description' => 'Обязательна для leader/assistant'],
                ],
                'required' => ['user_id', 'role'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'add_contact_log',
            'description' => 'Записать контакт с человеком (звонок/сообщение).',
            'scope' => 'people',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'person_id' => ['type' => 'integer'],
                    'type' => ['type' => 'string', 'description' => 'call|message|visit|other'],
                    'summary' => ['type' => 'string'],
                    'absence_reason' => ['type' => 'string'],
                    'next_contact_at' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                ],
                'required' => ['person_id', 'type', 'summary'],
                'additionalProperties' => false,
            ],
        ],
        [
            'name' => 'list_assignable_roles',
            'description' => 'Какие роли текущий пользователь может назначить целевому (для UI агента).',
            'scope' => 'roles',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'user_id' => ['type' => 'integer', 'description' => 'Целевой пользователь'],
                ],
                'required' => ['user_id'],
                'additionalProperties' => false,
            ],
        ],
    ];
}

/**
 * Tools visible for the principal's scopes (role gates still apply at call time).
 *
 * @param list<string> $scopes
 * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
 */
function mcp_tools_for_scopes(array $scopes): array
{
    $out = [];
    foreach (mcp_tool_definitions() as $tool) {
        if (!mcp_has_api_key_scope($scopes, (string) $tool['scope'])) {
            continue;
        }
        $out[] = [
            'name' => $tool['name'],
            'description' => $tool['description'],
            'inputSchema' => $tool['inputSchema'],
        ];
    }
    return $out;
}

/**
 * @param array{user: array<string, mixed>, scopes: list<string>, key_id: int} $principal
 * @param array<string, mixed> $arguments
 * @return array{ok: bool, data?: mixed, error?: string}
 */
function mcp_call_tool(array $principal, string $name, array $arguments): array
{
    $user = $principal['user'];
    $scopes = $principal['scopes'];
    $def = null;
    foreach (mcp_tool_definitions() as $tool) {
        if ($tool['name'] === $name) {
            $def = $tool;
            break;
        }
    }
    if ($def === null) {
        return ['ok' => false, 'error' => 'Неизвестный инструмент: ' . $name];
    }
    if (!mcp_has_api_key_scope($scopes, (string) $def['scope'])) {
        return ['ok' => false, 'error' => 'Ключ не даёт scope «' . $def['scope'] . '» для ' . $name];
    }

    try {
        $data = mcp_dispatch_tool($user, $name, $arguments);
        audit('mcp_tool_call', 'mcp', (int) $principal['key_id'], [
            'tool' => $name,
            'ok' => true,
            'args_keys' => array_keys($arguments),
        ]);
        return ['ok' => true, 'data' => $data];
    } catch (Throwable $e) {
        audit('mcp_tool_call', 'mcp', (int) $principal['key_id'], [
            'tool' => $name,
            'ok' => false,
            'error' => $e->getMessage(),
        ]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * @param array<string, mixed> $arguments
 * @return mixed
 */
function mcp_dispatch_tool(array $user, string $name, array $arguments)
{
    return match ($name) {
        'get_me' => mcp_tool_get_me($user),
        'list_groups' => mcp_tool_list_groups($user),
        'list_people' => mcp_tool_list_people($user, $arguments),
        'get_person' => mcp_tool_get_person($user, $arguments),
        'get_care_radar' => mcp_tool_care_radar($user, $arguments, 'yellow'),
        'list_inactive_people' => mcp_tool_care_radar($user, $arguments, 'orange'),
        'get_group_stats' => mcp_tool_group_stats($user, $arguments),
        'get_church_stats' => mcp_tool_church_stats($user),
        'list_meetings' => mcp_tool_list_meetings($user, $arguments),
        'get_meeting' => mcp_tool_get_meeting($user, $arguments),
        'create_meeting' => mcp_tool_create_meeting($user, $arguments),
        'save_meeting_attendance' => mcp_tool_save_attendance($user, $arguments),
        'update_profile' => mcp_tool_update_profile($user, $arguments),
        'update_person' => mcp_tool_update_person($user, $arguments),
        'change_user_role' => mcp_tool_change_role($user, $arguments),
        'add_contact_log' => mcp_tool_add_contact($user, $arguments),
        'list_assignable_roles' => mcp_tool_list_assignable_roles($user, $arguments),
        default => throw new RuntimeException('Неизвестный инструмент'),
    };
}

function mcp_clamp_limit(mixed $value, int $default, int $max): int
{
    $n = (int) ($value ?? $default);
    if ($n < 1) {
        $n = $default;
    }
    return min($max, $n);
}

/** @return array<string, mixed> */
function mcp_tool_get_me(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'name' => (string) $user['name'],
        'login' => (string) $user['login'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
        'role_label' => role_label((string) $user['role']),
        'church_id' => $user['church_id'] !== null ? (int) $user['church_id'] : null,
        'group_id' => $user['group_id'] !== null ? (int) $user['group_id'] : null,
        'capabilities' => [
            'can_edit_group_data' => can_edit_group_data($user),
            'edit_people' => can_action($user, 'edit_people'),
            'view_church_analytics' => can_action($user, 'view_church_analytics'),
            'manage_users' => can_manage_users($user),
        ],
    ];
}

/** @return list<array<string, mixed>> */
function mcp_tool_list_groups(array $user): array
{
    [$sql, $params] = accessible_group_sql($user, 'g');
    return fetch_all(
        "SELECT g.id, g.name, g.district, g.address, g.short_address, g.meeting_weekday, g.meeting_time,
                g.leader_person_id, g.assistant_person_id, c.name AS church_name,
                (SELECT COUNT(*) FROM people p WHERE p.group_id = g.id AND p.deleted_at IS NULL) AS people_count
         FROM home_groups g
         JOIN churches c ON c.id = g.church_id
         WHERE $sql AND g.is_active = 1
         ORDER BY g.name",
        $params
    );
}

/**
 * @param array<string, mixed> $arguments
 * @return list<array<string, mixed>>
 */
function mcp_tool_list_people(array $user, array $arguments): array
{
    $groupId = isset($arguments['group_id']) ? (int) $arguments['group_id'] : null;
    if ($groupId !== null && $groupId > 0 && !scoped_group($user, $groupId)) {
        throw new InvalidArgumentException('Группа недоступна');
    }
    $limit = mcp_clamp_limit($arguments['limit'] ?? null, 50, 200);
    $people = care_people($user, $groupId && $groupId > 0 ? $groupId : null, 'name');
    $out = [];
    foreach (array_slice($people, 0, $limit) as $person) {
        $out[] = care_public_person_row($person, $user);
    }
    return $out;
}

/**
 * @param array<string, mixed> $arguments
 * @return array<string, mixed>
 */
function mcp_tool_get_person(array $user, array $arguments): array
{
    $person = scoped_person($user, (int) ($arguments['person_id'] ?? 0));
    if (!$person) {
        throw new InvalidArgumentException('Карточка не найдена или недоступна');
    }
    $enriched = care_people($user, (int) $person['group_id'], 'name');
    foreach ($enriched as $row) {
        if ((int) $row['id'] === (int) $person['id']) {
            return care_public_person_row($row, $user);
        }
    }
    $person['group_name'] = fetch_one('SELECT name FROM home_groups WHERE id = ?', [(int) $person['group_id']])['name'] ?? null;
    return care_public_person_row($person, $user);
}

/**
 * @param array<string, mixed> $arguments
 * @return list<array<string, mixed>>
 */
function mcp_tool_care_radar(array $user, array $arguments, string $defaultMin): array
{
    if (!can_edit_group_data($user) && $user['role'] !== 'member') {
        // regional+ already can_edit; members only see themselves via care_people scope
    }
    if ($user['role'] === 'member') {
        throw new RuntimeException('Радар заботы недоступен участнику');
    }
    $groupId = isset($arguments['group_id']) ? (int) $arguments['group_id'] : null;
    if ($groupId !== null && $groupId > 0 && !scoped_group($user, $groupId)) {
        throw new InvalidArgumentException('Группа недоступна');
    }
    $minLevel = (string) ($arguments['min_level'] ?? $defaultMin);
    if (!in_array($minLevel, ['yellow', 'orange', 'red'], true)) {
        $minLevel = $defaultMin;
    }
    $limit = mcp_clamp_limit($arguments['limit'] ?? null, 30, 100);
    $rows = care_inactive_people($user, $groupId && $groupId > 0 ? $groupId : null, $minLevel);
    $out = [];
    foreach (array_slice($rows, 0, $limit) as $person) {
        $out[] = care_public_person_row($person, $user);
    }
    return $out;
}

/**
 * @param array<string, mixed> $arguments
 * @return list<array<string, mixed>>
 */
function mcp_tool_group_stats(array $user, array $arguments): array
{
    if ($user['role'] === 'member') {
        throw new RuntimeException('Статистика групп недоступна участнику');
    }
    $groupId = isset($arguments['group_id']) ? (int) $arguments['group_id'] : null;
    if ($groupId !== null && $groupId > 0 && !scoped_group($user, $groupId)) {
        throw new InvalidArgumentException('Группа недоступна');
    }
    return group_health_stats($user, $groupId && $groupId > 0 ? $groupId : null);
}

/** @return array<string, int|string> */
function mcp_tool_church_stats(array $user): array
{
    return church_analytics_stats($user);
}

/**
 * @param array<string, mixed> $arguments
 * @return list<array<string, mixed>>
 */
function mcp_tool_list_meetings(array $user, array $arguments): array
{
    if ($user['role'] === 'member') {
        throw new RuntimeException('Список встреч недоступен участнику');
    }
    [$sql, $params] = accessible_group_sql($user, 'g');
    $extra = '';
    if (!empty($arguments['group_id'])) {
        $gid = (int) $arguments['group_id'];
        if (!scoped_group($user, $gid)) {
            throw new InvalidArgumentException('Группа недоступна');
        }
        $extra .= ' AND m.group_id = ?';
        $params[] = $gid;
    }
    if (!empty($arguments['from_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $arguments['from_date'])) {
        $extra .= ' AND m.meeting_date >= ?';
        $params[] = (string) $arguments['from_date'];
    }
    if (!empty($arguments['to_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $arguments['to_date'])) {
        $extra .= ' AND m.meeting_date <= ?';
        $params[] = (string) $arguments['to_date'];
    }
    $limit = mcp_clamp_limit($arguments['limit'] ?? null, 30, 100);
    return fetch_all(
        "SELECT m.id, m.group_id, g.name AS group_name, m.meeting_date, m.topic, m.atmosphere,
                m.had_conflicts, m.pastor_help_needed, m.notes
         FROM meetings m
         JOIN home_groups g ON g.id = m.group_id
         WHERE $sql AND g.is_active = 1 $extra
         ORDER BY m.meeting_date DESC, m.id DESC
         LIMIT $limit",
        $params
    );
}

/**
 * @param array<string, mixed> $arguments
 * @return array<string, mixed>
 */
function mcp_tool_get_meeting(array $user, array $arguments): array
{
    $meeting = scoped_meeting($user, (int) ($arguments['meeting_id'] ?? 0));
    if (!$meeting) {
        throw new InvalidArgumentException('Встреча не найдена или недоступна');
    }
    $attendance = fetch_all(
        'SELECT a.person_id, a.status, p.full_name
         FROM attendance a
         JOIN people p ON p.id = a.person_id
         WHERE a.meeting_id = ?
         ORDER BY p.full_name',
        [(int) $meeting['id']]
    );
    return [
        'meeting' => [
            'id' => (int) $meeting['id'],
            'group_id' => (int) $meeting['group_id'],
            'group_name' => $meeting['group_name'] ?? null,
            'meeting_date' => $meeting['meeting_date'],
            'topic' => $meeting['topic'],
            'atmosphere' => $meeting['atmosphere'],
            'had_conflicts' => (int) ($meeting['had_conflicts'] ?? 0),
            'pastor_help_needed' => (int) ($meeting['pastor_help_needed'] ?? 0),
            'notes' => $meeting['notes'],
        ],
        'attendance' => $attendance,
    ];
}

/**
 * @param array<string, mixed> $arguments
 * @return array<string, mixed>
 */
function mcp_tool_create_meeting(array $user, array $arguments): array
{
    return meeting_create_for_user(
        $user,
        (int) ($arguments['group_id'] ?? 0),
        (string) ($arguments['meeting_date'] ?? ''),
        (string) ($arguments['topic'] ?? '')
    );
}

/**
 * @param array<string, mixed> $arguments
 * @return array<string, mixed>
 */
function mcp_tool_save_attendance(array $user, array $arguments): array
{
    $present = $arguments['present_person_ids'] ?? [];
    if (!is_array($present)) {
        throw new InvalidArgumentException('present_person_ids должен быть массивом');
    }
    return meeting_save_attendance_for_user(
        $user,
        (int) ($arguments['meeting_id'] ?? 0),
        array_map('intval', $present),
        isset($arguments['atmosphere']) ? (string) $arguments['atmosphere'] : null,
        !empty($arguments['had_conflicts']),
        !empty($arguments['pastor_help_needed']),
        (string) ($arguments['notes'] ?? '')
    );
}

/**
 * @param array<string, mixed> $arguments
 * @return array<string, mixed>
 */
function mcp_tool_update_profile(array $user, array $arguments): array
{
    $name = array_key_exists('name', $arguments) ? trim((string) $arguments['name']) : (string) $user['name'];
    $email = array_key_exists('email', $arguments) ? trim((string) $arguments['email']) : (string) $user['email'];
    $loginRaw = array_key_exists('login', $arguments) ? (string) $arguments['login'] : (string) $user['login'];
    if ($name === '' || $email === '') {
        throw new InvalidArgumentException('Имя и почта обязательны');
    }
    $email = function_exists('normalize_account_email')
        ? normalize_account_email($email)
        : mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Некорректный email');
    }
    if (email_owned_by_other($email, (int) $user['id'])) {
        throw new InvalidArgumentException('Почта уже используется другим пользователем в этой церкви');
    }
    $login = unique_user_login($loginRaw, (int) $user['id']);
    db()->prepare('UPDATE users SET name = ?, login = ?, email = ? WHERE id = ?')
        ->execute([$name, $login, $email, (int) $user['id']]);
    audit('profile_update', 'user', (int) $user['id'], ['source' => 'mcp']);
    return [
        'id' => (int) $user['id'],
        'name' => $name,
        'login' => $login,
        'email' => $email,
    ];
}

/**
 * @param array<string, mixed> $arguments
 * @return array<string, mixed>
 */
function mcp_tool_update_person(array $user, array $arguments): array
{
    $person = scoped_person($user, (int) ($arguments['person_id'] ?? 0));
    if (!$person) {
        throw new InvalidArgumentException('Карточка не найдена или недоступна');
    }

    $isOwnCard = (int) ($person['user_id'] ?? 0) === (int) $user['id'];
    $canPastoralEdit = can_edit_group_data($user) && can_action($user, 'edit_people');
    if (!$canPastoralEdit && !($user['role'] === 'member' && $isOwnCard)) {
        throw new RuntimeException('Недостаточно прав для редактирования карточки');
    }

    $memberAllowed = ['phone', 'telegram', 'email', 'address'];
    $fields = ['phone', 'telegram', 'email', 'address', 'birth_date', 'marital_status', 'first_visit_date', 'ministry', 'notes'];
    $updates = [];
    $params = [];
    $changed = [];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $arguments)) {
            continue;
        }
        if (!$canPastoralEdit && !in_array($field, $memberAllowed, true)) {
            throw new RuntimeException('Участник может менять только контактные поля своей карточки');
        }
        if ($field === 'notes') {
            $value = trim((string) $arguments[$field]);
            $parsed = ['ok' => true, 'value' => $value === '' ? null : $value];
        } else {
            $parsed = parse_person_field_value($field, $arguments[$field] === null ? null : (string) $arguments[$field]);
        }
        if (empty($parsed['ok'])) {
            throw new InvalidArgumentException((string) ($parsed['error'] ?? ('Некорректное значение: ' . $field)));
        }
        $updates[] = "$field = ?";
        $params[] = $parsed['value'];
        $changed[$field] = $parsed['value'];
    }
    if ($updates === []) {
        throw new InvalidArgumentException('Не передано ни одного поля для обновления');
    }
    $params[] = (int) $person['id'];
    db()->prepare('UPDATE people SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
    log_person_changes((int) $person['id'], (int) $user['id'], $person, $changed);
    audit('person_update', 'person', (int) $person['id'], ['source' => 'mcp', 'fields' => array_keys($changed)]);
    $fresh = scoped_person($user, (int) $person['id']);
    return care_public_person_row($fresh ?: $person, $user);
}

/**
 * @param array<string, mixed> $arguments
 * @return array<string, mixed>
 */
function mcp_tool_change_role(array $user, array $arguments): array
{
    if (!can_action($user, 'edit_people') || !can_manage_users($user)) {
        throw new RuntimeException('Недостаточно прав для смены ролей');
    }
    $target = fetch_one('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) ($arguments['user_id'] ?? 0)]);
    if (!$target || !can_edit_user_record($user, $target)) {
        throw new RuntimeException('Пользователь недоступен');
    }
    $currentRole = (string) $target['role'];
    $role = (string) ($arguments['role'] ?? '');
    if (!can_transition_role((string) $user['role'], $currentRole, $role)) {
        throw new RuntimeException('Эту роль нельзя назначить с текущей (матрица переходов)');
    }

    $groupId = array_key_exists('group_id', $arguments)
        ? ($arguments['group_id'] !== null && $arguments['group_id'] !== '' ? (int) $arguments['group_id'] : null)
        : ($target['group_id'] !== null ? (int) $target['group_id'] : null);
    if ($groupId !== null) {
        $group = scoped_group($user, $groupId);
        $groupId = $group ? (int) $group['id'] : null;
    }
    if (in_array($role, ['leader', 'assistant'], true) && !$groupId) {
        throw new InvalidArgumentException('Для роли leader/assistant укажите group_id');
    }

    db()->prepare('UPDATE users SET role = ?, group_id = ? WHERE id = ?')
        ->execute([$role, $groupId, (int) $target['id']]);
    $person = fetch_one('SELECT id FROM people WHERE user_id = ? LIMIT 1', [(int) $target['id']]);
    if ($person && $groupId) {
        person_add_group_membership((int) $person['id'], (int) $groupId, true);
    }
    if ($role === 'leader' && $groupId && $person) {
        assign_home_group_leader((int) $groupId, (int) $person['id']);
    }
    if ($role !== $currentRole) {
        notify_role_change($target, $role);
    }
    audit('user_access_update', 'user', (int) $target['id'], ['role' => $role, 'group_id' => $groupId, 'source' => 'mcp']);

    return [
        'user_id' => (int) $target['id'],
        'from_role' => $currentRole,
        'to_role' => $role,
        'group_id' => $groupId,
    ];
}

/**
 * @param array<string, mixed> $arguments
 * @return array<string, mixed>
 */
function mcp_tool_add_contact(array $user, array $arguments): array
{
    if (!can_edit_group_data($user)) {
        throw new RuntimeException('Недостаточно прав для записи контакта');
    }
    $person = scoped_person($user, (int) ($arguments['person_id'] ?? 0));
    if (!$person) {
        throw new InvalidArgumentException('Карточка не найдена или недоступна');
    }
    $type = trim((string) ($arguments['type'] ?? ''));
    $summary = trim((string) ($arguments['summary'] ?? ''));
    if ($type === '' || $summary === '') {
        throw new InvalidArgumentException('type и summary обязательны');
    }
    $next = !empty($arguments['next_contact_at']) ? (string) $arguments['next_contact_at'] : null;
    if ($next !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $next)) {
        throw new InvalidArgumentException('next_contact_at: YYYY-MM-DD');
    }
    db()->prepare(
        'INSERT INTO contact_logs (person_id, user_id, contact_date, type, summary, absence_reason, next_contact_at)
         VALUES (?, ?, CURDATE(), ?, ?, ?, ?)'
    )->execute([
        (int) $person['id'],
        (int) $user['id'],
        $type,
        $summary,
        trim((string) ($arguments['absence_reason'] ?? '')) ?: null,
        $next,
    ]);
    $id = (int) db()->lastInsertId();
    audit('contact_add', 'person', (int) $person['id'], ['source' => 'mcp', 'contact_id' => $id]);
    return ['id' => $id, 'person_id' => (int) $person['id']];
}

/**
 * @param array<string, mixed> $arguments
 * @return array<string, mixed>
 */
function mcp_tool_list_assignable_roles(array $user, array $arguments): array
{
    if (!can_manage_users($user)) {
        throw new RuntimeException('Недостаточно прав');
    }
    $target = fetch_one('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) ($arguments['user_id'] ?? 0)]);
    if (!$target || !can_edit_user_record($user, $target)) {
        throw new RuntimeException('Пользователь недоступен');
    }
    $current = (string) $target['role'];
    $roles = [];
    foreach (assignable_roles($user, $current) as $role) {
        $roles[] = [
            'role' => $role,
            'label' => role_label($role),
            'allowed' => can_transition_role((string) $user['role'], $current, $role),
        ];
    }
    return [
        'user_id' => (int) $target['id'],
        'current_role' => $current,
        'roles' => $roles,
    ];
}
