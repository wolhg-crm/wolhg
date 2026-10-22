<?php

declare(strict_types=1);

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

require_once __DIR__ . '/../../src/SystemGuide.php';

/**
 * @return array{role:string,church_id:int,group_id:int,id:int}
 */
function system_guide_test_user(string $role): array
{
    return [
        'id' => 1,
        'role' => $role,
        'church_id' => 1,
        'group_id' => 4,
        'name' => 'Тест',
    ];
}

/**
 * @param list<string> $ids
 */
function system_guide_test_has(array $ids, string $id): bool
{
    return in_array($id, $ids, true);
}

return [
    'role labels stay aligned with bootstrap' => function (TestCase $test): void {
        $bootstrap = (string) file_get_contents(__DIR__ . '/../../src/bootstrap.php');
        $labels = [
            'member' => 'Участник',
            'assistant' => 'Помощник лидера',
            'leader' => 'Лидер домашней группы',
            'district_leader' => 'Окружной лидер',
            'pastor' => 'Пастор',
            'admin' => 'Администратор',
        ];
        foreach ($labels as $role => $label) {
            $test->assertSame($label, system_guide_role_label($role));
            $test->assertStringContains("'$role' => '$label'", $bootstrap);
        }
    },

    'catalog keeps unique article ids and required chapters' => function (TestCase $test): void {
        $ids = system_guide_all_article_ids();

        $test->assertSame($ids, array_values(array_unique($ids)));
        $test->assertTrue(count($ids) >= 20);
        $test->assertTrue(system_guide_test_has($ids, 'process-care-radar'));
        $test->assertTrue(system_guide_test_has($ids, 'process-leader-tasks'));
        $test->assertTrue(system_guide_test_has($ids, 'ui-elements'));
        $test->assertTrue(system_guide_test_has($ids, 'settings-permissions'));
        $test->assertSame('Как пользоваться системой', system_guide_data()['title']);
    },

    'member never receives minister-only articles' => function (TestCase $test): void {
        $test->assertSame([], system_guide_article_ids(system_guide_test_user('member')));
        $test->assertSame([], system_guide_visible_tree(system_guide_test_user('member')));
    },

    'guide visible nav never lists miracles' => function (TestCase $test): void {
        require_once __DIR__ . '/../../src/AppNav.php';

        $memberLabels = array_column(system_guide_visible_nav(system_guide_test_user('member')), 'label');
        $leaderLabels = array_column(system_guide_visible_nav(system_guide_test_user('leader')), 'label');

        $test->assertSame(['Главная'], $memberLabels);
        $test->assertTrue(in_array('Сопровождение', $leaderLabels, true));
        $test->assertFalse(in_array('Чудеса', $memberLabels, true));
        $test->assertFalse(in_array('Чудеса', $leaderLabels, true));

        $guide = (string) file_get_contents(__DIR__ . '/../../src/SystemGuide.php');
        $test->assertStringNotContains("['href' => '/miracles', 'label' => 'Чудеса']", $guide);
    },

    'guide miracles article points ministers to care hub entry' => function (TestCase $test): void {
        $html = '';
        foreach (system_guide_data()['chapters'] as $chapter) {
            foreach ($chapter['articles'] as $article) {
                if (($article['id'] ?? '') === 'process-miracles') {
                    foreach ($article['blocks'] as $block) {
                        if (($block['type'] ?? '') === 'p') {
                            $html .= (string) ($block['text'] ?? '');
                        }
                    }
                }
            }
        }

        $test->assertStringContains('Сопровождение → Чудеса', $html);
        $test->assertStringContains('отдельного пункта в верхнем меню нет', $html);
    },

    'assistant sees care and meetings but not people hub or structure' => function (TestCase $test): void {
        $ids = system_guide_article_ids(system_guide_test_user('assistant'));

        $test->assertTrue(system_guide_test_has($ids, 'start-login'));
        $test->assertTrue(system_guide_test_has($ids, 'start-nav'));
        $test->assertTrue(system_guide_test_has($ids, 'process-care-radar'));
        $test->assertTrue(system_guide_test_has($ids, 'process-leader-tasks'));
        $test->assertTrue(system_guide_test_has($ids, 'process-meeting'));
        $test->assertTrue(system_guide_test_has($ids, 'process-prayers'));
        $test->assertTrue(system_guide_test_has($ids, 'groups-people-add'));
        $test->assertTrue(system_guide_test_has($ids, 'ui-elements'));
        $test->assertFalse(system_guide_test_has($ids, 'start-pwa'));
        $test->assertFalse(system_guide_test_has($ids, 'groups-create'));
        $test->assertFalse(system_guide_test_has($ids, 'groups-settings'));
        $test->assertFalse(system_guide_test_has($ids, 'groups-assign-leader'));
        $test->assertFalse(system_guide_test_has($ids, 'people-hub'));
        $test->assertFalse(system_guide_test_has($ids, 'analytics-regional'));
        $test->assertFalse(system_guide_test_has($ids, 'knowledge-edit'));
        $test->assertFalse(system_guide_test_has($ids, 'settings-security'));
        $test->assertFalse(system_guide_test_has($ids, 'settings-admin'));
    },

    'leader gains people hub pwa and group settings but not church create' => function (TestCase $test): void {
        $ids = system_guide_article_ids(system_guide_test_user('leader'));

        $test->assertTrue(system_guide_test_has($ids, 'start-pwa'));
        $test->assertTrue(system_guide_test_has($ids, 'people-hub'));
        $test->assertTrue(system_guide_test_has($ids, 'people-invites'));
        $test->assertTrue(system_guide_test_has($ids, 'groups-settings'));
        $test->assertFalse(system_guide_test_has($ids, 'groups-create'));
        $test->assertFalse(system_guide_test_has($ids, 'groups-assign-leader'));
        $test->assertFalse(system_guide_test_has($ids, 'people-role-approve'));
        $test->assertFalse(system_guide_test_has($ids, 'people-participant-requests'));
        $test->assertFalse(system_guide_test_has($ids, 'analytics-church'));
        $test->assertFalse(system_guide_test_has($ids, 'settings-permissions'));
        $test->assertFalse(system_guide_test_has($ids, 'knowledge-edit'));
    },

    'regional leader sees group create analytics and training edit' => function (TestCase $test): void {
        $ids = system_guide_article_ids(system_guide_test_user('district_leader'));

        $test->assertTrue(system_guide_test_has($ids, 'groups-create'));
        $test->assertTrue(system_guide_test_has($ids, 'groups-assign-leader'));
        $test->assertTrue(system_guide_test_has($ids, 'people-participant-requests'));
        $test->assertTrue(system_guide_test_has($ids, 'analytics-regional'));
        $test->assertTrue(system_guide_test_has($ids, 'analytics-church'));
        $test->assertTrue(system_guide_test_has($ids, 'knowledge-edit'));
        $test->assertFalse(system_guide_test_has($ids, 'settings-security'));
        $test->assertFalse(system_guide_test_has($ids, 'settings-permissions'));
        $test->assertFalse(system_guide_test_has($ids, 'people-role-approve'));
        $test->assertFalse(system_guide_test_has($ids, 'settings-import'));
    },

    'pastor sees privacy and profile 2fa but not vapid matrix' => function (TestCase $test): void {
        $ids = system_guide_article_ids(system_guide_test_user('pastor'));

        $test->assertTrue(system_guide_test_has($ids, 'settings-hub'));
        $test->assertTrue(system_guide_test_has($ids, 'settings-privacy'));
        $test->assertTrue(system_guide_test_has($ids, 'start-profile'));
        $test->assertFalse(system_guide_test_has($ids, 'settings-security'));
        $test->assertFalse(system_guide_test_has($ids, 'settings-vapid'));
        $test->assertFalse(system_guide_test_has($ids, 'settings-backup'));
        $test->assertTrue(system_guide_test_has($ids, 'people-role-approve'));
        $test->assertFalse(system_guide_test_has($ids, 'settings-permissions'));
        $test->assertFalse(system_guide_test_has($ids, 'settings-church'));
        $test->assertFalse(system_guide_test_has($ids, 'settings-admin'));
        $test->assertFalse(system_guide_test_has($ids, 'settings-import'));
        $test->assertFalse(system_guide_test_has($ids, 'settings-role-preview'));

        ob_start();
        system_guide_render_page(system_guide_test_user('pastor'));
        $html = (string) ob_get_clean();
        $test->assertStringContains('Пастор: до администратора с повышением на одну ступень', $html);
        $test->assertStringContains('Безопасность / 2FA', $html);
    },

    'admin sees church settings but not platform admin screen' => function (TestCase $test): void {
        $admin = system_guide_test_user('admin');
        $ids = system_guide_article_ids($admin);
        $all = system_guide_all_article_ids();

        $test->assertTrue(system_guide_test_has($ids, 'settings-church'));
        $test->assertTrue(system_guide_test_has($ids, 'settings-role-preview'));
        $test->assertTrue(system_guide_test_has($ids, 'settings-import'));
        $test->assertFalse(system_guide_test_has($ids, 'settings-permissions'));
        $test->assertFalse(system_guide_test_has($ids, 'settings-vapid'));
        $test->assertFalse(system_guide_test_has($ids, 'settings-admin'));
        $hidden = array_values(array_diff($all, $ids));
        sort($hidden);
        $expectedHidden = ['settings-admin', 'settings-permissions', 'settings-vapid'];
        if (!system_guide_flag($admin, 'mcp_enabled')) {
            $expectedHidden[] = 'settings-mcp';
            sort($expectedHidden);
        }
        $test->assertSame($expectedHidden, $hidden);
    },

    'super_admin sees platform admin article including church delete guidance' => function (TestCase $test): void {
        $super = system_guide_test_user('super_admin');
        $ids = system_guide_article_ids($super);
        $all = system_guide_all_article_ids();
        if (!system_guide_flag($super, 'mcp_enabled')) {
            $all = array_values(array_filter($all, static fn(string $id): bool => $id !== 'settings-mcp'));
        }

        sort($ids);
        sort($all);
        $test->assertSame($all, $ids);
        $test->assertTrue(system_guide_test_has($ids, 'settings-admin'));

        ob_start();
        system_guide_render_page($super);
        $html = (string) ob_get_clean();
        $test->assertStringContains('Администрирование платформы', $html);
        $test->assertStringContains('мягкое удаление церкви', $html);
        $test->assertStringContains('id="article-settings-permissions"', $html);
        $test->assertStringContains('id="article-settings-vapid"', $html);
        if (!system_guide_flag($super, 'mcp_enabled')) {
            $test->assertStringNotContains('id="article-settings-mcp"', $html);
        }
    },

    'settings-mcp article visibility tracks mcp_enabled flag' => function (TestCase $test): void {
        $leader = system_guide_test_user('leader');
        $super = system_guide_test_user('super_admin');
        $mcpOn = system_guide_flag($leader, 'mcp_enabled');
        $leaderHas = system_guide_test_has(system_guide_article_ids($leader), 'settings-mcp');
        $superHas = system_guide_test_has(system_guide_article_ids($super), 'settings-mcp');

        $test->assertSame($mcpOn, $leaderHas);
        $test->assertSame($mcpOn, $superHas);
    },

    'care legend matches domain status labels and thresholds' => function (TestCase $test): void {
        $levels = system_guide_care_levels();
        $byLevel = [];
        foreach ($levels as $row) {
            $byLevel[$row['level']] = $row['label'];
        }

        $test->assertSame('Был на этой неделе', $byLevel['green']);
        $test->assertSame('Не был 2 недели', $byLevel['yellow']);
        $test->assertSame('Не был месяц', $byLevel['orange']);
        $test->assertSame('Не был больше 6 недель', $byLevel['red']);

        [$green] = ChurchCare\care_status('2026-07-08', '2026-07-10', '2026-07-15');
        [$yellow] = ChurchCare\care_status('2026-07-01', '2026-07-10', '2026-07-15');
        [$orange] = ChurchCare\care_status('2026-06-10', '2026-07-10', '2026-07-15');
        [$red] = ChurchCare\care_status('2026-05-30', '2026-07-10', '2026-07-15');
        $test->assertSame('green', $green);
        $test->assertSame('yellow', $yellow);
        $test->assertSame('orange', $orange);
        $test->assertSame('red', $red);
    },

    'newcomer steps match domain follow-up titles' => function (TestCase $test): void {
        $tasks = ChurchCare\newcomer_followup_tasks(5, 1, 2, 'Ольга Соколова', '2026-07-01', '2026-08-01');
        $titles = array_column($tasks, 'title');
        $steps = system_guide_newcomer_steps();

        $test->assertSame(3, count($steps));
        $test->assertSame(3, count($titles));
        $test->assertSame('Позвонить новичку Ольга Соколова', $titles[0]);
        $test->assertSame('Пригласить новичка снова: Ольга Соколова', $titles[1]);
        $test->assertSame('Предложить встречу новичку: Ольга Соколова', $titles[2]);
        $test->assertSame(2, $steps[0]['days']);
        $test->assertSame(7, $steps[1]['days']);
        $test->assertSame(30, $steps[2]['days']);
        $test->assertTrue(str_starts_with($titles[0], $steps[0]['title']));
        $test->assertTrue(str_starts_with($titles[1], $steps[1]['title']));
        $test->assertTrue(str_starts_with($titles[2], $steps[2]['title']));
    },

    'leader html includes care radar and tasks but not permission matrix' => function (TestCase $test): void {
        $user = system_guide_test_user('leader');
        ob_start();
        system_guide_render_page($user);
        $html = (string) ob_get_clean();

        $test->assertStringContains('Задачи лидера', $html);
        $test->assertStringContains('Радар заботы и цветные точки', $html);
        $test->assertStringContains('Был на этой неделе', $html);
        $test->assertStringContains('Не был 2 недели', $html);
        $test->assertStringContains('Позвонить новичку', $html);
        $test->assertStringContains('выпадает из общения', $html);
        $test->assertStringContains('id="article-process-care-radar"', $html);
        $test->assertStringContains('class="dot green"', $html);
        $test->assertStringNotContains('Матрица прав', $html);
        $test->assertStringNotContains('Администрирование структуры', $html);
        $test->assertStringNotContains('Просмотр роли', $html);
        $test->assertStringContains('/groups', $html);
        $test->assertStringContains('/users', $html);
        $test->assertStringNotContains('/settings', $html);
    },

    'assistant html omits people menu and pwa install' => function (TestCase $test): void {
        ob_start();
        system_guide_render_page(system_guide_test_user('assistant'));
        $html = (string) ob_get_clean();

        $test->assertStringContains('Как устроено меню', $html);
        $test->assertStringContains('/groups', $html);
        $test->assertStringNotContains('— Люди', $html);
        $test->assertStringNotContains('Установка на домашний экран', $html);
        $test->assertStringContains('Цветные кружки рядом с именами', $html);
    },

    'admin html includes role preview and import' => function (TestCase $test): void {
        ob_start();
        system_guide_render_page(system_guide_test_user('admin'));
        $html = (string) ob_get_clean();

        $test->assertStringContains('Просмотр роли', $html);
        $test->assertStringContains('Импорт людей из CSV', $html);
        $test->assertStringNotContains('id="article-settings-permissions"', $html);
        $test->assertStringNotContains('id="article-settings-vapid"', $html);
        $test->assertStringContains('/settings', $html);
        $test->assertStringContains('id="article-settings-role-preview"', $html);
    },

    'rendered copy escapes html from user-controlled role fallback' => function (TestCase $test): void {
        $block = ['type' => 'p', 'text' => '<script>alert(1)</script>'];
        $html = system_guide_render_block($block, system_guide_test_user('leader'));

        $test->assertStringContains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $test->assertStringNotContains('<script>alert(1)</script>', $html);
    },

    'promo card is scoped to the current role article count' => function (TestCase $test): void {
        $leaderPromo = system_guide_promo_html(system_guide_test_user('leader'));
        $adminPromo = system_guide_promo_html(system_guide_test_user('admin'));
        $superPromo = system_guide_promo_html(system_guide_test_user('super_admin'));
        $leaderCount = count(system_guide_article_ids(system_guide_test_user('leader')));
        $adminCount = count(system_guide_article_ids(system_guide_test_user('admin')));
        $superCount = count(system_guide_article_ids(system_guide_test_user('super_admin')));

        $test->assertTrue($adminCount > $leaderCount);
        $test->assertTrue($superCount > $adminCount);
        $test->assertStringContains((string) $leaderCount, $leaderPromo);
        $test->assertStringContains((string) $adminCount, $adminPromo);
        $test->assertStringContains((string) $superCount, $superPromo);
        $test->assertStringContains('/knowledge/system-guide', $leaderPromo);
        $test->assertStringContains('Лидер домашней группы', $leaderPromo);
        $test->assertStringContains('Администратор', $adminPromo);
        $test->assertStringContains('Суперадминистратор', $superPromo);
        $test->assertStringContains('class="dot red"', $leaderPromo);
    },

    'permission-gated articles follow default_permission matrix when Features is loaded' => function (TestCase $test): void {
        require_once __DIR__ . '/../../src/Features.php';

        $pastor = system_guide_test_user('pastor');
        $test->assertTrue(default_permission('pastor', 'manage_security'));
        $test->assertFalse(default_permission('pastor', 'manage_permissions'));
        $test->assertTrue(system_guide_can_action($pastor, 'manage_security'));
        $test->assertFalse(system_guide_can_action($pastor, 'manage_permissions'));
        $test->assertTrue(system_guide_test_has(system_guide_article_ids($pastor), 'settings-privacy'));
        $test->assertTrue(system_guide_test_has(system_guide_article_ids($pastor), 'start-profile'));
        $test->assertFalse(system_guide_test_has(system_guide_article_ids($pastor), 'settings-security'));
        $test->assertFalse(system_guide_test_has(system_guide_article_ids($pastor), 'settings-permissions'));
        $test->assertFalse(system_guide_test_has(system_guide_article_ids($pastor), 'settings-vapid'));
        $test->assertFalse(in_array('manage_permissions', array_keys(permission_actions()), true));
    },

    'role matrix and vapid articles are visible only to super admin' => function (TestCase $test): void {
        $leader = system_guide_test_user('leader');
        $admin = system_guide_test_user('admin');
        $super = system_guide_test_user('super_admin');

        $test->assertFalse(system_guide_test_has(system_guide_article_ids($leader), 'settings-permissions'));
        $test->assertFalse(system_guide_test_has(system_guide_article_ids($admin), 'settings-permissions'));
        $test->assertFalse(system_guide_test_has(system_guide_article_ids($admin), 'settings-vapid'));
        $test->assertTrue(system_guide_test_has(system_guide_article_ids($super), 'settings-permissions'));
        $test->assertTrue(system_guide_test_has(system_guide_article_ids($super), 'settings-vapid'));
        $test->assertTrue(system_guide_user_matches(
            $leader,
            ['actions' => ['manage_prayers']]
        ));
        $test->assertFalse(system_guide_user_matches(
            $leader,
            ['flags' => ['platform_admin']]
        ));
    },
];
