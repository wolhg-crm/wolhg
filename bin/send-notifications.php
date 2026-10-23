<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require $autoload;
}

$config = webpush_config();
if ($config['public_key'] === '' || $config['private_key'] === '') {
    fwrite(STDERR, "VAPID keys are not configured. Generate them on /security or set webpush keys in config/local.php.\n");
    exit(2);
}
if ($config['subject'] === '') {
    fwrite(STDERR, "VAPID contact (subject) is not configured. Set email or https URL on /security.\n");
    exit(2);
}

if (!class_exists(\Minishlink\WebPush\WebPush::class)) {
    fwrite(STDERR, "minishlink/web-push is not installed. Run composer install inside the app container.\n");
    exit(3);
}

exit(cli_with_lock('send-notifications', static function () use ($config): void {
    $events = fetch_all('SELECT * FROM notification_events WHERE channel = "webpush" AND status = "pending" ORDER BY id LIMIT 50');
    $auth = [
        'VAPID' => [
            'subject' => $config['subject'],
            'publicKey' => $config['public_key'],
            'privateKey' => $config['private_key'],
        ],
    ];
    $webPush = new \Minishlink\WebPush\WebPush($auth);

    foreach ($events as $event) {
        if (function_exists('is_demo_user')) {
            $eventUser = fetch_one('SELECT church_id FROM users WHERE id = ? LIMIT 1', [(int) $event['user_id']]);
            if ($eventUser && church_is_demo_by_id((int) ($eventUser['church_id'] ?? 0))) {
                db()->prepare('UPDATE notification_events SET status = "failed", error_message = ? WHERE id = ?')
                    ->execute(['Skipped: demo church notification', $event['id']]);
                continue;
            }
        }
        $subscriptions = fetch_all('SELECT * FROM web_push_subscriptions WHERE user_id = ?', [$event['user_id']]);
        if (!$subscriptions) {
            db()->prepare('UPDATE notification_events SET status = "failed", error_message = ? WHERE id = ?')->execute(['No active WebPush subscriptions', $event['id']]);
            continue;
        }

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(
                \Minishlink\WebPush\Subscription::create([
                    'endpoint' => $subscription['endpoint'],
                    'keys' => [
                        'p256dh' => $subscription['p256dh'],
                        'auth' => $subscription['auth'],
                    ],
                ]),
                json_encode([
                    'title' => $event['title'],
                    'body' => $event['body'],
                    'url' => $event['url'],
                ], JSON_UNESCAPED_UNICODE)
            );
        }

        $ok = true;
        $error = null;
        foreach ($webPush->flush() as $report) {
            if (!$report->isSuccess()) {
                $ok = false;
                $error = $report->getReason();
            }
        }

        db()->prepare('UPDATE notification_events SET status = ?, error_message = ?, sent_at = IF(? = "sent", NOW(), sent_at) WHERE id = ?')
            ->execute([$ok ? 'sent' : 'failed', $error, $ok ? 'sent' : 'failed', $event['id']]);
    }
}));
