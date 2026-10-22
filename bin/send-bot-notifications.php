<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

function bot_event_text(array $event): string
{
    $text = (string) $event['title'] . "\n" . (string) $event['body'];
    if (!empty($event['url'])) {
        $text .= "\n" . absolute_url((string) $event['url']);
    }
    $actions = $event['actions'] ? json_decode((string) $event['actions'], true) : [];
    if (is_array($actions) && $actions) {
        $labels = array_map(static fn($a) => (string) ($a['label'] ?? ''), $actions);
        $text .= "\nБыстрые действия: " . implode(', ', array_filter($labels));
    }
    return $text;
}

$digests = queue_daily_pastor_digest();
$events = fetch_all('SELECT * FROM notification_events WHERE channel IN ("max", "vk", "telegram") AND status = "pending" ORDER BY id LIMIT 50');
$processed = 0;
foreach ($events as $event) {
    if (function_exists('church_is_demo_by_id')) {
        $eventUser = fetch_one('SELECT church_id FROM users WHERE id = ? LIMIT 1', [(int) $event['user_id']]);
        if ($eventUser && church_is_demo_by_id((int) ($eventUser['church_id'] ?? 0))) {
            db()->prepare('UPDATE notification_events SET status = "failed", error_message = ? WHERE id = ?')
                ->execute(['Skipped: demo church notification', $event['id']]);
            continue;
        }
    }
    $account = fetch_one('SELECT * FROM bot_accounts WHERE user_id = ? AND provider = ? LIMIT 1', [(int) $event['user_id'], (string) $event['channel']]);
    if (!$account) {
        db()->prepare('UPDATE notification_events SET status = "failed", error_message = ? WHERE id = ?')->execute(['Bot account is not linked', (int) $event['id']]);
        continue;
    }
    $ok = bot_send_chat_message((string) $event['channel'], (string) $account['chat_id'], bot_event_text($event));
    db()->prepare('UPDATE notification_events SET status = ?, error_message = ?, sent_at = IF(? = "sent", NOW(), sent_at) WHERE id = ?')
        ->execute([$ok ? 'sent' : 'failed', $ok ? null : 'Bot API request failed or token is missing', $ok ? 'sent' : 'failed', (int) $event['id']]);
    $processed++;
}

