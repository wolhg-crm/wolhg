<?php

declare(strict_types=1);

return [
    'webpush documentation exists and links from readme' => function (TestCase $test): void {
        $doc = __DIR__ . '/../../docs/webpush.md';
        $readme = file_get_contents(__DIR__ . '/../../README.md');

        $test->assertFileExists($doc);
        $test->assertStringContains('docs/webpush.md', $readme);
        $test->assertStringContains('Радара заботы', $readme);
    },

    'webpush documentation describes care radar trigger from code' => function (TestCase $test): void {
        $doc = file_get_contents(__DIR__ . '/../../docs/webpush.md');
        $bootstrap = file_get_contents(__DIR__ . '/../../src/bootstrap.php');
        $domain = file_get_contents(__DIR__ . '/../../src/Domain.php');

        $test->assertStringContains('ensure_automations', $doc);
        $test->assertStringContains('6 недель', $doc);
        $test->assertStringContains('first_visit_date', $doc);
        $test->assertStringContains('needs_radar_notification', $bootstrap);
        $test->assertStringContains('effective_last_attendance', $bootstrap);
        $test->assertStringContains('attendanceDays > 42', $domain);
        $test->assertStringContains('Радар заботы', $doc);
        $test->assertStringContains('notification_events', $doc);
        $test->assertStringContains('web_push_subscriptions', $doc);
        $test->assertStringContains('bin/send-notifications.php', $doc);
        $test->assertStringContains('minishlink/web-push', $doc);
    },

    'webpush documentation matches subscription and vapid endpoints' => function (TestCase $test): void {
        $doc = file_get_contents(__DIR__ . '/../../docs/webpush.md');
        $index = file_get_contents(__DIR__ . '/../../public/index.php');
        $features = file_get_contents(__DIR__ . '/../../src/Features.php');

        $test->assertStringContains('/push/subscribe', $doc);
        $test->assertStringContains('/security', $doc);
        $test->assertStringContains('суперадминистратором', $doc);
        $test->assertStringContains('/profile', $doc);
        $test->assertStringContains("if (\$method === 'POST' && \$path === '/push/subscribe')", $index);
        $test->assertStringContains('function webpush_config', $features);
        $test->assertStringContains('function generate_vapid_keys', $features);
        $test->assertStringContains('data-vapid-public-key', $doc);
    },

    'webpush documentation notes user preference limitation' => function (TestCase $test): void {
        $doc = file_get_contents(__DIR__ . '/../../docs/webpush.md');

        $test->assertStringContains('webpush_enabled', $doc);
        $test->assertStringContains('не проверяют', $doc);
    },

    'webpush sender validates vapid configuration' => function (TestCase $test): void {
        $sender = file_get_contents(__DIR__ . '/../../bin/send-notifications.php');

        $test->assertStringContains('webpush_config()', $sender);
        $test->assertStringContains('VAPID keys are not configured', $sender);
        $test->assertStringContains('VAPID contact (subject) is not configured', $sender);
        $test->assertStringContains('channel = "webpush" AND status = "pending"', $sender);
        $test->assertStringContains('No active WebPush subscriptions', $sender);
        $test->assertStringContains("exit(2)", $sender);
        $test->assertStringContains("exit(3)", $sender);
    },

    'service worker handles push payload and notification click' => function (TestCase $test): void {
        $worker = file_get_contents(__DIR__ . '/../../public/service-worker.js');
        $doc = file_get_contents(__DIR__ . '/../../docs/webpush.md');

        $test->assertStringContains('addEventListener("push"', $worker);
        $test->assertStringContains('showNotification', $worker);
        $test->assertStringContains('addEventListener("notificationclick"', $worker);
        $test->assertStringContains('event.data.json()', $worker);
        $test->assertStringContains('notificationclick', $doc);
    },

    'client subscribes with vapid key and posts subscription' => function (TestCase $test): void {
        $js = file_get_contents(__DIR__ . '/../../public/assets/app.js');

        $test->assertStringContains('#enable-push', $js);
        $test->assertStringContains('dataset.vapidPublicKey', $js);
        $test->assertStringContains('pushManager.subscribe', $js);
        $test->assertStringContains('userVisibleOnly: true', $js);
        $test->assertStringContains('applicationServerKey', $js);
        $test->assertStringContains('urlBase64ToUint8Array', $js);
        $test->assertStringContains('/push/subscribe', $js);
        $test->assertStringContains('Notification.requestPermission', $js);
    },

    'implementation status and shared hosting link webpush doc' => function (TestCase $test): void {
        $status = file_get_contents(__DIR__ . '/../../docs/implementation-status.md');
        $hosting = file_get_contents(__DIR__ . '/../../docs/shared-hosting.md');

        $test->assertStringContains('webpush.md', $status);
        $test->assertStringContains('webpush.md', $hosting);
    },
];
