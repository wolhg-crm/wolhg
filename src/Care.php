<?php

declare(strict_types=1);

/**
 * Care radar, group health stats, and meeting attendance helpers.
 * Shared by HTML pages and the MCP tool surface.
 */

/**
 * People visible to $user with care_status enrichment.
 *
 * @return list<array<string, mixed>>
 */
function care_people(array $user, ?int $groupId = null, string $sort = 'risk'): array
{
    [$personSql, $params] = accessible_person_sql($user);
    $extra = '';
    if ($groupId) {
        if (person_group_memberships_ready()) {
            $extra = ' AND (p.group_id = ? OR EXISTS (SELECT 1 FROM person_group_memberships pgm WHERE pgm.person_id = p.id AND pgm.group_id = ?))';
            $params[] = $groupId;
            $params[] = $groupId;
        } else {
            $extra = ' AND p.group_id = ?';
            $params[] = $groupId;
        }
    }
    $meetingJoin = person_group_memberships_ready()
        ? "LEFT JOIN meetings m ON (
                m.group_id = p.group_id
                OR EXISTS (
                    SELECT 1 FROM person_group_memberships pgm_m
                    WHERE pgm_m.person_id = p.id AND pgm_m.group_id = m.group_id
                )
           )"
        : 'LEFT JOIN meetings m ON m.group_id = p.group_id';
    $rows = fetch_all("SELECT p.*, g.name AS group_name,
            MAX(CASE WHEN a.status IN ('present','new') THEN m.meeting_date END) AS last_attendance,
            MAX(cl.contact_date) AS last_contact,
            SUM(CASE WHEN a.status IN ('present','new') THEN 1 ELSE 0 END) AS visits
        FROM people p
        JOIN home_groups g ON g.id = p.group_id
        $meetingJoin
        LEFT JOIN attendance a ON a.meeting_id = m.id AND a.person_id = p.id
        LEFT JOIN contact_logs cl ON cl.person_id = p.id
        WHERE $personSql AND g.is_active = 1 $extra
        GROUP BY p.id
        ORDER BY visits DESC, last_attendance DESC, p.full_name", $params);

    foreach ($rows as &$row) {
        $firstVisit = isset($row['first_visit_date']) ? (string) $row['first_visit_date'] : null;
        $lastAttendance = ChurchCare\effective_last_attendance(
            isset($row['last_attendance']) ? (string) $row['last_attendance'] : null,
            $firstVisit
        );
        $lastContact = ChurchCare\effective_last_contact(
            isset($row['last_contact']) ? (string) $row['last_contact'] : null,
            $firstVisit
        );
        $row['last_attendance'] = $lastAttendance;
        $row['last_contact'] = $lastContact;
        [$level, $label, $attendanceDays, $contactDays] = care_status($lastAttendance, $lastContact);
        $row['care_level'] = $level;
        $row['care_label'] = $label;
        $row['attendance_days'] = $attendanceDays;
        $row['contact_days'] = $contactDays;
    }
    unset($row);

    if ($sort === 'risk') {
        usort($rows, static function ($a, $b) {
            $rank = ['red' => 0, 'orange' => 1, 'yellow' => 2, 'green' => 3];
            return ($rank[$a['care_level']] <=> $rank[$b['care_level']]) ?: strcmp((string) $a['full_name'], (string) $b['full_name']);
        });
    } elseif ($sort === 'name') {
        usort($rows, static function ($a, $b) {
            return strcmp((string) $a['full_name'], (string) $b['full_name']);
        });
    }

    return $rows;
}

/**
 * People who have not attended recently (care_level !== green).
 *
 * @return list<array<string, mixed>>
 */
function care_inactive_people(array $user, ?int $groupId = null, ?string $minLevel = null): array
{
    $rank = ['yellow' => 1, 'orange' => 2, 'red' => 3];
    $minRank = $rank[$minLevel ?? 'yellow'] ?? 1;
    $out = [];
    foreach (care_people($user, $groupId, 'risk') as $person) {
        $level = (string) ($person['care_level'] ?? 'green');
        if ($level === 'green') {
            continue;
        }
        if (($rank[$level] ?? 0) < $minRank) {
            continue;
        }
        $out[] = $person;
    }
    return $out;
}

/**
 * Home-group health rows for accessible groups (90-day window).
 *
 * @return list<array<string, mixed>>
 */
