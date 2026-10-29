<?php

declare(strict_types=1);

// Lead job in the minute — no stagger (notifications +15s, bots +30s).
require __DIR__ . '/../src/bootstrap.php';

exit(cli_with_lock('send-emails', static function (): void {
    email_notifications_dispatch_pending(50);
}));
