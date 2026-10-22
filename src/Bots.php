<?php

declare(strict_types=1);

/** @return list<string> */
function bot_supported_providers(): array
{
    return ['max', 'vk', 'telegram'];
}

function bot_provider_label(string $provider): string
{
    return ['max' => 'Max', 'vk' => 'VK', 'telegram' => 'Telegram'][(string) $provider] ?? $provider;
}

function bot_provider_is_supported(string $provider): bool
{
    return in_array($provider, bot_supported_providers(), true);
}

function bot_provider_is_configured(string $provider): bool
{
    if (!bot_provider_is_supported($provider)) {
        return false;
    }
    $token = trim((string) (bot_config($provider)['token'] ?? ''));
    if ($token === '') {
        return false;
    }
    // Deep-link UX also needs a public handle where the platform requires one.
    if ($provider === 'telegram' && bot_provider_public_handle('telegram') === '') {
        return false;
    }
    return true;
}

/**
 * Read-only bot links for a user card. Columns match `bot_accounts` schema
 * (`provider`, `chat_id`, `display_name`) — not notification_events.channel.
 *
 * @return list<array<string, mixed>>
 */
function bot_accounts_for_user(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    return fetch_all(
        'SELECT provider, chat_id, display_name FROM bot_accounts WHERE user_id = ? ORDER BY provider',
        [$userId]
    );
}

/**
 * @param array<string, mixed> $row
 * @return array{0: string, 1: string}
 */
function bot_account_display_pair(array $row): array
{
    $provider = (string) ($row['provider'] ?? '');
    $displayName = trim((string) ($row['display_name'] ?? ''));
    $value = $displayName !== '' ? $displayName : (string) ($row['chat_id'] ?? '');

    return [bot_provider_label($provider), $value];
}

function bot_config(string $provider): array
{
    $configured = app_config('bots.' . $provider) ?: [];
    return is_array($configured) ? $configured : [];
}

function bot_verify_webhook(string $provider, array $payload): bool
{
    $config = bot_config($provider);
    $secret = (string) ($config['webhook_secret'] ?? '');
    if ($secret === '') {
        return true;
    }
    $headers = [
        (string) ($_SERVER['HTTP_X_BOT_SECRET'] ?? ''),
        (string) ($_SERVER['HTTP_X_VK_SECRET'] ?? ''),
        (string) ($_SERVER['HTTP_X_MAX_SECRET'] ?? ''),
        // Official Max Bot API webhook header (docs: X-Max-Bot-Api-Secret).
        (string) ($_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? ''),
        (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''),
        (string) ($_GET['token'] ?? ''),
        (string) ($payload['secret'] ?? ''),
    ];
    foreach ($headers as $value) {
        if ($value !== '' && hash_equals($secret, $value)) {
            return true;
        }
    }
    return false;
}