function group_health_stats(array $user, ?int $groupId = null): array
{
    [$groupSql, $params] = accessible_group_sql($user, 'g');
    $extra = '';
    if ($groupId !== null) {
        $extra = ' AND g.id = ?';
        $params[] = $groupId;
    }
    $rows = fetch_all("SELECT g.id, g.name,
            (SELECT COUNT(*) FROM people p WHERE p.group_id = g.id AND p.deleted_at IS NULL) AS people_count,
            (SELECT COUNT(*) FROM people p WHERE p.group_id = g.id AND p.deleted_at IS NULL AND p.first_visit_date >= CURDATE() - INTERVAL 30 DAY) AS newcomers,
            (SELECT COUNT(*) FROM people p WHERE p.group_id = g.id AND p.deleted_at IS NULL AND p.ministry IS NOT NULL AND p.ministry <> '') AS serving,
            (SELECT COUNT(*) FROM people p WHERE p.group_id = g.id AND p.deleted_at IS NULL AND p.is_potential_leader = 1) AS future_leaders,
            (SELECT COUNT(*) FROM prayer_requests pr WHERE pr.group_id = g.id) AS prayer_count,
            (SELECT COUNT(*) FROM prayer_requests pr WHERE pr.group_id = g.id AND pr.status IN ('testimony','closed')) AS closed_prayers,
            (SELECT COUNT(*) FROM meetings m WHERE m.group_id = g.id AND m.meeting_date >= CURDATE() - INTERVAL 90 DAY) AS meetings_count,
            (SELECT COUNT(*) FROM attendance a JOIN meetings m ON m.id = a.meeting_id WHERE m.group_id = g.id AND m.meeting_date >= CURDATE() - INTERVAL 90 DAY AND a.status IN ('present','new')) AS attendance_marks
        FROM home_groups g
        WHERE $groupSql AND g.is_active = 1 $extra
        ORDER BY g.name", $params);

    foreach ($rows as &$row) {
        $health = ChurchCare\health_metrics(
            (int) $row['people_count'],
            (int) $row['meetings_count'],
            (int) $row['attendance_marks'],
            (int) $row['newcomers'],
            (int) $row['serving'],
            (int) $row['future_leaders']
        );
        $row['average_attendance'] = $health['average_attendance'];
        $row['health_score'] = $health['score'];
    }
    unset($row);

    return $rows;
}

/**
 * Church-level analytics for roles with view_church_analytics.
 *
 * @return array<string, int|string>
 */
function church_analytics_stats(array $user): array
{
    if (!can_action($user, 'view_church_analytics')) {
        throw new RuntimeException('Недостаточно прав для церковной аналитики');
    }
    [$groupSql, $params] = accessible_group_sql($user, 'g');
    $stats = fetch_one(
        "SELECT
            COUNT(DISTINCT CASE WHEN g.is_active = 1 THEN g.id END) AS active_groups,
            COUNT(DISTINCT CASE WHEN g.opened_at >= CURDATE() - INTERVAL 1 YEAR THEN g.id END) AS opened_year,
            COUNT(DISTINCT CASE WHEN g.closed_at >= CURDATE() - INTERVAL 1 YEAR THEN g.id END) AS closed_year,
            COUNT(DISTINCT CASE WHEN p.baptism_date >= CURDATE() - INTERVAL 1 YEAR THEN p.id END) AS baptisms_year,
            COUNT(DISTINCT CASE WHEN p.first_visit_date >= CURDATE() - INTERVAL 90 DAY THEN p.id END) AS newcomers_quarter
         FROM home_groups g
         LEFT JOIN people p ON p.group_id = g.id AND p.deleted_at IS NULL
         WHERE $groupSql",
        $params
    ) ?: [];

    return [
        'active_groups' => (int) ($stats['active_groups'] ?? 0),
        'opened_year' => (int) ($stats['opened_year'] ?? 0),
        'closed_year' => (int) ($stats['closed_year'] ?? 0),
        'baptisms_year' => (int) ($stats['baptisms_year'] ?? 0),
        'newcomers_quarter' => (int) ($stats['newcomers_quarter'] ?? 0),
    ];
}

/**
 * Create a meeting in an accessible group.
 *
 * @return array{id: int, group_id: int, meeting_date: string, topic: string}
 */
function meeting_create_for_user(array $user, int $groupId, string $meetingDate, string $topic = ''): array
{
    if (!can_edit_group_data($user)) {
        throw new RuntimeException('Недостаточно прав для создания встречи');
    }
    $group = scoped_group($user, $groupId);
    if (!$group) {
        throw new InvalidArgumentException('Группа не найдена или недоступна');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $meetingDate)) {
        throw new InvalidArgumentException('Некорректная дата встречи (ожидается YYYY-MM-DD)');
    }
    $topic = trim($topic);
    $stmt = db()->prepare('INSERT INTO meetings (group_id, meeting_date, topic, created_by) VALUES (?, ?, ?, ?)');
    $stmt->execute([(int) $group['id'], $meetingDate, $topic !== '' ? $topic : null, (int) $user['id']]);
    $id = (int) db()->lastInsertId();
    audit('meeting_create', 'meeting', $id);

    return [
        'id' => $id,
        'group_id' => (int) $group['id'],
        'meeting_date' => $meetingDate,
        'topic' => $topic,
    ];
}

