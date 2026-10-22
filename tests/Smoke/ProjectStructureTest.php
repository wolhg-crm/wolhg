<?php

declare(strict_types=1);

return [
    'project contains expected runtime entrypoints' => function (TestCase $test): void {
        foreach (['Dockerfile', 'docker-compose.yml', 'index.php', '.htaccess', 'public/.htaccess', 'public/index.php', 'src/PwaManifest.php', 'public/service-worker.js', 'bin/send-notifications.php'] as $file) {
            $test->assertFileExists(__DIR__ . '/../../' . $file);
        }
    },
    'docker entrypoint delegates to service commands' => function (TestCase $test): void {
        $entrypoint = file_get_contents(__DIR__ . '/../../docker-entrypoint.sh');
        $compose = file_get_contents(__DIR__ . '/../../docker-compose.yml');
        $apache = file_get_contents(__DIR__ . '/../../apache.conf');

        $test->assertStringContains('exec "$@"', $entrypoint);
        $test->assertStringContains('php bin/send-notifications.php', $compose);
        $test->assertStringContains('ServerName localhost', $apache);
    },
    'shared hosting entrypoints protect non public project files' => function (TestCase $test): void {
        $rootIndex = file_get_contents(__DIR__ . '/../../index.php');
        $rootHtaccess = file_get_contents(__DIR__ . '/../../.htaccess');
        $publicHtaccess = file_get_contents(__DIR__ . '/../../public/.htaccess');

        $test->assertStringContains("require __DIR__ . '/public/index.php';", $rootIndex);
        foreach (['config', 'src', 'database', 'bin', 'tests', 'vendor', 'docs', 'scripts'] as $directory) {
            $test->assertStringContains($directory, $rootHtaccess);
        }
        $test->assertStringContains('public/$1', $rootHtaccess);
        $test->assertStringContains('public/index.php', $rootHtaccess);
        $test->assertStringContains('RewriteRule ^ index.php [L]', $publicHtaccess);
    },
    'php platform is pinned to php 8.5 with required extensions' => function (TestCase $test): void {
        $composerPath = __DIR__ . '/../../composer.json';
        $lockPath = __DIR__ . '/../../composer.lock';
        $dockerfilePath = __DIR__ . '/../../Dockerfile';
        $test->assertFileExists($composerPath);
        $test->assertFileExists($lockPath);
        $test->assertFileExists($dockerfilePath);

        $composer = (string) file_get_contents($composerPath);
        $lock = (string) file_get_contents($lockPath);
        $dockerfile = (string) file_get_contents($dockerfilePath);

        $test->assertStringContains('"php": ">=8.5"', $composer);
        foreach (['ext-pdo_mysql', 'ext-mbstring', 'ext-curl', 'ext-openssl', 'ext-json'] as $extension) {
            $test->assertStringContains('"' . $extension . '": "*"', $composer);
        }
        $test->assertStringContains('"php": "8.5.0"', $composer);
        $test->assertStringContains('"php": ">=8.5"', $lock);
        $test->assertStringContains('FROM php:8.5-apache', $dockerfile);
        $test->assertStringContains('docker-php-ext-install curl mbstring pdo_mysql', $dockerfile);
    },
    'application configuration is file based' => function (TestCase $test): void {
        $config = file_get_contents(__DIR__ . '/../../config/app.php');
        $compose = file_get_contents(__DIR__ . '/../../docker-compose.yml');

        $test->assertFileExists(__DIR__ . '/../../config/local.example.php');
        $test->assertStringContains("__DIR__ . '/local.php'", $config);
        $test->assertStringContains("require_once __DIR__ . '/db_host.php';", $config);
        $test->assertStringContains("resolve_db_host((string) (\$config['db']['host']", $config);
        $test->assertStringNotContains('getenv(', $config);
        $test->assertStringNotContains('APP_TIMEZONE', $compose);
        $test->assertStringNotContains('VAPID_PUBLIC_KEY', $compose);
    },
    'schema avoids reserved groups table and uses home_groups' => function (TestCase $test): void {
        $schema = file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');

        $test->assertStringContains('CREATE TABLE home_groups', $schema);
        $test->assertStringNotContains('CREATE TABLE groups', $schema);
    },
    'sql init files declare utf8mb4 before data' => function (TestCase $test): void {
        $schema = file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');
        $seed = file_get_contents(__DIR__ . '/../../database/init/002_seed.sql');
        $bootstrapAdmin = file_get_contents(__DIR__ . '/../../database/init/003_bootstrap_admin.sql');

        $test->assertStringContains('SET NAMES utf8mb4;', substr($schema, 0, 80));
        $test->assertStringContains('SET NAMES utf8mb4;', substr($seed, 0, 80));
        $test->assertStringContains('SET NAMES utf8mb4;', substr($bootstrapAdmin, 0, 80));
    },
    'fresh deployments include a baseline admin account' => function (TestCase $test): void {
        $bootstrapAdmin = file_get_contents(__DIR__ . '/../../database/init/003_bootstrap_admin.sql');

        $test->assertStringContains('admin@example.test', $bootstrapAdmin);
        $test->assertStringContains("'admin'", $bootstrapAdmin);
        $test->assertStringContains('ON DUPLICATE KEY UPDATE', $bootstrapAdmin);
    },
    'webpush persistence is represented in schema' => function (TestCase $test): void {
        $schema = file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');

        $test->assertStringContains('CREATE TABLE web_push_subscriptions', $schema);
        $test->assertStringContains('CREATE TABLE notification_events', $schema);
        $test->assertStringContains('CREATE TABLE role_invitation_requests', $schema);
        $test->assertFileExists(__DIR__ . '/../../database/migrations/016_role_invitation_requests.sql');
        $test->assertFileExists(__DIR__ . '/../../database/migrations/040_role_invite_admin.sql');
        $test->assertStringContains("ENUM('assistant','leader','district_leader','pastor','admin')", $schema);
        $test->assertStringContains("'admin'", file_get_contents(__DIR__ . '/../../database/migrations/040_role_invite_admin.sql'));
    },
    'task lifecycle uniqueness ignores cancelled rows and blocks reopened completed tasks' => function (TestCase $test): void {
        $schema = file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');

        $test->assertStringContains('active_task_key', $schema);
        $test->assertStringContains('UNIQUE KEY uniq_active_task', $schema);
        $test->assertStringNotContains('UNIQUE KEY uniq_open_task', $schema);
        $test->assertFileExists(__DIR__ . '/../../database/migrations/003_fix_task_lifecycle_unique_key.sql');
    },

    'mvp completion tables and routes are present' => function (TestCase $test): void {
        $schema = file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $features = file_get_contents(__DIR__ . '/../../src/Features.php');
        $compose = file_get_contents(__DIR__ . '/../../docker-compose.yml');

        foreach (['role_permissions', 'backup_runs', 'data_deletion_requests', 'person_change_logs', 'spiritual_stages', 'courses', 'meeting_reports', 'import_batches', 'user_api_keys', 'districts', 'district_leaders', 'district_catalog_cache'] as $table) {
            $test->assertStringContains('CREATE TABLE ' . $table, $schema);
        }

        foreach (['/admin', '/permissions', '/privacy', '/calendar', '/services', '/match', '/map', '/prayers', '/miracles', '/newcomers', '/regional', '/districts', '/church-analytics', '/courses', '/import', '/meeting-report'] as $route) {
            $test->assertStringContains("'" . $route . "' =>", $index);
        }
        $test->assertStringNotContains("'/backup' =>", $index);

        $test->assertStringContains('function two_factor_code', $features);
        $test->assertStringContains('function encrypt_sensitive_note', $features);
        $test->assertStringContains('function can_view_person_field', $features);
        $test->assertStringContains('function format_person_change_value', $features);
        $test->assertStringContains('scheduler:', $compose);
        $test->assertStringContains('bin/send-notifications.php', $compose);
    },

    'account profile user and email workflows are represented' => function (TestCase $test): void {
        $schema = file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');
        $features = file_get_contents(__DIR__ . '/../../src/Features.php');
        $compose = file_get_contents(__DIR__ . '/../../docker-compose.yml');

        foreach (['user_notification_settings', 'password_resets', 'account_invitations', 'participant_requests', 'email_notifications'] as $table) {
            $test->assertStringContains('CREATE TABLE ' . $table, $schema);
        }

        foreach (['/forgot-password', '/reset-password', '/account-invite', '/role-invite', '/regchurch', '/join', '/homegroup', '/homegroup/join', '/church-settings', '/profile', '/users', '/user', '/participant-requests', '/role-requests', '/care', '/analytics', '/settings'] as $route) {
            $test->assertStringContains($route, $index);
        }

        $catalog = file_get_contents(__DIR__ . '/../../src/HomeGroupCatalog.php');
        $homeGroup = file_get_contents(__DIR__ . '/../../src/HomeGroup.php');
        $test->assertStringContains('function homegroup_catalog_page', $catalog);
        $test->assertStringContains('function homegroup_join_page', $catalog);
        $test->assertStringContains('data-homegroup-map', $catalog);
        $test->assertStringContains('function parse_home_group_catalog_filters', $homeGroup);
        $test->assertStringContains('short_address VARCHAR(255)', $schema);
        $test->assertStringContains('group_types JSON', $schema);

        $test->assertStringContains("'/user' => user_page", $index);
        $test->assertStringContains('function user_page', $account);
        $test->assertStringContains('function scoped_user', $account);
        $test->assertStringContains('function user_card_href', $account);
        $test->assertStringContains('function person_linked_to_user', $account);
        $test->assertStringContains('function account_display_row', $account);
        $test->assertStringContains('function render_account_hero', $account);
        $test->assertStringContains('function render_account_person_panel', $account);
        $test->assertStringContains('function profile_page', $account);
        $test->assertStringContains('Моя учетная запись', $account);
        $test->assertStringContains('user_card_href($userId, $user)', $account);
        $test->assertStringContains('user_card_href($userId, $user)', $features);
        $test->assertStringContains('user_card_href($userId, $viewer)', $bootstrap);
        $test->assertStringContains('Моя учетная запись', $bootstrap);
        $test->assertStringContains("app_path('/profile')", $bootstrap);
        $test->assertStringContains('class="user-chip"', $bootstrap);

        $test->assertStringContains('function can_create_home_group', $account);
        $test->assertStringContains('function can_delete_home_group', $account);
        $test->assertStringContains('/group-delete', $account);
        $test->assertStringContains('delete_home_group($group)', $account);
        $test->assertStringContains('collapsible-panel group-create-panel', $index);
        $test->assertStringContains('can_delete_home_group($user)', $index);
        $test->assertStringContains('function queue_email_notification', $account);
        $test->assertStringContains("public_url('/reset-password?token='", $account);
        $test->assertStringContains("app_config('url')", $account);
        $test->assertStringContains("'url' => '',", file_get_contents(__DIR__ . '/../../config/app.php'));
        $test->assertStringContains('function can_invite_person_account', $account);
        $test->assertStringContains('function person_links_by_user_id', $account);
        $test->assertStringContains('function create_account_invite_link', $account);
        $test->assertStringContains('function active_account_invite_for_person', $account);
        $test->assertStringContains('token_cipher', $schema);
        $test->assertStringContains('can_invite_person_account($user)', $index);
        $test->assertStringContains('render_person_account_summary', $index);
        $test->assertStringContains('История изменений', $index);
        $test->assertStringContains('data-copy-source', $index);
        $test->assertStringContains('create_member_account_for_person', $account);
        $test->assertStringContains('function assignable_roles', $account);
        $test->assertStringContains('function can_transition_role', $account);
        $test->assertStringContains('function person_add_group_membership', $account);
        $test->assertStringContains('person_group_memberships', $schema);
        $test->assertFileExists(__DIR__ . '/../../database/migrations/020_person_group_memberships.sql');
        $test->assertStringContains('/user-access-update', $account);
        $test->assertStringContains('/person-set-group', $index);
        $test->assertStringContains('inline-access-form', $account);
        $test->assertStringContains('form="\' . e($formId) . \'"', $account);
        $test->assertStringContains('/person-group-remove', $index);
        $test->assertStringContains('/person-group-primary', $index);
        $test->assertStringContains('Домашние группы', $index);
        $nav = file_get_contents(__DIR__ . '/../../src/AppNav.php');
        $test->assertStringContains('can_manage_users($user)', $nav);
        $test->assertStringContains('render_person_account_summary', $bootstrap);
        $test->assertStringContains('user_card_href($userId, $viewer)', $bootstrap);
        $test->assertStringContains('Мой профиль', $bootstrap);
        $test->assertStringContains("status-badge ' . (\$registered ? 'registered'", $account);
        $test->assertStringContains('function parse_people_list_filters', $account);
        $test->assertStringContains('function people_list_filter_sql', $account);
        $test->assertStringContains('function users_list_filter_sql', $account);
        $test->assertStringContains('function render_people_list_filters', $account);
        $test->assertStringContains("e(app_path('/person?id='", $account);
        $test->assertFileExists(__DIR__ . '/../../bin/send-emails.php');
        $test->assertFileExists(__DIR__ . '/../../src/Mail.php');
        $test->assertStringContains('bin/send-emails.php', $compose);
        $test->assertStringContains('function send_app_mail', file_get_contents(__DIR__ . '/../../src/Mail.php'));
        $test->assertStringContains("'driver' => 'smtp'", file_get_contents(__DIR__ . '/../../config/local.example.php'));
    },

    'training management is limited to regional pastor and admin roles' => function (TestCase $test): void {
        $features = file_get_contents(__DIR__ . '/../../src/Features.php');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');

        $test->assertStringContains('function can_manage_training', $features);
        $test->assertStringContains("'district_leader', 'pastor', 'admin', 'super_admin'", $features);
        $test->assertStringContains('$path === \'/knowledge-save\'', $features);
        $test->assertStringContains('$path === \'/knowledge-image-upload\'', $features);
        $test->assertStringContains('knowledge_store_uploaded_image', $features);
        $test->assertStringContains('knowledge_resolve_item_image', $features);
        $test->assertStringContains('Новый материал обучения', $index);
        $test->assertStringContains('can_manage_training($user)', $index);
        $test->assertStringContains('$editMode = $canManageTraining', $index);
        $test->assertStringContains('/knowledge?edit=1', $index);
        $test->assertStringContains('Редактировать материалы', $index);
        $test->assertStringContains('enctype="multipart/form-data"', $index);
        $test->assertStringContains('knowledge_render_body', $index);
        $test->assertStringContains('knowledge_image_field_html', $index);
        $test->assertStringContains('knowledge_inline_image_controls_html', $index);
        $test->assertStringContains('/knowledge/home-group-leader-course', $index);
        $test->assertStringContains('knowledge_course_page($user)', $index);
        $test->assertStringContains('home_group_leader_course_data()', $index);
        $test->assertStringContains('knowledge-course-promo', $index);
        $test->assertStringContains('/knowledge/system-guide', $index);
        $test->assertStringContains('knowledge_system_guide_page($user)', $index);
        $test->assertStringContains('system_guide_promo_html($user)', $index);
        $test->assertStringContains('system_guide_render_page($user)', $index);
    },

    'home group leader course has route module assets and reader styling' => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $css = file_get_contents(__DIR__ . '/../../public/assets/app.css');
        $modules = file_get_contents(__DIR__ . '/../../src/load_modules.php');

        $test->assertFileExists(__DIR__ . '/../../src/HomeGroupLeaderCourse.php');
        $test->assertFileExists(__DIR__ . '/../../public/assets/home-group-leader-course-hero.jpg');
        $test->assertStringContains('HomeGroupLeaderCourse.php', $modules);
        $test->assertStringContains('home_group_leader_course_data', $modules);
        $test->assertStringContains('function knowledge_course_page', $index);
        $test->assertStringContains('render_knowledge_course_paragraph', $index);
        $test->assertStringContains('course-hero', $css);
        $test->assertStringContains('course-toc', $css);
        $test->assertStringContains('course-lesson__body', $css);
    },

    'system guide has role scoped module route and reader styling' => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $css = file_get_contents(__DIR__ . '/../../public/assets/app.css');
        $modules = file_get_contents(__DIR__ . '/../../src/load_modules.php');
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');

        $test->assertFileExists(__DIR__ . '/../../src/SystemGuide.php');
        $test->assertStringContains("'SystemGuide.php'", $modules);
        $test->assertStringContains('system_guide_data', $modules);
        $test->assertStringContains('system_guide_visible_tree', $modules);
        $test->assertStringContains('function knowledge_system_guide_page', $index);
        $test->assertStringContains('/knowledge/system-guide', $index);
        $test->assertFileExists(__DIR__ . '/../../src/AppNav.php');
        $test->assertStringContains("'AppNav.php'", $modules);
        $test->assertStringContains('app_sidebar_nav', $modules);
        $test->assertStringNotContains('function app_sidebar_nav', $bootstrap);
        $test->assertStringContains('guide-legend', $css);
        $test->assertStringContains('guide-ui', $css);
        $test->assertStringContains('knowledge-guide-promo', $css);
    },

    'knowledge images have dedicated module migration and upload hardening' => function (TestCase $test): void {
        $knowledge = file_get_contents(__DIR__ . '/../../src/Knowledge.php');
        $schema = file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');
        $migration = file_get_contents(__DIR__ . '/../../database/migrations/014_knowledge_item_image.sql');
        $uploadsHtaccess = file_get_contents(__DIR__ . '/../../public/uploads/knowledge/.htaccess');
        $rootHtaccess = file_get_contents(__DIR__ . '/../../.htaccess');
        $js = file_get_contents(__DIR__ . '/../../public/assets/app.js');
        $css = file_get_contents(__DIR__ . '/../../public/assets/app.css');
        $modules = file_get_contents(__DIR__ . '/../../src/load_modules.php');

        $test->assertFileExists(__DIR__ . '/../../src/Knowledge.php');
        $test->assertFileExists(__DIR__ . '/../../public/uploads/knowledge/.gitkeep');
        $test->assertStringContains("'Knowledge.php'", $modules);
        $test->assertStringContains('image_path VARCHAR(255) NULL', $schema);
        $test->assertStringContains('ADD COLUMN image_path VARCHAR(255) NULL', $migration);
        $test->assertStringContains('function knowledge_store_uploaded_image', $knowledge);
        $test->assertStringContains('function knowledge_render_body', $knowledge);
        $test->assertStringContains('function knowledge_is_safe_image_path', $knowledge);
        $test->assertStringContains('Require all denied', $uploadsHtaccess);
        $test->assertStringContains('RemoveHandler .php', $uploadsHtaccess);
        $test->assertStringContains('uploads/.*', $rootHtaccess);
        $test->assertStringContains('/knowledge-image-upload', $js);
        $test->assertStringContains('data-knowledge-insert-image', $js);
        $test->assertStringContains('previewKnowledgeCoverImage', $js);
        $test->assertStringContains('.knowledge-card__media', $css);
        $test->assertStringContains('.knowledge-inline-image', $css);
    },

    'miracles section has schema migration routes and prayer testimony flow' => function (TestCase $test): void {
        $schema = file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');
        $seed = file_get_contents(__DIR__ . '/../../database/init/002_seed.sql');
        $migration = file_get_contents(__DIR__ . '/../../database/migrations/017_miracles.sql');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $features = file_get_contents(__DIR__ . '/../../src/Features.php');
        $miracles = file_get_contents(__DIR__ . '/../../src/Miracles.php');
        $domain = file_get_contents(__DIR__ . '/../../src/Domain.php');
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        $nav = file_get_contents(__DIR__ . '/../../src/AppNav.php');
        $modules = file_get_contents(__DIR__ . '/../../src/load_modules.php');
        $css = file_get_contents(__DIR__ . '/../../public/assets/app.css');
        $js = file_get_contents(__DIR__ . '/../../public/assets/app.js');
        $docs = file_get_contents(__DIR__ . '/../../docs/shared-hosting.md');

        $test->assertFileExists(__DIR__ . '/../../src/Miracles.php');
        $test->assertFileExists(__DIR__ . '/../../database/migrations/017_miracles.sql');
        $test->assertStringContains('CREATE TABLE miracles', $schema);
        $test->assertStringContains('CREATE TABLE IF NOT EXISTS miracles', $migration);
        $test->assertStringContains('uniq_miracle_prayer', $schema);
        $test->assertStringContains('INSERT INTO miracles', $seed);
        $test->assertStringContains("'Miracles.php'", $modules);
        $test->assertStringContains('miracles_page', $modules);
        $test->assertStringContains("'/miracles' => miracles_page(\$user)", $index);
        $test->assertStringContains("'/miracle' => miracle_page(\$user)", $index);
        $test->assertStringContains("app_path('/miracles')", $account);
        $test->assertStringContains('<h2>Чудеса</h2>', $account);
        $test->assertStringNotContains("'path' => '/miracles'", $nav);
        $test->assertStringNotContains("'label' => 'Чудеса'", $nav);
        $test->assertStringContains('function miracle_default_title_from_prayer', $domain);
        $test->assertStringContains('function miracle_upsert_from_prayer', $miracles);
        $test->assertStringContains("\$path === '/miracle-from-prayer'", $features);
        $test->assertStringContains("\$path === '/miracle-add'", $features);
        $test->assertStringContains("\$path === '/miracle-archive'", $features);
        $test->assertStringContains('miracle_body', $features);
        $test->assertStringContains('/miracle-from-prayer', $index);
        $test->assertStringContains('Рассказать свидетельство', $index);
        $test->assertStringContains('data-prayer-status', $features);
        $test->assertStringContains('data-prayer-status', $js);
        $test->assertStringContains('.miracle-card', $css);
        $test->assertStringContains('017_miracles.sql', $docs);
        $test->assertStringContains('UPDATE miracles SET created_by = NULL', $account);
        $test->assertStringContains('UPDATE miracles SET person_id = NULL', $account);
    },

    'public prayer request form has schema migration and guest anonymity controls' => function (TestCase $test): void {
        $schema = file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');
        $migration = file_get_contents(__DIR__ . '/../../database/migrations/018_prayer_public_submit.sql');
        $module = file_get_contents(__DIR__ . '/../../src/PrayerPublic.php');
        $domain = file_get_contents(__DIR__ . '/../../src/Domain.php');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $js = file_get_contents(__DIR__ . '/../../public/assets/app.js');
        $css = file_get_contents(__DIR__ . '/../../public/assets/app.css');
        $docs = file_get_contents(__DIR__ . '/../../docs/shared-hosting.md');

        $test->assertStringContains('submitter_name VARCHAR(190) NULL', $schema);
        $test->assertStringContains('is_anonymous TINYINT(1) NOT NULL DEFAULT 0', $schema);
        $test->assertStringContains('church_id INT UNSIGNED NULL', $schema);
        $test->assertStringContains('ADD COLUMN submitter_name', $migration);
        $test->assertStringContains('function prayer_submitter_label', $domain);
        $test->assertStringContains('function prayer_public_insert', $module);
        $test->assertStringContains('/prayer-request', $index);
        $test->assertStringContains('redirect_legacy_crm_to_default_church(\'/prayer-request\'', $index);
        $test->assertStringContains('data-prayer-anonymous', $js);
        $test->assertStringContains('data-prayer-guest-name', $js);
        $test->assertStringContains('.prayer-public-shell', $css);
        $test->assertStringContains('018_prayer_public_submit.sql', $docs);
        $test->assertStringContains('/{slug}/prayer-request', $docs);
    },

    'public miracle share form mirrors prayer-request flow' => function (TestCase $test): void {
        $schema = file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');
        $migration = file_get_contents(__DIR__ . '/../../database/migrations/019_miracle_public_submit.sql');
        $miracles = file_get_contents(__DIR__ . '/../../src/Miracles.php');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $modules = file_get_contents(__DIR__ . '/../../src/load_modules.php');
        $docs = file_get_contents(__DIR__ . '/../../docs/shared-hosting.md');

        $routePos = strpos($index, "if (\$path === '/miracle-share')");
        $loginPos = strpos($index, '$user = require_login();');
        $test->assertTrue($routePos !== false && $loginPos !== false && $routePos < $loginPos);
        $test->assertStringContains('redirect_legacy_crm_to_default_church(\'/miracle-share\'', $index);
        $test->assertStringContains('miracle_share_public_page();', $index);
        $test->assertStringContains('function miracle_share_public_page', $miracles);
        $test->assertStringContains('current_church()', $miracles);
        $test->assertStringContains('public_prayer_request_path', $miracles);
        $test->assertStringContains('function miracle_public_insert', $miracles);
        $test->assertStringContains('data-prayer-anonymous', $miracles);
        $test->assertStringContains('Отправить анонимно', $miracles);
        $test->assertStringContains('miracle_share_public_page', $modules);
        $test->assertStringContains('submitter_name VARCHAR(190) NULL', $schema);
        $test->assertStringContains('ADD COLUMN submitter_name VARCHAR(190) NULL AFTER body', $migration);
        $test->assertStringContains('019_miracle_public_submit.sql', $docs);
        $test->assertStringContains('/{slug}/miracle-share', $docs);
    },

    'minified frontend assets are built from source files' => function (TestCase $test): void {
        foreach (['package.json', 'scripts/build-css.mjs', 'scripts/build-js.mjs', 'public/assets/app.min.css', 'public/assets/app.min.js'] as $file) {
            $test->assertFileExists(__DIR__ . '/../../' . $file);
        }

        $cssSource = file_get_contents(__DIR__ . '/../../public/assets/app.css');
        $cssMin = file_get_contents(__DIR__ . '/../../public/assets/app.min.css');
        $jsSource = file_get_contents(__DIR__ . '/../../public/assets/app.js');
        $jsMin = file_get_contents(__DIR__ . '/../../public/assets/app.min.js');

        $test->assertTrue(strlen($cssMin) < strlen($cssSource));
        $test->assertTrue(strlen($jsMin) < strlen($jsSource));
    },

    'bot digest counter tracks actually queued channel events' => function (TestCase $test): void {
        $bots = file_get_contents(__DIR__ . '/../../src/Bots.php');

        $test->assertStringContains('function queue_bot_notification', $bots);
        $test->assertStringContains('array $actions = []): int', $bots);
        $test->assertStringContains('$created++;', $bots);
        $test->assertStringContains('$created += queue_bot_notification', $bots);
    },

    'user page bot accounts query matches bot_accounts schema' => function (TestCase $test): void {
        $schema = file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        $bots = file_get_contents(__DIR__ . '/../../src/Bots.php');
        $migration = file_get_contents(__DIR__ . '/../../database/migrations/007_social_login_and_bots.sql');

        $test->assertStringContains('function bot_accounts_for_user', $bots);
        $test->assertStringContains(
            'SELECT provider, chat_id, display_name FROM bot_accounts WHERE user_id = ? ORDER BY provider',
            $bots
        );
        $test->assertStringContains('bot_accounts_for_user($userId)', $account);
        $test->assertStringContains('bot_account_display_pair($row)', $account);
        $test->assertStringNotContains('SELECT channel, external_chat_id, label FROM bot_accounts', $account);
        $test->assertStringNotContains('ORDER BY channel', $account);
        $test->assertStringNotContains("\$row['channel']", $account);
        $test->assertStringNotContains("\$row['external_chat_id']", $account);
        $test->assertStringNotContains("\$row['label']", $account);

        foreach (['provider ENUM(\'max\',\'vk\',\'telegram\') NOT NULL', 'chat_id VARCHAR(190) NOT NULL', 'display_name VARCHAR(190) NULL'] as $needle) {
            $test->assertStringContains($needle, $schema);
        }
        $test->assertStringContains("provider ENUM('max','vk') NOT NULL", $migration);
        $test->assertStringContains("MODIFY provider ENUM('max','vk','telegram') NOT NULL", file_get_contents(__DIR__ . '/../../database/migrations/039_bot_telegram_provider.sql'));
        $test->assertStringNotContains('channel ENUM', explode('CREATE TABLE bot_accounts', $schema)[1] ?? '');
    },

    'bot deep link helpers and telegram webhook route exist' => function (TestCase $test): void {
        $bots = file_get_contents(__DIR__ . '/../../src/Bots.php');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');

        $test->assertStringContains('function bot_build_deep_link', $bots);
        $test->assertStringContains('function bot_parse_update', $bots);
        $test->assertStringContains('function bot_send_chat_message', $bots);
        $test->assertStringContains('X-Max-Bot-Api-Secret', $bots);
        $test->assertStringContains("'/bot/telegram/webhook'", $index);
        $test->assertStringContains('function is_absolute_http_url', $bootstrap);
        $test->assertStringContains('is_absolute_http_url($path)', $bootstrap);
    },
    'schema verify script covers miracles and bot_accounts' => function (TestCase $test): void {
        $script = file_get_contents(__DIR__ . '/../../bin/verify-schema.php');
        $docs = file_get_contents(__DIR__ . '/../../docs/shared-hosting.md');

        $test->assertFileExists(__DIR__ . '/../../bin/verify-schema.php');
        $test->assertStringContains('function schema_required_objects', $script);
        $test->assertStringContains("'table' => 'miracles'", $script);
        $test->assertStringContains("'table' => 'bot_accounts'", $script);
        $test->assertStringContains("'provider', 'chat_id', 'display_name'", $script);
        $test->assertStringContains('017_miracles.sql', $docs);
        $test->assertStringContains('bin/verify-schema.php', $docs);
        $test->assertStringContains('020_person_group_memberships.sql', $docs);
    },

    'login social identity and bot integrations are represented' => function (TestCase $test): void {
        $schema = file_get_contents(__DIR__ . '/../../database/init/001_schema.sql');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $account = file_get_contents(__DIR__ . '/../../src/Account.php');
        $social = file_get_contents(__DIR__ . '/../../src/Social.php');
        $bots = file_get_contents(__DIR__ . '/../../src/Bots.php');
        $compose = file_get_contents(__DIR__ . '/../../docker-compose.yml');

        foreach (['login VARCHAR(80) NOT NULL UNIQUE', 'social_identities', 'oauth_states', 'bot_accounts', 'bot_link_tokens', 'bot_inbound_messages', 'yandex_geocode_cache', 'addresses_json'] as $needle) {
            $test->assertStringContains($needle, $schema);
        }

        foreach (["/social/start", "/social/callback", "/bot/max/webhook", "/bot/vk/webhook", "/geocode/reverse"] as $route) {
            $test->assertStringContains($route, $index);
        }

        $test->assertStringContains('SELECT * FROM users WHERE login = ?', $index);
        $test->assertStringContains('function login_from_email', $account);
        $test->assertStringContains('login:address.all.read', $social);
        $test->assertStringContains('render_yandex_address_picker', $social);
        $test->assertStringContains("code_challenge_method'] = 'S256'", $social);
        $test->assertStringNotContains("code_challenge_method'] = 'plain'", $social);
        $test->assertStringContains('social_pkce_challenge', $social);
        $test->assertStringContains('device_id', $social);
        $test->assertStringContains('function bot_handle_webhook', $bots);
        $test->assertStringContains('function queue_pastoral_bot_notification', $bots);
        $test->assertFileExists(__DIR__ . '/../../bin/send-bot-notifications.php');
        $test->assertStringContains('bin/send-bot-notifications.php', $compose);
        $test->assertFileExists(__DIR__ . '/../../docs/social-bots-leader-guide.md');
    },

    'group forms do not expose manual coordinate inputs' => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $features = file_get_contents(__DIR__ . '/../../src/Features.php');
        $appJs = file_get_contents(__DIR__ . '/../../public/assets/app.js');

        foreach ([$index, $features] as $source) {
            $test->assertStringNotContains('Широта', $source);
            $test->assertStringNotContains('Долгота', $source);
            $test->assertStringNotContains('data-address-latitude', $source);
            $test->assertStringNotContains('data-address-longitude', $source);
            $test->assertStringNotContains('name="latitude"', $source);
            $test->assertStringNotContains('name="longitude"', $source);
        }

        $test->assertStringContains('resolve_home_group_location_from_post()', $features);
        $test->assertStringNotContains('fillAddressCoordinates', $appJs);
    },

];
