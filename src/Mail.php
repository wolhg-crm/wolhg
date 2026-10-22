<?php

declare(strict_types=1);

/**
 * @return array{
 *   driver: string,
 *   from: string,
 *   smtp: array{host: string, port: int, encryption: string, username: string, password: string, timeout: int}
 * }
 */
function mail_settings(): array
{
    $smtp = app_config('mail.smtp');
    if (!is_array($smtp)) {
        $smtp = [];
    }

    $from = trim((string) (app_setting('mail_from', (string) (app_config('mail.from') ?: '')) ?: ''));
    if ($from === '') {
        $from = 'crm@localhost';
    }

    $driver = strtolower(trim((string) (app_config('mail.driver') ?: 'mail')));
    if (!in_array($driver, ['mail', 'smtp'], true)) {
        $driver = 'mail';
    }

    $encryption = strtolower(trim((string) ($smtp['encryption'] ?? 'ssl')));
    if (!in_array($encryption, ['ssl', 'tls', 'none', ''], true)) {
        $encryption = 'ssl';
    }
    if ($encryption === '') {
        $encryption = 'none';
    }

    return [
        'driver' => $driver,
        'from' => $from,
        'smtp' => [
            'host' => trim((string) ($smtp['host'] ?? '')),
            'port' => (int) ($smtp['port'] ?? 465),
            'encryption' => $encryption,
            'username' => trim((string) ($smtp['username'] ?? '')),
            'password' => (string) ($smtp['password'] ?? ''),
            'timeout' => max(5, (int) ($smtp['timeout'] ?? 20)),
        ],
    ];
}

function mail_encode_header(string $value): string
{
    if ($value === '' || preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
        return $value;
    }

    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

/**
 * @return array{ok: bool, error: ?string}
 */
function send_app_mail(string $to, string $subject, string $body): array
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid recipient email'];
    }

    $settings = mail_settings();
    if ($settings['driver'] === 'smtp') {
        return send_app_mail_smtp($to, $subject, $body, $settings['from'], $settings['smtp']);
    }

    return send_app_mail_php($to, $subject, $body, $settings['from']);
}

/**
 * @return array{ok: bool, error: ?string}
 */
function send_app_mail_php(string $to, string $subject, string $body, string $from): array
{
    if (!function_exists('mail')) {
        return ['ok' => false, 'error' => 'PHP mail() is not available'];
    }

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $headers .= 'From: ' . $from . "\r\n";
    $headers .= 'Reply-To: ' . $from . "\r\n";

    $encodedSubject = mail_encode_header($subject);
    $ok = @mail($to, $encodedSubject, $body, $headers, '-f' . $from);
    if ($ok) {
        return ['ok' => true, 'error' => null];
    }

    return ['ok' => false, 'error' => 'mail() returned false; notification kept in log'];
}

/**
 * @param array{host: string, port: int, encryption: string, username: string, password: string, timeout: int} $smtp
 * @return array{ok: bool, error: ?string}
 */
function send_app_mail_smtp(string $to, string $subject, string $body, string $from, array $smtp): array
{
    if ($smtp['host'] === '' || $smtp['username'] === '' || $smtp['password'] === '') {
        return ['ok' => false, 'error' => 'SMTP is not configured: set mail.smtp.host/username/password in config/local.php'];
    }

    $encryption = $smtp['encryption'];
    $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $smtp['host'] . ':' . $smtp['port'];
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        $smtp['timeout'],
        STREAM_CLIENT_CONNECT,
        stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ])
    );

    if ($socket === false) {
        return ['ok' => false, 'error' => sprintf('SMTP connect failed: %s (%d)', $errstr !== '' ? $errstr : 'unknown error', $errno)];
    }

    stream_set_timeout($socket, $smtp['timeout']);

    try {
        mail_smtp_expect($socket, [220]);
        mail_smtp_command($socket, 'EHLO wolhg', [250]);

        if ($encryption === 'tls') {
            mail_smtp_command($socket, 'STARTTLS', [220]);
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!@stream_socket_enable_crypto($socket, true, $crypto)) {
                throw new RuntimeException('SMTP STARTTLS negotiation failed');
            }
            mail_smtp_command($socket, 'EHLO wolhg', [250]);
        }

        mail_smtp_command($socket, 'AUTH LOGIN', [334]);
        mail_smtp_command($socket, base64_encode($smtp['username']), [334]);
        mail_smtp_command($socket, base64_encode($smtp['password']), [235]);
        mail_smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250]);
        mail_smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        mail_smtp_command($socket, 'DATA', [354]);

        $message = mail_build_smtp_data($from, $to, $subject, $body);
        fwrite($socket, $message . "\r\n.\r\n");
        mail_smtp_expect($socket, [250]);
        mail_smtp_command($socket, 'QUIT', [221]);
    } catch (Throwable $e) {
        fclose($socket);
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    fclose($socket);
    return ['ok' => true, 'error' => null];
}

function mail_build_smtp_data(string $from, string $to, string $subject, string $body): string
{
    $normalizedBody = str_replace(["\r\n", "\r"], "\n", $body);
    $normalizedBody = str_replace("\n", "\r\n", $normalizedBody);
    $normalizedBody = preg_replace('/^\./m', '..', $normalizedBody) ?? $normalizedBody;

    $headers = [
        'Date: ' . date('r'),
        'From: ' . $from,
        'To: ' . $to,
        'Subject: ' . mail_encode_header($subject),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    return implode("\r\n", $headers) . "\r\n\r\n" . $normalizedBody;
}

/**
 * @param resource $socket
 * @param list<int> $okCodes
 */
function mail_smtp_command($socket, string $command, array $okCodes): string
{
    fwrite($socket, $command . "\r\n");
    return mail_smtp_expect($socket, $okCodes);
}

/**
 * @param resource $socket
 * @param list<int> $okCodes
 */
function mail_smtp_expect($socket, array $okCodes): string
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (preg_match('/^\d{3}[\s-]/', $line) !== 1) {
            continue;
        }
        if (isset($line[3]) && $line[3] === '-') {
            continue;
        }
        break;
    }

    if ($response === '') {
        throw new RuntimeException('SMTP server closed the connection');
    }

    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $okCodes, true)) {
        throw new RuntimeException(trim(sprintf('SMTP unexpected reply %d: %s', $code, $response)));
    }

    return $response;
}