/**
 * Replace attendance for a meeting. presentPersonIds are marked present; others in the group absent.
 *
 * @param list<int> $presentPersonIds
 * @return array{meeting_id: int, present: int, absent: int}
 */
function meeting_save_attendance_for_user(
    array $user,
    int $meetingId,
    array $presentPersonIds,
    ?string $atmosphere = null,
    bool $hadConflicts = false,
    bool $pastorHelpNeeded = false,
    string $notes = ''
): array {
    if (!can_edit_group_data($user)) {
        throw new RuntimeException('Недостаточно прав для отметки посещаемости');
    }
    $meeting = scoped_meeting($user, $meetingId);
    if (!$meeting) {
        throw new InvalidArgumentException('Встреча не найдена или недоступна');
    }
    $groupId = (int) $meeting['group_id'];
    $peopleSql = 'SELECT DISTINCT p.id FROM people p WHERE p.deleted_at IS NULL AND ' . people_in_group_sql('p');
    $peopleParams = person_group_memberships_ready() ? [$groupId, $groupId] : [$groupId];
    $people = fetch_all($peopleSql, $peopleParams);
    $present = array_values(array_unique(array_map('intval', $presentPersonIds)));

    db()->beginTransaction();
    try {
        db()->prepare('DELETE FROM attendance WHERE meeting_id = ?')->execute([$meetingId]);
        $insert = db()->prepare('INSERT INTO attendance (meeting_id, person_id, status) VALUES (?, ?, ?)');
        $presentCount = 0;
        $absentCount = 0;
        foreach ($people as $person) {
            $isPresent = in_array((int) $person['id'], $present, true);
            $insert->execute([$meetingId, (int) $person['id'], $isPresent ? 'present' : 'absent']);
            if ($isPresent) {
                $presentCount++;
            } else {
                $absentCount++;
            }
        }
        db()->prepare('UPDATE meetings SET atmosphere = ?, had_conflicts = ?, pastor_help_needed = ?, notes = ? WHERE id = ?')
            ->execute([
                $atmosphere !== null && $atmosphere !== '' ? $atmosphere : null,
                $hadConflicts ? 1 : 0,
                $pastorHelpNeeded ? 1 : 0,
                trim($notes) !== '' ? trim($notes) : null,
                $meetingId,
            ]);
        if ($pastorHelpNeeded) {
            queue_regional_help_notice(
                (int) $meeting['group_id'],
                'Группа просит помощи пастора',
                'Встреча отмечена как требующая помощи пастора. Персональные детали доступны только в CRM.',
                '/meeting?id=' . $meetingId
            );
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    audit('meeting_save', 'meeting', $meetingId, ['present' => $presentCount, 'absent' => $absentCount]);

    return [
        'meeting_id' => $meetingId,
        'present' => $presentCount,
        'absent' => $absentCount,
    ];
}

/**
 * Strip encrypted/sensitive fields before exposing a person row to MCP/AI clients.
 *
 * @param array<string, mixed> $person
 * @return array<string, mixed>
 */
function care_strip_sensitive_person_fields(array $person): array
{
    $allowed = [
        'id', 'full_name', 'group_id', 'group_name', 'phone', 'email', 'telegram',
        'birth_date', 'address', 'marital_status', 'first_visit_date', 'repentance_date',
        'baptism_date', 'membership_date', 'ministry', 'training_stage', 'is_leader',
        'is_potential_leader', 'last_attendance', 'last_contact', 'visits',
        'care_level', 'care_label', 'attendance_days', 'contact_days', 'user_id',
        'notes',
    ];
    $out = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $person)) {
            $out[$key] = $person[$key];
        }
    }
    return $out;
}

/**
 * @param array<string, mixed> $person
 * @return array<string, mixed>
 */
function care_public_person_row(array $person, array $user): array
{
    $out = care_strip_sensitive_person_fields($person);

    if (function_exists('can_view_person_field')) {
        if (!can_view_person_field($user, 'notes')) {
            unset($out['notes']);
        }
        if (!can_view_person_field($user, 'phone')) {
            unset($out['phone']);
        }
        if (!can_view_person_field($user, 'telegram')) {
            unset($out['telegram']);
        }
        if (!can_view_person_field($user, 'email')) {
            unset($out['email']);
        }
        if (!can_view_person_field($user, 'address')) {
            unset($out['address']);
        }
    }

    return $out;
}
