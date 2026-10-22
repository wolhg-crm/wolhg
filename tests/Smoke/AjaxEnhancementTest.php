<?php

declare(strict_types=1);

return [
    "client intercepts internal navigation and form submissions" => function (TestCase $test): void {
        $js = file_get_contents(__DIR__ . "/../../public/assets/app.js");

        $test->assertStringContains("X-Requested-With", $js);
        $test->assertStringContains("loadPage(link.href)", $js);
        $test->assertStringContains("document.addEventListener(\"submit\"", $js);
        $test->assertStringContains("window.history[mode]", $js);
        $test->assertStringContains("initContactMasks", $js);
        $test->assertStringContains("data-mask", $js);
        $test->assertStringContains("formatPhoneMask", $js);
        $test->assertStringContains("formatTelegramMask", $js);
        $test->assertStringContains("[data-copy]", $js);
        $test->assertStringContains("data-copy-source", $js);
        $test->assertStringContains("startPersonFieldEdit", $js);
        $test->assertStringContains("savePersonField", $js);
        $test->assertStringContains("/person-field-update", $js);
        $test->assertStringContains("payload.remove", $js);
        $test->assertStringContains("/knowledge-image-upload", $js);
        $test->assertStringContains("uploadKnowledgeInlineImage", $js);
        $test->assertStringContains("previewKnowledgeCoverImage", $js);
    },
    "login page participates in ajax enhancement" => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . "/../../public/index.php");
        $account = file_get_contents(__DIR__ . "/../../src/Account.php");

        $test->assertStringContains("public_page_footer()", $index);
        $test->assertStringContains("asset_url('/assets/app.js')", $account);
        $test->assertStringContains("ajax_redirect(\"/\")", $index);
    },
    "person card supports clickable contacts and inline field edits" => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . "/../../public/index.php");
        $bootstrap = file_get_contents(__DIR__ . "/../../src/bootstrap.php");
        $features = file_get_contents(__DIR__ . "/../../src/Features.php");
        $css = file_get_contents(__DIR__ . "/../../public/assets/app.css");

        $test->assertStringContains("render_person_detail_field", $index);
        $test->assertStringContains("'birth_date'", $index);
        $test->assertStringContains("marital_status_select_markup", $index);
        $test->assertStringContains("'marital_status'", $index);
        $test->assertStringContains("checkbox-row", $index);
        $test->assertStringContains("is_potential_leader", $index);
        $test->assertStringContains("'first_visit_date'", $index);
        $test->assertStringContains("'repentance_date'", $index);
        $test->assertStringContains("'membership_date'", $index);
        $test->assertStringContains("detail-edit-btn", $bootstrap);
        $test->assertStringContains("data-options", $bootstrap);
        $test->assertStringContains("'input' => 'select'", $bootstrap);
        $test->assertStringContains("function email_href", $bootstrap);
        $test->assertStringContains("/person-field-update", $features);
        $test->assertStringContains("parse_person_field_value", $features);
        $test->assertStringContains("function marital_status_options", $features);
        $test->assertStringContains("function marital_status_select_markup", $features);
        $test->assertStringContains("'birth_date', 'marital_status', 'first_visit_date', 'repentance_date', 'baptism_date', 'membership_date'", $features);
        $test->assertStringContains(".detail-field", $css);
        $test->assertStringContains(".checkbox-row", $css);
        $test->assertStringContains('width: 20px', $css);
        $test->assertStringContains('church-care-v24', file_get_contents(__DIR__ . "/../../public/service-worker.js"));
        $js = file_get_contents(__DIR__ . "/../../public/assets/app.js");
        $test->assertStringContains('dataset.input === "select"', $js);
        $test->assertStringContains('querySelector("input, select")', $js);
        $test->assertStringContains('"/social/start"', $js);
        $test->assertStringContains('requiresFullNavigation', $js);
        $test->assertStringContains('payload.navigate', $js);
        $test->assertStringContains('data-yandex-addresses-json', $js);
        $social = file_get_contents(__DIR__ . "/../../src/Social.php");
        $test->assertStringContains('yandex-address-picker__list', $social);
        $test->assertStringContains('data-yandex-addresses-json', $social);
    },
    "users page keeps people hub, accounts list and ajax delete hooks" => function (TestCase $test): void {
        $account = file_get_contents(__DIR__ . "/../../src/Account.php");
        $bootstrap = file_get_contents(__DIR__ . "/../../src/bootstrap.php");
        $js = file_get_contents(__DIR__ . "/../../public/assets/app.js");
        $index = file_get_contents(__DIR__ . "/../../public/index.php");

        $test->assertStringContains('$formId = \'user-update-\' . $userId;', $account);
        $test->assertStringContains('people_hub_view(', $account);
        $test->assertStringContains('render_people_hub_view_switch(', $account);
        $test->assertStringContains('render_people_hub_inbox(', $account);
        $test->assertStringContains('data-user-row="', $account);
        $test->assertStringContains('data-person-row="', $account);
        $test->assertStringContains('people-list-mobile', $account);
        $test->assertStringContains('render_role_invite_links_panel($user)', $account);
        $test->assertStringContains('function role_requests_page', $account);
        $test->assertStringContains('role_requests_link(', $account);
        $test->assertStringContains('function role_invite_page', $account);
        $test->assertStringContains('function role_invite_storage_ready', $account);
        $test->assertStringContains('function role_invite_public_path', $account);
        $test->assertStringContains('function role_invite_matches_request_church', $account);
        $test->assertStringContains('role_invite_storage_ready()', $account);
        $test->assertStringContains('db_table_exists', $bootstrap);
        $test->assertStringContains('/role-invite-approve', $account);
        $test->assertStringContains('/role-invite-reject', $account);
        $test->assertStringContains('/participant-reject', $account);
        $test->assertStringContains('role_invitation_requests', $account);
        $test->assertStringContains('id="role-invites"', $account);
        $test->assertStringContains('/role-requests', $account);
        $test->assertStringContains('Приглашение на роль', $account);
        $test->assertStringContains('Заявки на роли', $account);
        $test->assertStringContains('data-default-collapsed="1"', $account);
        $test->assertStringNotContains('<details class="panel collapsible-panel role-invite-panel" open', $account);
        $test->assertStringNotContains('render_role_invite_pending_panel', $account);
        $test->assertStringNotContains('id="role-pending"', $account);
        $test->assertStringContains('collapse-indicator', $account);
        $test->assertStringContains('function participant_requests_new_count', $account);
        $test->assertStringContains('participant_requests_link(', $account);
        $test->assertStringContains('can_create_home_group($user)', $account);
        $test->assertStringContains('render_people_list_filters(', $account);
        $test->assertStringContains('parse_people_list_filters($_GET', $account);
        $test->assertStringContains('data-people-filters', $account);
        $test->assertStringContains("e(app_path('/users'))", $account);
        $test->assertStringContains('function people_hub_logical_path', $account);
        $test->assertStringContains('app_path($path)', $account);
        $test->assertStringContains('people_hub_url([], \'accounts\')', $account);
        $test->assertStringContains('people_hub_url([], \'people\')', $account);
        $test->assertStringContains('status-badge pending', $account);
        $test->assertStringContains('pattern="[a-zA-Z0-9._\\x2d]+"', $account);
        $test->assertStringNotContains('pattern="[-a-zA-Z0-9._]+"', $account);
        $test->assertStringNotContains('pattern="[a-zA-Z0-9._\\-]+"', $account);

        $test->assertStringContains('/person-delete', $account);
        $test->assertStringContains("'remove' => '[data-person-row=" . chr(34) . "'", $index);
        $test->assertStringContains("'remove' => '[data-user-row=" . chr(34) . "'", $account);
        $test->assertStringContains("payload.remove", $js);
        $test->assertStringContains("asset_url('/assets/app.js')", $bootstrap);
        $test->assertStringContains('people_accounts_hub_path()', $account);
        $test->assertStringContains('person-change-log', $index);
        $test->assertStringContains('Путь и рост', $index);
    },
    "user card page is linked from user lists and supports return redirects" => function (TestCase $test): void {
        $account = file_get_contents(__DIR__ . "/../../src/Account.php");
        $index = file_get_contents(__DIR__ . "/../../public/index.php");
        $features = file_get_contents(__DIR__ . "/../../src/Features.php");
        $bootstrap = file_get_contents(__DIR__ . "/../../src/bootstrap.php");

        $test->assertStringContains("'/user' => user_page", $index);
        $test->assertStringContains('function user_page', $account);
        $test->assertStringContains('user_card_href($userId, $user)', $account);
        $test->assertStringContains('user_card_href($userId, $user)', $features);
        $test->assertStringContains('user_card_href($userId, $viewer)', $bootstrap);
        $test->assertStringContains('<input type="hidden" name="return" value="user">', $account);
        $test->assertStringContains('user_admin_return_path($target)', $account);
        $test->assertStringContains('redirect(\'/profile\')', $account);
        $test->assertStringContains('Моя учетная запись', $account);
        $test->assertStringContains('render_account_hero', $account);
        $test->assertStringContains('person_linked_to_user', $account);
    },
    "login asks for two factor code only after password step" => function (TestCase $test): void {
        $index = file_get_contents(__DIR__ . "/../../public/index.php");

        $test->assertStringContains("pending_2fa_user_id", $index);
        $test->assertStringContains('name="step" value="2fa"', $index);
        $test->assertStringContains("Подтвердить вход", $index);
        $test->assertStringNotContains('placeholder="Если включена 2FA"', $index);
    },
    "server exposes an ajax redirect contract while keeping redirects" => function (TestCase $test): void {
        $bootstrap = file_get_contents(__DIR__ . "/../../src/bootstrap.php");
        $index = file_get_contents(__DIR__ . "/../../public/index.php");
        $features = file_get_contents(__DIR__ . "/../../src/Features.php");

        $test->assertStringContains("function wants_ajax", $bootstrap);
        $test->assertStringContains("function ajax_redirect", $bootstrap);
        $test->assertStringContains("json_response([\"ok\" => true", $bootstrap);
        $test->assertStringContains("Встреча создана", $index);
        $test->assertStringContains("Карточка обновлена", $features);
    },
    "service worker keeps dynamic pages out of the offline cache" => function (TestCase $test): void {
        $worker = file_get_contents(__DIR__ . "/../../public/service-worker.js");

        $test->assertStringContains("const isStaticAsset", $worker);
        $test->assertStringContains("const isNavigation", $worker);
        $test->assertStringContains("cache: \"no-store\"", $worker);
        $test->assertStringContains("ASSETS.includes(url.pathname)", $worker);
        $test->assertStringNotContains("\"/\",", $worker);
    },

    "pwa install prompt is wired for leader-facing app install" => function (TestCase $test): void {
        $js = file_get_contents(__DIR__ . "/../../public/assets/app.js");
        $bootstrap = file_get_contents(__DIR__ . "/../../src/bootstrap.php");
        $worker = file_get_contents(__DIR__ . "/../../public/service-worker.js");

        $test->assertStringContains("beforeinstallprompt", $js);
        $test->assertStringContains("data-install-pwa", $js);
        $test->assertStringContains("data-dismiss-pwa-install", $js);
        $test->assertStringContains("pwa-install-dismissed", $js);
        $test->assertStringContains("appinstalled", $js);
        $test->assertStringContains("showPwaInstallHelp", $js);
        $test->assertStringContains("registerPwaServiceWorker", $js);
        $test->assertStringContains("data-pwa-install-panel", $bootstrap);
        $test->assertStringContains("data-dismiss-pwa-install", $bootstrap);
        $test->assertStringContains("data-pwa-ready-note", $bootstrap);
        $test->assertStringContains('can_show_pwa_install', $bootstrap);
        $test->assertStringContains('church-care-v24', $worker);
        $test->assertStringNotContains('/manifest.webmanifest', $worker);
    },

    "webpush subscription flow is wired in profile and service worker" => function (TestCase $test): void {
        $js = file_get_contents(__DIR__ . "/../../public/assets/app.js");
        $account = file_get_contents(__DIR__ . "/../../src/Account.php");
        $worker = file_get_contents(__DIR__ . "/../../public/service-worker.js");
        $bootstrap = file_get_contents(__DIR__ . "/../../src/bootstrap.php");

        $test->assertStringContains('id="enable-push"', $account);
        $test->assertStringContains('webpush_enabled', $account);
        $test->assertStringContains('class="checkbox-row"', $account);
        $test->assertStringContains('data-vapid-public-key', $bootstrap);
        $test->assertStringContains('webpush_config()', $bootstrap);
        $test->assertStringContains('pushManager.subscribe', $js);
        $test->assertStringContains('/push/subscribe', $js);
        $test->assertStringContains('addEventListener("push"', $worker);
        $test->assertStringContains('addEventListener("notificationclick"', $worker);
    },
];
