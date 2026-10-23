<?php

declare(strict_types=1);

return [
    // Базовый URL сайта для ссылок в email (обязателен на production / Timeweb).
    'url' => 'https://your-domain.ru',
    'timezone' => 'Europe/Moscow',
    // Опционально: явный slug для редиректов со старых URL без /{slug}/.
    // Пустое значение → первая не-демо церковь из БД, иначе "example".
    // 'legacy_default_slug' => 'example',
    // 'default_church_name' => 'Название вашей церкви',
    'db' => [
        // Docker: 'db'. Shared hosting (Timeweb и аналоги): 'localhost'.
        // Если панель хостинга даёт отдельный hostname БД — укажите его вместо localhost.
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'your_database',
        'username' => 'your_user',
        'password' => 'your_password',
        // Опционально: unix-сокет MySQL (если хостер требует вместо TCP).
        'unix_socket' => '',
        'connect_timeout' => 5,
        'connect_retries' => 4,
    ],
    'security' => [
        'encryption_key' => 'replace-with-production-secret',
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
    'yandex' => [
        'maps_api_key' => '',
        'geocoder_key' => '',
        'geocoder_cache_ttl' => 7 * 24 * 60 * 60,
        'geocoder_negative_cache_ttl' => 24 * 60 * 60,
        'geocoder_timeout' => 3,
    ],
    // Timeweb: создайте ящик на домене и заполните SMTP.
    'mail' => [
        'driver' => 'smtp',
        'from' => 'crm@your-domain.ru',
        'smtp' => [
            'host' => 'smtp.timeweb.ru',
            'port' => 465,
            'encryption' => 'ssl', // 465+ssl или 587+tls
            'username' => 'crm@your-domain.ru',
            'password' => 'mailbox-password',
            'timeout' => 20,
        ],
    ],
    'mcp' => [
        // false — отключить /mcp, OAuth и UI API-ключей в профиле.
        // По умолчанию выключено (заморозка). Включить: true.
        'enabled' => false,
        'oauth' => [
            // OAuth 2.1 для ChatGPT + Cursor. true по умолчанию если mcp.enabled = true.
            'enabled' => true,
            'advertise_cimd' => false,
            'access_ttl' => 3600,
            'refresh_ttl' => 2592000,
        ],
    ],
];
