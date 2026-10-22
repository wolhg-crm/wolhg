<?php

declare(strict_types=1);

require_once __DIR__ . '/db_host.php';

$config = [
    'name' => 'CRM Домашних групп',
    'short_name' => 'CRM ДГ',
    'description' => 'CRM для домашних групп церкви.',
    'default_church_name' => 'Примерная церковь',
    // Абсолютный URL приложения для ссылок в письмах (например https://crm.example.org).
    'url' => '',
    'timezone' => 'Europe/Moscow',
    'db' => [
        'host' => 'db',
        'port' => 3306,
        'database' => 'church_care',
        'username' => 'church',
        'password' => 'church',
    ],
    'security' => [
        'encryption_key' => 'local-dev-key',
    ],
    'webpush' => [
        'public_key' => '',
        'private_key' => '',
        'subject' => '',
    ],
    'social' => [
        'yandex' => [
            'client_id' => '',
            'client_secret' => '',
        ],
        'vk' => [
            'client_id' => '',
            'client_secret' => '',
            'service_token' => '',
        ],
    ],
    'bots' => [
        'max' => [
            'token' => '',
            'webhook_secret' => '',
            // Deep link: https://max.ru/{username}?start=CODE
            'username' => '',
            'api_url' => 'https://botapi.max.ru',
        ],
        'vk' => [
            'token' => '',
            'webhook_secret' => '',
            'confirmation' => 'ok',
            // Deep link: https://vk.me/{screen_name}?ref=CODE (or group_id → club{id})
            'screen_name' => '',
            'group_id' => '',
            'api_url' => 'https://api.vk.com/method',
        ],
        'telegram' => [
            'token' => '',
            'webhook_secret' => '',
            // Deep link: https://t.me/{username}?start=CODE (without @)
            'username' => '',
            'api_url' => 'https://api.telegram.org',
        ],
    ],
    'default_church_id' => 1,
    // Empty: resolve first non-demo church slug from DB, else "example" (see legacy_default_slug()).
    // Production operators may set an explicit slug in config/local.php.
    'legacy_default_slug' => '',
    'yandex' => [
        'maps_api_key' => '',
        'geocoder_key' => '',
        'geocoder_cache_ttl' => 7 * 24 * 60 * 60,
        'geocoder_negative_cache_ttl' => 24 * 60 * 60,
        'geocoder_timeout' => 3,
    ],
    'mail' => [
        // 'mail' = PHP mail(); 'smtp' = SMTP (нужно для Timeweb и большинства shared-хостингов).
        'driver' => 'mail',
        'from' => 'crm@localhost',
        'smtp' => [
            'host' => 'smtp.timeweb.ru',
            'port' => 465,
            'encryption' => 'ssl', // ssl | tls | none
            'username' => '',
            'password' => '',
            'timeout' => 20,
        ],
    ],
    'mcp' => [
        // Streamable HTTP endpoint /mcp (Bearer wolhg_ak_…). false — полностью отключить.
        // Заморожено: /mcp, OAuth и UI API-ключей в профиле/гайде скрыты. Включение — config/local.php.
        'enabled' => false,
        'oauth' => [
            // OAuth 2.1 для ChatGPT + Cursor (RFC 9728, RFC 8414, PKCE S256, DCR).
            'enabled' => true,
            // Client ID Metadata Document (CIMD) fetch — выключен по умолчанию.
            'advertise_cimd' => false,
            // Время жизни access token (секунды).
            'access_ttl' => 3600,
            // Время жизни refresh token (секунды).
            'refresh_ttl' => 2592000,
        ],
    ],
    'migrate' => [
        // Apply pending database/migrations on web bootstrap (MySQL GET_LOCK).
        'auto' => true,
        // Optional explicit token for POST /system/migrate (CI). Empty → HMAC of encryption_key.
        'token' => '',
    ],
];

$localConfig = __DIR__ . '/local.php';
if (is_file($localConfig)) {
    $local = require $localConfig;
    if (!is_array($local)) {
        throw new RuntimeException('config/local.php must return an array.');
    }

    $config = array_replace_recursive($config, $local);
}

$config['db']['host'] = resolve_db_host((string) ($config['db']['host'] ?? 'localhost'));

return $config;