function bot_user_display_name(array $user): string
{
    $name = trim((string) ($user['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $first = trim((string) ($user['first_name'] ?? ''));
    $last = trim((string) ($user['last_name'] ?? ''));
    return trim($first . ($last !== '' ? ' ' . $last : ''));
}

/**
 * Normalize a one-time bot link code. Codes are 6–16 chars of A–Z / 0–9.
 */
function bot_normalize_link_code(?string $raw): ?string
{
    $code = strtoupper(trim((string) $raw));
    if ($code === '' || !preg_match('/^[A-Z0-9]{6,16}$/', $code)) {
        return null;
    }
    return $code;
}

/**
 * Extract a link code from free-form chat text (/start CODE, привязать CODE).
 */
function bot_link_code_from_text(string $text): ?string
{
    if (preg_match('/(?:\/start(?:@[A-Za-z0-9_]+)?|привязать|link)\s+([A-Z0-9]{6,16})/iu', $text, $m)) {
        return bot_normalize_link_code($m[1]);
    }
    return null;
}

/**
 * Resolve link code from provider-specific deep-link / start payloads.
 *
 * Max: `bot_started.payload` or `/start CODE`
 * Telegram: `/start CODE` (deep link ?start=)
 * VK: message `ref` from `vk.me/…?ref=CODE` (arrives with the first message)
 */
function bot_link_code_from_update(string $provider, string $text, string $callback, array $rawMessage, array $payload): ?string
{
    $fromText = bot_link_code_from_text($text);
    if ($fromText !== null) {
        return $fromText;
    }

    if ($provider === 'max') {
        $startPayload = (string) ($payload['payload'] ?? '');
        if (($payload['update_type'] ?? '') === 'bot_started' || $startPayload !== '') {
            $code = bot_normalize_link_code($startPayload !== '' ? $startPayload : $callback);
            if ($code !== null) {
                return $code;
            }
        }
    }

    if ($provider === 'vk') {
        $ref = (string) ($rawMessage['ref'] ?? $payload['object']['message']['ref'] ?? $payload['object']['ref'] ?? '');
        $code = bot_normalize_link_code($ref);
        if ($code !== null) {
            return $code;
        }
    }

    if ($provider === 'telegram') {
        // Deep-link payload may also arrive alone as callback-like data in rare clients.
        $code = bot_normalize_link_code($callback);
        if ($code !== null) {
            return $code;
        }
    }

    return null;
}

/**
 * Public bot handle used in deep links (username / screen_name / club id).
 */
function bot_provider_public_handle(string $provider, ?array $config = null): string
{
    $config ??= bot_config($provider);
    if ($provider === 'max' || $provider === 'telegram') {
        $handle = ltrim(trim((string) ($config['username'] ?? '')), '@');
        if ($handle !== '') {
            return $handle;
        }
        if ($provider === 'max') {
            return bot_max_resolve_username($config);
        }
        return '';
    }
    if ($provider === 'vk') {
        $screen = ltrim(trim((string) ($config['screen_name'] ?? '')), '@');
        if ($screen !== '') {
            return $screen;
        }
        $groupId = preg_replace('/\D+/', '', (string) ($config['group_id'] ?? '')) ?? '';
        return $groupId !== '' ? 'club' . $groupId : '';
    }
    return '';
}

/**
 * Resolve Max bot username via GET /me when config.username is empty.
 * Result is cached in-request to avoid repeated API calls.
 */
function bot_max_resolve_username(?array $config = null): string
{
    static $cache = null;
    if (is_string($cache)) {
        return $cache;
    }
    $config ??= bot_config('max');
    $token = trim((string) ($config['token'] ?? ''));
    if ($token === '') {
        return $cache = '';
    }
    $base = bot_max_api_base($config);
    $result = bot_http_json('GET', $base . '/me', null, ['Authorization' => $token]);
    if (!$result['ok']) {
        return $cache = '';
    }
    $decoded = json_decode($result['body'], true);
    $username = ltrim(trim((string) ($decoded['username'] ?? '')), '@');
    return $cache = $username;
}

/**
 * Build a one-click deep link that carries the link token as start/ref payload.
 * Returns null when the provider cannot form a deep link from config.
 */
function bot_build_deep_link(string $provider, string $token, ?array $config = null): ?string
{
    $code = bot_normalize_link_code($token);
    if ($code === null) {
        return null;
    }
    $config ??= bot_config($provider);
    $handle = bot_provider_public_handle($provider, $config);
    if ($provider === 'max') {
        if ($handle === '') {
            return null;
        }
        return 'https://max.ru/' . rawurlencode($handle) . '?start=' . rawurlencode($code);
    }
    if ($provider === 'telegram') {
        if ($handle === '') {
            return null;
        }
        return 'https://t.me/' . rawurlencode($handle) . '?start=' . rawurlencode($code);
    }
    if ($provider === 'vk') {
        if ($handle === '') {
            return null;
        }
        // VK delivers `ref` on the first message after opening vk.me/?ref=
        return 'https://vk.me/' . rawurlencode($handle) . '?ref=' . rawurlencode($code);
    }
    return null;
}

/**
 * Parse inbound webhook payload into a normalized update.
 *
 * @return array{
 *   chat_id: string,
 *   external_user_id: string,
 *   text: string,
 *   callback: string,
 *   location: array,
 *   display_name: string,
 *   link_code: ?string,
 *   raw: array
 * }
 */
function bot_parse_update(string $provider, array $payload): array
{
    if ($provider === 'vk') {
        $object = $payload['object']['message'] ?? $payload['object'] ?? [];
        if (!is_array($object)) {
            $object = [];
        }
        $text = (string) ($object['text'] ?? '');
        $chatId = (string) ($object['peer_id'] ?? $object['from_id'] ?? '');
        $externalId = (string) ($object['from_id'] ?? '');
        $geo = $object['geo']['coordinates'] ?? [];
        $callback = (string) ($object['payload'] ?? '');
        $location = is_array($geo) ? $geo : [];
        $parsed = [
            'chat_id' => $chatId,
            'external_user_id' => $externalId,
            'text' => $text,
            'callback' => $callback,
            'location' => $location,
            'display_name' => '',
            'link_code' => null,
            'raw' => $object,
        ];
        $parsed['link_code'] = bot_link_code_from_update($provider, $text, $callback, $object, $payload);
        return $parsed;
    }

    if ($provider === 'telegram') {
        $message = $payload['message'] ?? $payload['edited_message'] ?? $payload['callback_query']['message'] ?? [];
        if (!is_array($message)) {
            $message = [];
        }
        $from = $message['from'] ?? $payload['callback_query']['from'] ?? [];
        if (!is_array($from)) {
            $from = [];
        }
        $chat = $message['chat'] ?? [];
        if (!is_array($chat)) {
            $chat = [];
        }
        $text = (string) ($message['text'] ?? $message['caption'] ?? '');
        $callback = (string) ($payload['callback_query']['data'] ?? '');
        $location = [];
        if (isset($message['location']) && is_array($message['location'])) {
            $location = [
                'latitude' => $message['location']['latitude'] ?? null,
                'longitude' => $message['location']['longitude'] ?? null,
            ];
        }
        $parsed = [
            'chat_id' => (string) ($chat['id'] ?? ''),
            'external_user_id' => (string) ($from['id'] ?? ''),
            'text' => $text,
            'callback' => $callback,
            'location' => $location,
            'display_name' => bot_user_display_name($from),
            'link_code' => null,
            'raw' => $message !== [] ? $message : $payload,
        ];
        $parsed['link_code'] = bot_link_code_from_update($provider, $text, $callback, $message, $payload);
        return $parsed;
    }

    // Max Bot API
    $updateType = (string) ($payload['update_type'] ?? '');
    if ($updateType === 'bot_started') {
        $user = is_array($payload['user'] ?? null) ? $payload['user'] : [];
        $callback = (string) ($payload['payload'] ?? '');
        $chatId = (string) ($payload['chat_id'] ?? '');
        $externalId = (string) ($user['user_id'] ?? $user['id'] ?? '');
        $parsed = [
            'chat_id' => $chatId,
            'external_user_id' => $externalId,
            'text' => '',
            'callback' => $callback,
            'location' => [],
            'display_name' => bot_user_display_name($user),
            'link_code' => null,
            'raw' => $payload,
        ];
        $parsed['link_code'] = bot_link_code_from_update($provider, '', $callback, $payload, $payload);
        return $parsed;
    }

    $message = $payload['message'] ?? $payload['event']['message'] ?? [];
    if (!is_array($message)) {
        $message = [];
    }
    $sender = $message['sender'] ?? $message['from'] ?? $payload['callback']['user'] ?? $payload['user'] ?? [];
    if (!is_array($sender)) {
        $sender = [];
    }
    $recipient = $message['recipient'] ?? $message['chat'] ?? [];
    if (!is_array($recipient)) {
        $recipient = [];
    }
    $body = $message['body'] ?? [];
    if (!is_array($body)) {
        $body = [];
    }
    // Official Max shape: message.body.text, recipient.chat_id, sender.user_id
    $text = (string) ($body['text'] ?? $message['text'] ?? $payload['text'] ?? '');
    $chatId = (string) ($recipient['chat_id'] ?? $payload['chat_id'] ?? $recipient['id'] ?? $message['chat_id'] ?? '');
    $externalId = (string) ($sender['user_id'] ?? $sender['id'] ?? $message['user_id'] ?? '');
    $callback = (string) ($payload['callback']['payload'] ?? $payload['payload'] ?? '');
    $location = [];
    if (isset($message['location']) && is_array($message['location'])) {
        $location = $message['location'];
    } elseif (isset($payload['location']) && is_array($payload['location'])) {
        $location = $payload['location'];
    }
    $attachments = $body['attachments'] ?? [];
    if ($location === [] && is_array($attachments)) {
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            if (($attachment['type'] ?? '') === 'location' && is_array($attachment['location'] ?? null)) {
                $location = $attachment['location'];
                break;
            }
        }
    }
    $parsed = [
        'chat_id' => $chatId,
        'external_user_id' => $externalId,
        'text' => $text,
        'callback' => $callback,
        'location' => $location,
        'display_name' => bot_user_display_name($sender),
        'link_code' => null,
        'raw' => $message !== [] ? $message : $payload,
    ];
    $parsed['link_code'] = bot_link_code_from_update($provider, $text, $callback, $parsed['raw'], $payload);
    return $parsed;
}

/**
 * Legacy tuple extractor kept for callers/tests that still unpack 6 values.
 *
 * @return array{0:string,1:string,2:string,3:string,4:array,5:array}
 */
function bot_extract_update(string $provider, array $payload): array
{
    $parsed = bot_parse_update($provider, $payload);
    return [
        $parsed['chat_id'],
        $parsed['external_user_id'],
        $parsed['text'],
        $parsed['callback'],
        $parsed['location'],
        $parsed['raw'],
    ];
}

function bot_account_by_chat(string $provider, string $chatId): ?array
{
    return fetch_one('SELECT ba.*, u.name, u.role, u.church_id, u.group_id FROM bot_accounts ba JOIN users u ON u.id = ba.user_id WHERE ba.provider = ? AND ba.chat_id = ? LIMIT 1', [$provider, $chatId]);
}

function bot_generate_link_token(int $userId, ?string $provider = null): string
{
    $token = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    db()->prepare('INSERT INTO bot_link_tokens (user_id, token_hash, provider, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))')
        ->execute([$userId, hash('sha256', $token), $provider]);
    return $token;
}

function bot_consume_link_token(string $provider, string $code): ?array
{
    $normalized = bot_normalize_link_code($code);
    if ($normalized === null) {
        return null;
    }
    return fetch_one(
        'SELECT * FROM bot_link_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() AND (provider IS NULL OR provider = ?) LIMIT 1',
        [hash('sha256', $normalized), $provider]
    ) ?: null;
}

function bot_link_account(string $provider, string $chatId, string $externalId, string $displayName, string $code): array
{
    if ($chatId === '') {
        return ['handled_as' => 'link_failed', 'reply' => 'Не удалось определить чат для привязки. Откройте бота ещё раз по ссылке из профиля CRM.'];
    }
    $row = bot_consume_link_token($provider, $code);
    if (!$row) {
        return ['handled_as' => 'link_failed', 'reply' => 'Код привязки не найден или устарел. Нажмите «Привязать» ещё раз в профиле CRM.'];
    }
    db()->prepare('INSERT INTO bot_accounts (user_id, provider, chat_id, external_user_id, display_name, private_chat, last_seen_at) VALUES (?, ?, ?, ?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), external_user_id = VALUES(external_user_id), display_name = VALUES(display_name), private_chat = 1, last_seen_at = NOW()')
        ->execute([(int) $row['user_id'], $provider, $chatId, $externalId ?: null, $displayName ?: null]);
    db()->prepare('UPDATE bot_link_tokens SET used_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);
    audit('bot_account_linked', 'user', (int) $row['user_id'], ['provider' => $provider]);
    return ['handled_as' => 'linked', 'reply' => bot_provider_label($provider) . ' привязан к вашему аккаунту CRM. Теперь напоминания будут приходить сюда.'];
}

function bot_try_link(string $provider, string $chatId, string $externalId, string $displayName, string $text, ?string $explicitCode = null): ?array
{
    $code = $explicitCode ?? bot_link_code_from_text($text);
    if ($code === null) {
        return null;
    }
    return bot_link_account($provider, $chatId, $externalId, $displayName, $code);
}

function bot_unlinked_help_reply(string $provider): string
{
    $label = bot_provider_label($provider);
    return "Чтобы привязать {$label} к CRM, откройте «Моя учётная запись» и нажмите «Привязать {$label}». "
        . 'Можно также отправить сюда сообщение: привязать КОД';
}

function bot_haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earth = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function yandex_geocode_normalize_query(string $query): string
{
    $normalized = preg_replace('/[\p{Z}\s]+/u', ' ', trim($query));
    return trim(is_string($normalized) ? $normalized : $query);
}

function yandex_geocode_cache_key(string $query): string
{
    return hash('sha256', mb_strtolower(yandex_geocode_normalize_query($query), 'UTF-8'));
}

function yandex_geocode_config_int(string $key, int $default, int $minimum): int
{
    $value = function_exists('app_config') ? app_config($key) : null;
    if (is_numeric($value)) {
        $default = (int) $value;
    }
    return max($minimum, $default);
}

function yandex_geocode_cache_ttl(bool $negative = false): int
{
    return $negative
        ? yandex_geocode_config_int('yandex.geocoder_negative_cache_ttl', 86400, 3600)
        : yandex_geocode_config_int('yandex.geocoder_cache_ttl', 604800, 86400);
}

function yandex_geocode_http_timeout(): int
{
    return min(10, yandex_geocode_config_int('yandex.geocoder_timeout', 3, 1));
}

function yandex_geocode_lock_name(string $cacheKey): string
{
    return 'wolhg_geocode_' . substr($cacheKey, 0, 50);
}

function yandex_geocode_acquire_lock(string $cacheKey): bool
{
    try {
        $row = fetch_one('SELECT GET_LOCK(?, 2) AS locked', [yandex_geocode_lock_name($cacheKey)]);
        return (int) ($row['locked'] ?? 0) === 1;
    } catch (Throwable) {
        return false;
    }
}

function yandex_geocode_release_lock(string $cacheKey): void
{
    try {
        fetch_one('SELECT RELEASE_LOCK(?) AS released', [yandex_geocode_lock_name($cacheKey)]);
    } catch (Throwable) {
    }
}

function yandex_geocode_cached_row(string $cacheKey): ?array
{
    return fetch_one('SELECT * FROM yandex_geocode_cache WHERE cache_key = ? AND expires_at > NOW() LIMIT 1', [$cacheKey]);
}

function yandex_geocode_result_from_cache(array $cached): ?array
{
    if ($cached['latitude'] === null || $cached['longitude'] === null) {
        return null;
    }
    return [
        'latitude' => (float) $cached['latitude'],
        'longitude' => (float) $cached['longitude'],
        'address' => (string) ($cached['resolved_address'] ?: $cached['query_text']),
    ];
}

function yandex_geocode_fetch_url(string $url): ?string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => yandex_geocode_http_timeout(),
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    return is_string($raw) ? $raw : null;
}

function yandex_geocode_decode_response(?string $raw): ?array
{
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function yandex_geocode_response_has_collection(array $data): bool
{
    return is_array($data['response']['GeoObjectCollection'] ?? null);
}

function yandex_geocode_result_from_response(array $data, string $fallbackAddress): ?array
{
    $member = $data['response']['GeoObjectCollection']['featureMember'][0]['GeoObject'] ?? null;
    $pos = is_array($member) ? trim((string) ($member['Point']['pos'] ?? '')) : '';
    $parts = $pos !== '' ? preg_split('/\s+/', $pos) : [];
    if (!is_array($parts) || count($parts) < 2) {
        return null;
    }

    $lon = (float) $parts[0];
    $lat = (float) $parts[1];
    if (!is_finite($lat) || !is_finite($lon) || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        return null;
    }

    $address = trim((string) ($member['metaDataProperty']['GeocoderMetaData']['text'] ?? ''));
    return ['latitude' => $lat, 'longitude' => $lon, 'address' => $address !== '' ? $address : $fallbackAddress];
}

function yandex_geocode_store_cache(string $cacheKey, string $query, ?array $result, array $data, bool $negative): void
{
    $expiresAt = date('Y-m-d H:i:s', time() + yandex_geocode_cache_ttl($negative));
    $rawResponse = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    db()->prepare('INSERT INTO yandex_geocode_cache (cache_key, query_text, latitude, longitude, resolved_address, raw_response, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE query_text = VALUES(query_text), latitude = VALUES(latitude), longitude = VALUES(longitude), resolved_address = VALUES(resolved_address), raw_response = VALUES(raw_response), expires_at = VALUES(expires_at)')
        ->execute([
            $cacheKey,
            mb_substr($query, 0, 255),
            $result['latitude'] ?? null,
            $result['longitude'] ?? null,
            isset($result['address']) ? mb_substr((string) $result['address'], 0, 255) : null,
            is_string($rawResponse) ? $rawResponse : null,
            $expiresAt,
        ]);
}

function yandex_geocode_coords_cache_key(float $lat, float $lon): string
{
    return hash('sha256', 'coords:' . round($lat, 5) . ',' . round($lon, 5));
}

function yandex_reverse_geocode(float $lat, float $lon): ?array
{
    if (!is_finite($lat) || !is_finite($lon) || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        return null;
    }

    $query = sprintf('%.5F,%.5F', $lon, $lat);
    $key = yandex_geocode_coords_cache_key($lat, $lon);
    $cached = yandex_geocode_cached_row($key);
    if ($cached) {
        return yandex_geocode_result_from_cache($cached);
    }

    $apiKey = (string) (app_config('yandex.geocoder_key') ?: '');
    if ($apiKey === '') {
        return null;
    }

    $locked = yandex_geocode_acquire_lock($key);
    try {
        if ($locked) {
            $cached = yandex_geocode_cached_row($key);
            if ($cached) {
                return yandex_geocode_result_from_cache($cached);
            }
        }

        $url = 'https://geocode-maps.yandex.ru/1.x/?' . http_build_query([
            'apikey' => $apiKey,
            'format' => 'json',
            'geocode' => $query,
            'results' => 1,
            'lang' => 'ru_RU',
        ]);
        $data = yandex_geocode_decode_response(yandex_geocode_fetch_url($url));
        if (!$data || !yandex_geocode_response_has_collection($data)) {
            return null;
        }

        $result = yandex_geocode_result_from_response($data, '');
        yandex_geocode_store_cache($key, $query, $result, $data, $result === null);
        return $result;
    } finally {
        if ($locked) {
            yandex_geocode_release_lock($key);
        }
    }
}

function yandex_geocode(string $query): ?array
{
    $query = yandex_geocode_normalize_query($query);
    if ($query === '') {
        return null;
    }
    $key = yandex_geocode_cache_key($query);
    $cached = yandex_geocode_cached_row($key);
    if ($cached) {
        return yandex_geocode_result_from_cache($cached);
    }
    $apiKey = (string) (app_config('yandex.geocoder_key') ?: '');
    if ($apiKey === '') {
        return null;
    }

    $locked = yandex_geocode_acquire_lock($key);
    try {
        if ($locked) {
            $cached = yandex_geocode_cached_row($key);
            if ($cached) {
                return yandex_geocode_result_from_cache($cached);
            }
        }

        $url = 'https://geocode-maps.yandex.ru/1.x/?' . http_build_query(['apikey' => $apiKey, 'format' => 'json', 'geocode' => $query, 'results' => 1]);
        $data = yandex_geocode_decode_response(yandex_geocode_fetch_url($url));
        if (!$data || !yandex_geocode_response_has_collection($data)) {
            return null;
        }

        $result = yandex_geocode_result_from_response($data, $query);
        yandex_geocode_store_cache($key, $query, $result, $data, $result === null);
        return $result;
    } finally {
        if ($locked) {
            yandex_geocode_release_lock($key);
        }
    }
}

function recommend_home_group(?float $lat, ?float $lon, ?string $locationQuery = null, ?int $churchId = null): ?array
{
    $params = [];
    $where = 'g.is_active = 1';
    if ($churchId) {
        $where .= ' AND g.church_id = ?';
        $params[] = $churchId;
    }
    $groups = fetch_all("SELECT g.*, COUNT(p.id) AS people_count FROM home_groups g LEFT JOIN people p ON p.group_id = g.id AND p.deleted_at IS NULL WHERE $where GROUP BY g.id ORDER BY g.name", $params);
    $best = null;
    foreach ($groups as $group) {
        $load = (int) $group['people_count'] / max(1, (int) $group['max_people']);
        $score = $load * 5;
        if ($lat !== null && $lon !== null && $group['latitude'] !== null && $group['longitude'] !== null) {
            $score += bot_haversine($lat, $lon, (float) $group['latitude'], (float) $group['longitude']);
        } elseif ($locationQuery && ChurchCare\home_group_matches_location_query($group, $locationQuery)) {
            $score -= 3;
        }
        if ($best === null || $score < $best['score']) {
            $best = ['score' => $score, 'group' => $group];
        }
    }
    return $best['group'] ?? null;
}

function bot_create_newcomer_request(string $provider, string $externalId, string $text, array $location): array
{
    $name = 'Новый человек из ' . bot_provider_label($provider);
    $phone = null;
    $district = null;
    $address = null;
    $message = $text;
    if (preg_match('/новичок\s+([^;\n]+)(?:;\s*([^;\n]+))?(?:;\s*([^;\n]+))?/iu', $text, $m)) {
        $name = trim($m[1]) ?: $name;
        $phone = isset($m[2]) ? ChurchCare\normalize_phone(trim($m[2])) : null;
        $district = isset($m[3]) ? trim($m[3]) : null;
    }
    $lat = isset($location['latitude']) ? (float) $location['latitude'] : (isset($location['lat']) ? (float) $location['lat'] : null);
    $lon = isset($location['longitude']) ? (float) $location['longitude'] : (isset($location['lon']) ? (float) $location['lon'] : null);
    if (($lat === null || $lon === null) && preg_match('/адрес:\s*(.+)$/ium', $text, $m)) {
        $geo = yandex_geocode(trim($m[1]));
        if ($geo) {
            $lat = (float) $geo['latitude'];
            $lon = (float) $geo['longitude'];
            $address = trim((string) ($geo['address'] ?? '')) ?: trim($m[1]);
            $district = $district ?: $address;
        }
    }
    if ($address === null && $lat !== null && $lon !== null) {
        $reverse = yandex_reverse_geocode($lat, $lon);
        if ($reverse) {
            $address = trim((string) ($reverse['address'] ?? '')) ?: null;
        }
    }
    if ($address === null && $district !== null && mb_strlen($district) > 20) {
        $address = $district;
    }
    $group = recommend_home_group($lat, $lon, $district);
    $churchId = (int) ($group['church_id'] ?? app_config('default_church_id') ?: 1);
    db()->prepare('INSERT INTO participant_requests (church_id, full_name, phone, district, address, latitude, longitude, source_provider, source_external_id, recommended_group_id, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$churchId, $name, $phone ?: null, $district ?: null, $address, $lat, $lon, $provider, $externalId ?: null, $group['id'] ?? null, $message]);
    $locationHint = $address ?: $district;
    return ['handled_as' => 'newcomer_request', 'reply' => $group ? ('Заявка создана. Подходящая группа: ' . $group['name'] . ($locationHint ? ' (' . $locationHint . ').' : '.')) : 'Заявка создана. Лидер подберет домашнюю группу.'];
}

function bot_handle_action(array $account, string $payload, string $text): ?array
{
    $command = $payload !== '' ? $payload : mb_strtolower(trim($text));
    if (!preg_match('/^(called|wrote|meeting|pastor_help|позвонил|написал|назначил встречу|нужна помощь пастора)(?::|\s+)(\d+)/iu', $command, $m)) {
        return null;
    }
    $action = mb_strtolower($m[1]);
    $person = scoped_person($account, (int) $m[2]);
    if (!$person) {
        return ['handled_as' => 'action_denied', 'reply' => 'Карточка недоступна для вашего аккаунта.'];
    }
    $type = str_contains($action, 'call') || str_contains($action, 'поз') ? 'call' : (str_contains($action, 'meeting') || str_contains($action, 'встреч') ? 'meeting' : 'telegram');
    $summary = match (true) {
        str_contains($action, 'pastor') || str_contains($action, 'пастор') => 'Из чата: нужна помощь пастора',
        $type === 'call' => 'Из чата: лидер позвонил',
        $type === 'meeting' => 'Из чата: назначена встреча',
        default => 'Из чата: лидер написал',
    };
    db()->prepare('INSERT INTO contact_logs (person_id, user_id, contact_date, type, summary) VALUES (?, ?, CURDATE(), ?, ?)')
        ->execute([(int) $person['id'], (int) $account['user_id'], $type, $summary]);
    if (str_contains($action, 'pastor') || str_contains($action, 'пастор')) {
        queue_regional_help_notice((int) $person['group_id'], 'Группа просит помощи пастора', 'Лидер отметил, что нужна помощь пастора. Детали доступны в CRM.', '/person?id=' . (int) $person['id']);
    }
    return ['handled_as' => 'quick_action', 'reply' => 'Записано в историю общения.'];
}

function bot_handle_prayer(array $account, string $text): ?array
{
    if (!preg_match('/^(?:молитва|нужда|prayer)\s+([^:]+):\s*(.+)$/ius', trim($text), $m)) {
        return null;
    }
    [$personSql, $params] = accessible_person_sql($account, 'p');
    $params[] = '%' . trim($m[1]) . '%';
    $person = fetch_one("SELECT p.* FROM people p JOIN home_groups g ON g.id = p.group_id WHERE $personSql AND p.full_name LIKE ? LIMIT 1", $params);
    if (!$person) {
        return ['handled_as' => 'prayer_person_not_found', 'reply' => 'Не нашел человека по имени. Откройте карточку в CRM или уточните имя.'];
    }
    $group = fetch_one('SELECT church_id FROM home_groups WHERE id = ? LIMIT 1', [(int) $person['group_id']]);
    $churchId = (int) ($group['church_id'] ?? $account['church_id'] ?? 0) ?: null;
    db()->prepare('INSERT INTO prayer_requests (person_id, group_id, church_id, title, description, status, next_reminder_at, created_by) VALUES (?, ?, ?, ?, ?, "new", CURDATE() + INTERVAL 14 DAY, ?)')
        ->execute([(int) $person['id'], (int) $person['group_id'], $churchId, mb_substr(trim($m[2]), 0, 190), trim($m[2]), (int) $account['user_id']]);
    return ['handled_as' => 'prayer_created', 'reply' => 'Молитвенная нужда сохранена в карточке ' . $person['full_name'] . '.'];
}

function bot_handle_meeting_report(array $account, string $text): ?array
{
    if (!preg_match('/^(?:отчет|отчёт|report):\s*(.+)$/ius', trim($text), $m)) {
        return null;
    }
    $meeting = fetch_one('SELECT m.*, g.church_id FROM meetings m JOIN home_groups g ON g.id = m.group_id WHERE m.group_id = ? ORDER BY m.meeting_date DESC, m.id DESC LIMIT 1', [(int) $account['group_id']]);
    if (!$meeting) {
        return ['handled_as' => 'report_no_meeting', 'reply' => 'Не нашел последнюю встречу вашей группы.'];
    }
    $recipient = fetch_one('SELECT id FROM users WHERE role IN ("district_leader","pastor","admin","super_admin") AND church_id = ? ORDER BY FIELD(role, "district_leader","pastor","admin","super_admin") LIMIT 1', [(int) $meeting['church_id']]);
    $sentTo = $recipient['id'] ?? null;
    db()->prepare('INSERT INTO meeting_reports (meeting_id, submitted_by, summary, attendance_count, newcomers_count, pastor_help_needed, sent_to_user_id, sent_at) VALUES (?, ?, ?, 0, 0, 0, ?, NOW()) ON DUPLICATE KEY UPDATE submitted_by = VALUES(submitted_by), summary = VALUES(summary), sent_to_user_id = VALUES(sent_to_user_id), sent_at = NOW()')
        ->execute([(int) $meeting['id'], (int) $account['user_id'], trim($m[1]), $sentTo]);
    if ($sentTo) {
        queue_bot_notification((int) $sentTo, 'Отчет после встречи', 'Лидер отправил короткий отчет из чата. Детали доступны в CRM.', '/meeting-report?id=' . (int) $meeting['id']);
    }
    return ['handled_as' => 'meeting_report', 'reply' => 'Отчет сохранен и передан выше.'];
}

/**
 * Low-level HTTP JSON helper for bot API calls.
 *
 * @param array<string, string> $headers
 * @return array{ok: bool, status: int, body: string}
 */
function bot_http_json(string $method, string $url, ?array $jsonBody = null, array $headers = []): array
{
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }
    if ($jsonBody !== null) {
        $headerLines[] = 'Content-Type: application/json';
    }
    $context = stream_context_create([
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headerLines),
            'content' => $jsonBody !== null ? json_encode($jsonBody, JSON_UNESCAPED_UNICODE) : '',
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    $body = is_string($response) ? $response : '';
    $ok = $body !== '' && ($status === 0 || ($status >= 200 && $status < 300));
    return ['ok' => $ok, 'status' => $status, 'body' => $body];
}

function bot_max_api_base(?array $config = null): string
{
    $config ??= bot_config('max');
    return rtrim((string) ($config['api_url'] ?? 'https://botapi.max.ru'), '/');
}

function bot_send_chat_message(string $provider, string $chatId, string $text): bool
{
    $chatId = trim($chatId);
    $text = trim($text);
    if ($chatId === '' || $text === '') {
        return false;
    }
    $config = bot_config($provider);
    $token = trim((string) ($config['token'] ?? ''));
    if ($token === '') {
        return false;
    }

    if ($provider === 'max') {
        $base = bot_max_api_base($config);
        $result = bot_http_json('POST', $base . '/messages', [
            'chat_id' => ctype_digit($chatId) ? (int) $chatId : $chatId,
            'text' => $text,
        ], ['Authorization' => $token]);
        return $result['ok'];
    }

    if ($provider === 'vk') {
        $base = rtrim((string) ($config['api_url'] ?? 'https://api.vk.com/method'), '/');
        $url = $base . '/messages.send?' . http_build_query([
            'access_token' => $token,
            'v' => '5.199',
        ]);
        $result = bot_http_json('POST', $url, [
            'peer_id' => ctype_digit($chatId) ? (int) $chatId : $chatId,
            'random_id' => random_int(1, PHP_INT_MAX),
            'message' => $text,
        ]);
        return $result['ok'];
    }

    if ($provider === 'telegram') {
        $base = rtrim((string) ($config['api_url'] ?? 'https://api.telegram.org'), '/');
        $result = bot_http_json('POST', $base . '/bot' . rawurlencode($token) . '/sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
        ]);
        return $result['ok'];
    }

    return false;
}

function bot_handle_webhook(string $provider): void
{
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($payload)) {
        $payload = [];
    }
    if ($provider === 'vk' && ($payload['type'] ?? '') === 'confirmation') {
        echo (string) (bot_config('vk')['confirmation'] ?? 'ok');
        return;
    }
    if (!bot_verify_webhook($provider, $payload)) {
        http_response_code(403);
        echo 'forbidden';
        return;
    }
    if ($provider === 'telegram' && (($payload['message'] ?? null) === null)
        && (($payload['edited_message'] ?? null) === null)
        && (($payload['callback_query'] ?? null) === null)) {
        header('Content-Type: application/json; charset=utf-8');
        echo '{"ok":true}';
        return;
    }

    $update = bot_parse_update($provider, $payload);
    $chatId = $update['chat_id'];
    $externalId = $update['external_user_id'];
    $text = $update['text'];
    $callback = $update['callback'];
    $location = $update['location'];
    $displayName = $update['display_name'];
    $linkCode = $update['link_code'];

    $account = $chatId !== '' ? bot_account_by_chat($provider, $chatId) : null;
    $result = null;
    if (!$account) {
        $result = bot_try_link($provider, $chatId, $externalId, $displayName, $text, $linkCode);
        if (!$result && (mb_stripos($text, 'новичок') !== false || !empty($location))) {
            $result = bot_create_newcomer_request($provider, $externalId, $text, is_array($location) ? $location : []);
        }
        if (!$result && ($linkCode !== null || $text !== '' || ($payload['update_type'] ?? '') === 'bot_started')) {
            $result = ['handled_as' => 'unlinked_help', 'reply' => bot_unlinked_help_reply($provider)];
        }
        $handledAs = $result['handled_as'] ?? 'unlinked_message';
        $userId = null;
    } else {
        db()->prepare('UPDATE bot_accounts SET last_seen_at = NOW() WHERE id = ?')->execute([(int) $account['id']]);
        if ($linkCode !== null) {
            $result = bot_try_link($provider, $chatId, $externalId, $displayName, $text, $linkCode);
        }
        $result = $result
            ?? bot_handle_action($account, $callback, $text)
            ?? bot_handle_prayer($account, $text)
            ?? bot_handle_meeting_report($account, $text);
        if (!$result && (mb_stripos($text, 'новичок') !== false || !empty($location))) {
            $result = bot_create_newcomer_request($provider, $externalId, $text, is_array($location) ? $location : []);
        }
        $handledAs = $result['handled_as'] ?? 'message_saved';
        $userId = (int) $account['user_id'];
    }

    db()->prepare('INSERT INTO bot_inbound_messages (provider, chat_id, external_user_id, user_id, message_text, payload, handled_as) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$provider, $chatId, $externalId ?: null, $userId, $text ?: null, json_encode($payload, JSON_UNESCAPED_UNICODE), $handledAs]);

    $reply = (string) ($result['reply'] ?? '');
    if ($reply !== '' && $chatId !== '') {
        bot_send_chat_message($provider, $chatId, $reply);
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'message' => $reply !== '' ? $reply : 'Сообщение принято.'], JSON_UNESCAPED_UNICODE);
}

function queue_bot_notification(int $userId, string $title, string $body, string $url = '/', array $actions = []): int
{
    $accounts = fetch_all('SELECT provider FROM bot_accounts WHERE user_id = ? AND private_chat = 1', [$userId]);
    $created = 0;
    foreach ($accounts as $account) {
        db()->prepare('INSERT INTO notification_events (user_id, title, body, url, channel, actions, status) VALUES (?, ?, ?, ?, ?, ?, "pending")')
            ->execute([$userId, $title, $body, $url, $account['provider'], $actions ? json_encode($actions, JSON_UNESCAPED_UNICODE) : null]);
        $created++;
    }
    return $created;
}

function queue_pastoral_bot_notification(int $userId, int $personId, string $title, string $body, string $url): void
{
    $actions = [
        ['label' => 'позвонил', 'payload' => 'called:' . $personId],
        ['label' => 'написал', 'payload' => 'wrote:' . $personId],
        ['label' => 'назначил встречу', 'payload' => 'meeting:' . $personId],
        ['label' => 'нужна помощь пастора', 'payload' => 'pastor_help:' . $personId],
    ];
    queue_bot_notification($userId, $title, $body, $url, $actions);
}

function queue_regional_help_notice(int $groupId, string $title, string $body, string $url): void
{
    $recipients = fetch_all('SELECT DISTINCT u.id FROM users u JOIN home_groups g ON g.id = ? WHERE u.church_id = g.church_id AND u.role IN ("pastor","admin","super_admin","district_leader")', [$groupId]);
    foreach ($recipients as $recipient) {
        queue_bot_notification((int) $recipient['id'], $title, $body, $url);
    }
}

function queue_daily_pastor_digest(): int
{
    $pastors = fetch_all('SELECT id, church_id FROM users WHERE role IN ("pastor","admin","super_admin")');
    $created = 0;
    foreach ($pastors as $pastor) {
        $exists = fetch_one('SELECT id FROM notification_events WHERE user_id = ? AND title = "Ежедневная сводка заботы" AND channel IN ("max","vk","telegram") AND created_at >= CURDATE() LIMIT 1', [(int) $pastor['id']]);
        if ($exists) {
            continue;
        }
        $stats = fetch_one('SELECT COUNT(DISTINCT p.id) AS risks, COUNT(DISTINCT CASE WHEN m.pastor_help_needed = 1 AND m.meeting_date >= CURDATE() - INTERVAL 30 DAY THEN g.id END) AS help_groups FROM home_groups g LEFT JOIN people p ON p.group_id = g.id AND p.deleted_at IS NULL LEFT JOIN meetings m ON m.group_id = g.id WHERE g.church_id = ?', [(int) $pastor['church_id']]) ?: [];
        $created += queue_bot_notification((int) $pastor['id'], 'Ежедневная сводка заботы', 'Людей в зоне внимания: ' . (int) ($stats['risks'] ?? 0) . '. Групп с просьбой о помощи: ' . (int) ($stats['help_groups'] ?? 0) . '.', '/regional');
    }
    return $created;
}

function render_bot_accounts_panel(array $user): void
{
    $accounts = bot_accounts_for_user((int) $user['id']);
    $byProvider = array_column($accounts, null, 'provider');
    echo '<section class="panel"><h2>Боты уведомлений</h2><div class="list-stack">';
    foreach (bot_supported_providers() as $provider) {
        $row = $byProvider[$provider] ?? null;
        $configured = bot_provider_is_configured($provider);
        $label = bot_provider_label($provider);
        $status = $row
            ? ('привязан' . (!empty($row['display_name']) ? ': ' . $row['display_name'] : ''))
            : ($configured ? 'не привязан' : 'не настроен администратором');
        echo '<div class="list-row"><div><strong>' . e($label) . '</strong><span>' . e($status) . '</span></div>';
        if ($row) {
            echo '<form method="post" action="' . e(app_path('/bot-unlink')) . '" class="compact">' . csrf_field() . '<input type="hidden" name="provider" value="' . e($provider) . '"><button class="ghost">Отвязать</button></form>';
        } elseif ($configured) {
            $canDeepLink = bot_build_deep_link($provider, 'ABCDEFGH') !== null;
            $button = $canDeepLink ? ('Привязать ' . $label) : 'Код привязки';
            echo '<form method="post" action="' . e(app_path('/bot-link-token')) . '" class="compact">' . csrf_field() . '<input type="hidden" name="provider" value="' . e($provider) . '"><button class="primary">' . e($button) . '</button></form>';
        }
        echo '</div>';
    }
    echo '</div><p class="muted">Нажмите «Привязать» — откроется бот со встроенным кодом. Ссылка действует 30 минут. Для VK после перехода отправьте боту любое сообщение (платформа передаёт код в параметре ref).</p></section>';
}

function handle_bot_account_post(string $path, string $method, array $user): void
{
    if ($method !== 'POST') {
        return;
    }
    if ($path === '/bot-link-token') {
        verify_csrf();
        $provider = (string) ($_POST['provider'] ?? '');
        if (!bot_provider_is_supported($provider) || !bot_provider_is_configured($provider)) {
            ajax_redirect('/profile', 'Этот бот сейчас не настроен.');
        }
        $token = bot_generate_link_token((int) $user['id'], $provider);
        $deepLink = bot_build_deep_link($provider, $token);
        if ($deepLink !== null) {
            ajax_redirect($deepLink);
        }
        ajax_redirect(
            '/profile',
            'Код привязки ' . bot_provider_label($provider) . ': ' . $token
            . '. Отправьте боту в личный чат: привязать ' . $token
        );
    }
    if ($path === '/bot-unlink') {
        verify_csrf();
        $provider = (string) ($_POST['provider'] ?? '');
        if (bot_provider_is_supported($provider)) {
            db()->prepare('DELETE FROM bot_accounts WHERE user_id = ? AND provider = ?')->execute([$user['id'], $provider]);
            audit('bot_account_unlinked', 'user', (int) $user['id'], ['provider' => $provider]);
        }
        ajax_redirect('/profile', 'Бот отвязан');
    }
}
