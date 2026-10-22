<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$items = fetch_all('SELECT * FROM email_notifications WHERE status = "pending" ORDER BY created_at LIMIT 50');
$sent = 0;
$failed = 0;

foreach ($items as $item) {
    if (function_exists('demo_queue_email_allowed') && !demo_queue_email_allowed(
        isset($item['user_id']) ? (int) $item['user_id'] : null
    )) {
        db()->prepare('UPDATE email_notifications SET status = "failed", error_message = ? WHERE id = ?')
            ->execute(['Skipped: demo church notification', (int) $item['id']]);
        $failed++;
        continue;
    }
    $ok = false;
    $error = null;

    try {
        $result = send_app_mail((string) $item['to_email'], (string) $item['subject'], (string) $item['body']);
        $ok = (bool) $result['ok'];
        $error = $result['error'];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    if ($ok) {
        db()->prepare('UPDATE email_notifications SET status = "sent", sent_at = NOW(), error_message = NULL WHERE id = ?')->execute([(int) $item['id']]);
        $sent++;
    } else {
        db()->prepare('UPDATE email_notifications SET status = "failed", error_message = ? WHERE id = ?')->execute([$error ?: 'unknown mail error', (int) $item['id']]);
        $failed++;
    }
}

