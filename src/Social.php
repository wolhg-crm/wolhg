<?php

declare(strict_types=1);

require_once __DIR__ . '/YandexAddress.php';

function social_provider_defaults(string $provider): array
{
    return [
        'yandex' => [
            'label' => 'Яндекс ID',
            'auth_url' => 'https://oauth.yandex.ru/authorize',
            'token_url' => 'https://oauth.yandex.ru/token',
            'profile_url' => 'https://login.yandex.ru/info?format=json',
            'scopes' => 'login:info login:address.all.read',
        ],
        'vk' => [
            'label' => 'VK ID',
            'auth_url' => 'https://id.vk.ru/authorize',
            'token_url' => 'https://id.vk.ru/oauth2/auth',
            'profile_url' => 'https://id.vk.ru/oauth2/user_info',
            'scopes' => 'vkid.personal_info email',
        ],
    ][$provider] ?? [];
}

function social_normalize_yandex_scopes(string $scopes): string
{
    $items = preg_split('/\s+/', trim($scopes));
    $items = is_array($items) ? array_values(array_unique(array_filter($items))) : [];

    if (in_array('login:address.all.read', $items, true)) {
        $items = array_values(array_filter($items, static fn (string $scope): bool => $scope !== 'login:address.home_work.read'));
    }

    return implode(' ', $items);
}

function social_normalize_vk_scopes(string $scopes): string
{
    $items = preg_split('/\s+/', trim($scopes));
    $items = is_array($items) ? array_values(array_unique(array_filter($items))) : [];

    foreach (['vkid.personal_info', 'email'] as $requiredScope) {
        if (!in_array($requiredScope, $items, true)) {
            $items[] = $requiredScope;
        }
    }

    return implode(' ', $items);
}

function social_provider_config(string $provider): ?array
{
    if (!in_array($provider, ['yandex', 'vk'], true)) {
        return null;
    }

    $defaults = social_provider_defaults($provider);
    $configured = app_config('social.' . $provider) ?: [];
    $config = array_merge($defaults, is_array($configured) ? $configured : []);
    $config['client_id'] = (string) ($config['client_id'] ?? '');
    $config['client_secret'] = (string) ($config['client_secret'] ?? '');
    if ($provider === 'yandex') {
        $config['scopes'] = social_normalize_yandex_scopes((string) ($config['scopes'] ?? $defaults['scopes']));
    } elseif ($provider === 'vk') {
        $config['scopes'] = social_normalize_vk_scopes((string) ($config['scopes'] ?? $defaults['scopes']));
    }

    return $config;
}

function social_provider_ready(string $provider): bool
{
    $config = social_provider_config($provider);
    return $config !== null && $config['client_id'] !== '' && !empty($config['auth_url']);
}

function social_pkce_verifier(): string
{
    // RFC 7636: 43–128 chars from [A-Z] / [a-z] / [0-9] / "-" / "." / "_" / "~"
    return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
}

function social_pkce_challenge(string $verifier): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
}

/**
 * @return array<string, mixed>
 */
function social_oauth_callback_params(): array
{
    $payloadRaw = trim((string) ($_GET['payload'] ?? ''));
    if ($payloadRaw !== '') {
        $decoded = json_decode($payloadRaw, true);
        if (!is_array($decoded)) {
            $decoded = json_decode(urldecode($payloadRaw), true);
        }
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [
        'code' => $_GET['code'] ?? null,
        'state' => $_GET['state'] ?? null,
        'device_id' => $_GET['device_id'] ?? null,
        'access_token' => $_GET['access_token'] ?? null,
        'id_token' => $_GET['id_token'] ?? null,
        'error' => $_GET['error'] ?? null,
        'error_description' => $_GET['error_description'] ?? null,
    ];
}

function social_safe_return_to(string $returnTo, string $fallback = '/'): string
{
    $returnTo = trim($returnTo);
    if ($returnTo === '' || str_starts_with($returnTo, '//')) {
        return $fallback;
    }

    if (str_starts_with($returnTo, 'http://') || str_starts_with($returnTo, 'https://')) {
        $parts = parse_url($returnTo);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $currentHost = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]);
        if ($host === '' || $currentHost === '' || $host !== $currentHost) {
            return $fallback;
        }

        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? ('?' . $parts['query']) : '';
        return ($path !== '' && str_starts_with($path, '/')) ? ($path . $query) : $fallback;
    }

    return str_starts_with($returnTo, '/') ? $returnTo : $fallback;
}

function social_join_session_clear(): void
{
    unset($_SESSION['social_join_profile']);
}

/**
 * @return array<string, mixed>|null
 */
function social_join_profile(): ?array
{
    $profile = $_SESSION['social_join_profile'] ?? null;
    if (!is_array($profile) || (int) ($profile['expires_at'] ?? 0) < time()) {
        social_join_session_clear();
        return null;
    }

    $provider = (string) ($profile['provider'] ?? '');
    $externalId = (string) ($profile['external_user_id'] ?? '');
    if (!in_array($provider, ['yandex', 'vk'], true) || $externalId === '') {
        social_join_session_clear();
        return null;
    }

    return $profile;
}

/**
 * @param array<string, mixed> $profile
 */
function social_join_session_store(string $provider, array $profile): void
{
    $_SESSION['social_join_profile'] = [
        'provider' => $provider,
        'provider_label' => social_provider_defaults($provider)['label'] ?? $provider,
        'external_user_id' => (string) ($profile['external_user_id'] ?? ''),
        'profile_name' => (string) ($profile['profile_name'] ?? ''),
        'first_name' => (string) ($profile['first_name'] ?? ''),
        'last_name' => (string) ($profile['last_name'] ?? ''),
        'email' => (string) ($profile['email'] ?? ''),
        'phone' => (string) ($profile['phone'] ?? ''),
        'addresses' => is_array($profile['addresses'] ?? null) ? $profile['addresses'] : [],
        'expires_at' => time() + 1800,
    ];
}

function absolute_url(string $path): string
{
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (request_is_https() ? 'https://' : 'http://') . $host . $path;
}

function social_start(string $provider, string $mode = 'login', string $returnTo = '/'): never
{
    $provider = in_array($provider, ['yandex', 'vk'], true) ? $provider : '';
    $mode = in_array($mode, ['login', 'link', 'join'], true) ? $mode : 'login';
    $fallback = match ($mode) {
        'link' => '/profile',
        'join' => '/homegroup/join',
        default => '/',
    };
    $returnTo = social_safe_return_to($returnTo, $fallback);
    $user = current_user();
    if ($mode === 'link' && !$user) {
        flash('Сначала войдите по логину и паролю, затем привяжите сервис.');
        redirect('/login');
    }

    $config = $provider ? social_provider_config($provider) : null;
    if (!$config || !social_provider_ready($provider)) {
        flash('Вход через сервис пока не настроен администратором.');
        redirect($mode === 'link' ? '/profile' : ($mode === 'join' ? $returnTo : '/login'));
    }

    $state = bin2hex(random_bytes(24));
    $codeVerifier = $provider === 'vk' ? social_pkce_verifier() : null;
    db()->prepare('INSERT INTO oauth_states (state, provider, mode, user_id, return_to, code_verifier, expires_at) VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))')
        ->execute([$state, $provider, $mode, $user['id'] ?? null, $returnTo, $codeVerifier]);

    $args = [
        'client_id' => $config['client_id'],
        'redirect_uri' => absolute_url('/social/callback'),
        'response_type' => 'code',
        'scope' => $config['scopes'] ?? '',
        'state' => $state,
    ];
    if ($provider === 'vk') {
        $args['code_challenge'] = social_pkce_challenge((string) $codeVerifier);
        $args['code_challenge_method'] = 'S256';
    }

    $authorizeUrl = (string) $config['auth_url'] . '?' . http_build_query($args);
    // AJAX fetch cannot follow cross-origin OAuth redirects (CORS). Tell the client to navigate.
    if (wants_ajax()) {
        json_response(['ok' => true, 'redirect' => $authorizeUrl, 'navigate' => true]);
    }
    redirect($authorizeUrl);
}

function social_http_json(string $url, array $options = []): ?array
{
    $method = $options['method'] ?? 'GET';
    $headers = $options['headers'] ?? [];
    $body = $options['body'] ?? null;
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body,
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function social_exchange_code_data(string $provider, array $state, string $code, string $deviceId = ''): ?array
{
    $config = social_provider_config($provider);
    if (!$config || empty($config['token_url'])) {
        return null;
    }
    $body = [
        'grant_type' => 'authorization_code',
        'client_id' => $config['client_id'],
        'redirect_uri' => absolute_url('/social/callback'),
        'code' => $code,
    ];
    if ($provider === 'yandex') {
        $body['client_secret'] = $config['client_secret'];
    }
    if ($provider === 'vk') {
        $body['code_verifier'] = (string) ($state['code_verifier'] ?? '');
        $body['device_id'] = $deviceId;
        $body['state'] = (string) ($state['state'] ?? '');
        if (!empty($config['client_secret'])) {
            $body['client_secret'] = $config['client_secret'];
        }
    }

    $data = social_http_json((string) $config['token_url'], [
        'method' => 'POST',
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        'body' => http_build_query($body),
    ]);
    if (!is_array($data) || empty($data['access_token'])) {
        return null;
    }

    return $data;
}

function social_exchange_code(string $provider, array $state, string $code, string $deviceId = ''): ?string
{
    $data = social_exchange_code_data($provider, $state, $code, $deviceId);
    return isset($data['access_token']) ? (string) $data['access_token'] : null;
}

/**
 * @param array<string, mixed> $data
 * @param list<string> $keys
 */
function social_first_string(array $data, array $keys): string
{
    foreach ($keys as $key) {
        $value = $data[$key] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
    }

    return '';
}

/**
 * @param array<string, mixed> $data
 */
function social_profile_email(array $data): string
{
    $email = social_first_string($data, ['default_email', 'email']);
    if ($email === '' && isset($data['emails']) && is_array($data['emails'])) {
        foreach ($data['emails'] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $email = trim($candidate);
                break;
            }
        }
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

/**
 * @param array<string, mixed> $data
 */
function social_profile_phone(array $data): string
{
    $phone = social_first_string($data, ['phone', 'phone_number', 'mobile_phone']);
    if ($phone === '' && isset($data['default_phone']) && is_array($data['default_phone'])) {
        $phone = social_first_string($data['default_phone'], ['number', 'phone_number', 'e164']);
    }
    if ($phone === '' && isset($data['phones']) && is_array($data['phones'])) {
        foreach ($data['phones'] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $phone = trim($candidate);
                break;
            }
            if (is_array($candidate)) {
                $phone = social_first_string($candidate, ['number', 'phone_number', 'e164']);
                if ($phone !== '') {
                    break;
                }
            }
        }
    }

    return $phone;
}

function social_base64url_decode(string $value): ?string
{
    $remainder = strlen($value) % 4;
    if ($remainder > 0) {
        $value .= str_repeat('=', 4 - $remainder);
    }

    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return is_string($decoded) ? $decoded : null;
}

/**
 * @return array<string, mixed>
 */
function social_jwt_payload(string $jwt): array
{
    $parts = explode('.', $jwt);
    if (count($parts) < 2 || $parts[1] === '') {
        return [];
    }

    $decoded = social_base64url_decode($parts[1]);
    if ($decoded === null || $decoded === '') {
        return [];
    }

    $payload = json_decode($decoded, true);
    return is_array($payload) ? $payload : [];
}

/**
 * @param array<string, mixed> $data
 * @param array<string, mixed> $tokenData
 * @return array<string, mixed>
 */
function social_profile_payload(string $provider, array $data, array $tokenData = []): array
{
    if ($provider === 'vk' && isset($data['user']) && is_array($data['user'])) {
        $topLevel = $data;
        unset($topLevel['user']);
        $data = array_merge($data['user'], $topLevel);
    }

    $claims = isset($tokenData['id_token']) && is_string($tokenData['id_token'])
        ? social_jwt_payload($tokenData['id_token'])
        : [];
    foreach ($claims as $key => $value) {
        if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            $data[$key] = $value;
        }
    }

    return $data;
}

function social_load_profile(string $provider, string $token, array $tokenData = []): ?array
{
    $config = social_provider_config($provider);
    if (!$config || empty($config['profile_url'])) {
        return null;
    }
    $url = (string) $config['profile_url'];
    if ($provider === 'vk') {
        $data = social_http_json($url, [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query([
                'client_id' => $config['client_id'],
                'access_token' => $token,
            ]),
        ]);
    } else {
        $data = social_http_json($url, ['headers' => ['Authorization' => 'Bearer ' . $token]]);
    }
    if (!$data) {
        return null;
    }
    $data = social_profile_payload($provider, $data, $tokenData);
    $externalId = (string) ($data['id'] ?? $data['user_id'] ?? $data['sub'] ?? '');
    if ($externalId === '') {
        return null;
    }
    $firstName = social_first_string($data, ['first_name', 'given_name']);
    $lastName = social_first_string($data, ['last_name', 'family_name']);
    $name = social_first_string($data, ['name', 'real_name', 'display_name']);
    if ($name === '') {
        $name = trim($firstName . ' ' . $lastName);
    }
    $profile = [
        'external_user_id' => $externalId,
        'profile_name' => $name ?: null,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => social_profile_email($data),
        'phone' => social_profile_phone($data),
        'addresses' => [],
    ];
    if ($provider === 'yandex') {
        $addresses = yandex_address_normalize_profile($data);
        if ($addresses !== []) {
            $profile['addresses'] = $addresses;
        }
    }

    return $profile;
}

/**
 * @param list<array<string, mixed>> $addresses
 */
function social_addresses_json(array $addresses): ?string
{
    if ($addresses === []) {
        return null;
    }

    $encoded = json_encode($addresses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($encoded) ? $encoded : null;
}

function social_save_identity(int $userId, string $provider, array $profile): void
{
    $addressesJson = social_addresses_json($profile['addresses'] ?? []);
    db()->prepare('INSERT INTO social_identities (user_id, provider, external_user_id, profile_name, addresses_json) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), profile_name = VALUES(profile_name), addresses_json = VALUES(addresses_json), linked_at = NOW()')
        ->execute([$userId, $provider, $profile['external_user_id'], $profile['profile_name'], $addressesJson]);
}

/**
 * @param list<array<string, mixed>> $addresses
 */
function social_sync_yandex_address_to_person(int $userId, array $addresses): void
{
    if ($addresses === []) {
        return;
    }

    $line = yandex_address_formatted_line($addresses[0]);
    if ($line === '') {
        return;
    }

    $person = fetch_one('SELECT id, address FROM people WHERE user_id = ? AND deleted_at IS NULL LIMIT 1', [$userId]);
    if (!$person || trim((string) ($person['address'] ?? '')) !== '') {
        return;
    }

    db()->prepare('UPDATE people SET address = ? WHERE id = ?')->execute([$line, (int) $person['id']]);
}

/**
 * @return list<array<string, mixed>>
 */
function social_yandex_addresses_for_user(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $row = fetch_one('SELECT addresses_json FROM social_identities WHERE user_id = ? AND provider = ? LIMIT 1', [$userId, 'yandex']);
    if (!$row) {
        return [];
    }

    return yandex_address_decode_stored($row['addresses_json'] ?? null);
}

function social_callback(): never
{
    $params = social_oauth_callback_params();
    $stateValue = trim((string) ($params['state'] ?? $_GET['state'] ?? ''));
    $state = $stateValue !== '' ? fetch_one('SELECT * FROM oauth_states WHERE state = ? AND expires_at > NOW() LIMIT 1', [$stateValue]) : null;
    if (!$state) {
        flash('Не удалось проверить вход через сервис: устаревший state.');
        redirect('/login');
    }
    db()->prepare('DELETE FROM oauth_states WHERE state = ?')->execute([$stateValue]);
    $provider = (string) $state['provider'];

    $error = trim((string) ($params['error'] ?? ''));
    if ($error !== '') {
        $description = trim((string) ($params['error_description'] ?? $error));
        flash('Вход через сервис отклонён: ' . $description);
        redirect((string) ($state['mode'] === 'link' ? '/profile' : ($state['mode'] === 'join' ? social_safe_return_to((string) ($state['return_to'] ?? ''), '/homegroup/join') : '/login')));
    }

    $tokenData = $params;
    $token = trim((string) ($params['access_token'] ?? ''));
    if ($token === '') {
        $code = trim((string) ($params['code'] ?? ''));
        $deviceId = trim((string) ($params['device_id'] ?? ''));
        $exchangeData = $code !== '' ? social_exchange_code_data($provider, $state, $code, $deviceId) : null;
        $tokenData = is_array($exchangeData) ? $exchangeData : $tokenData;
        $token = is_array($exchangeData) ? trim((string) ($exchangeData['access_token'] ?? '')) : '';
    }
    $profile = $token !== '' ? social_load_profile($provider, $token, $tokenData) : null;
    if (!$profile) {
        flash('Сервис не вернул подтвержденный идентификатор пользователя.');
        redirect((string) ($state['mode'] === 'link' ? '/profile' : ($state['mode'] === 'join' ? social_safe_return_to((string) ($state['return_to'] ?? ''), '/homegroup/join') : '/login')));
    }

    if ($state['mode'] === 'join') {
        social_join_session_store($provider, $profile);
        flash('Данные из ' . social_provider_defaults($provider)['label'] . ' подтверждены. Проверьте заявку и отправьте её.');
        redirect(social_safe_return_to((string) ($state['return_to'] ?? ''), '/homegroup/join'));
    }

    if ($state['mode'] === 'link') {
        $userId = (int) ($state['user_id'] ?? 0);
        if ($userId <= 0) {
            flash('Привязка доступна только после входа.');
            redirect('/login');
        }
        $owner = fetch_one('SELECT user_id FROM social_identities WHERE provider = ? AND external_user_id = ? LIMIT 1', [$provider, $profile['external_user_id']]);
        if ($owner && (int) $owner['user_id'] !== $userId) {
            flash('Этот профиль уже привязан к другой учетной записи.');
            redirect('/profile');
        }
        social_save_identity($userId, $provider, $profile);
        if ($provider === 'yandex') {
            social_sync_yandex_address_to_person($userId, $profile['addresses'] ?? []);
        }
        audit('social_identity_linked', 'user', $userId, ['provider' => $provider]);
        $message = 'Вход через ' . social_provider_defaults($provider)['label'] . ' привязан.';
        if ($provider === 'yandex' && ($profile['addresses'] ?? []) !== []) {
            $message .= ' Адреса из профиля сохранены.';
        }
        flash($message);
        redirect('/profile');
    }

    $identity = fetch_one('SELECT * FROM social_identities WHERE provider = ? AND external_user_id = ? LIMIT 1', [$provider, $profile['external_user_id']]);
    if (!$identity) {
        flash('Профиль сервиса найден, но еще не привязан к аккаунту CRM. Войдите по логину и привяжите его в профиле.');
        redirect('/login');
    }

    if ($provider === 'yandex' && ($profile['addresses'] ?? []) !== []) {
        social_save_identity((int) $identity['user_id'], $provider, $profile);
        social_sync_yandex_address_to_person((int) $identity['user_id'], $profile['addresses'] ?? []);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $identity['user_id'];
    audit('social_login', 'user', (int) $identity['user_id'], ['provider' => $provider]);
    redirect(social_safe_return_to((string) ($state['return_to'] ?? ''), '/'));
}

function social_provider_icon_svg(string $provider): string
{
    if ($provider === 'yandex') {
        return '<span class="social-sdk-button__icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="12" fill="#fc3f1d"/><path d="M13.1 18.8h-2.2v-5.7L6.8 5.2h2.5l2.8 5.7 2.8-5.7h2.4l-4.2 7.9v5.7Z" fill="#fff"/></svg></span>';
    }
    if ($provider === 'vk') {
        return '<span class="social-sdk-button__icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><rect width="24" height="24" rx="6" fill="#fff"/><path d="M12.8 16.7c-5.2 0-8.1-3.5-8.2-9.4h2.6c.1 4.3 2 6.1 3.5 6.5V7.3h2.5v3.7c1.5-.2 3-1.8 3.5-3.7h2.5c-.4 2.3-2.2 3.9-3.5 4.5 1.3.5 3.4 1.9 4.2 4.9h-2.8c-.5-1.8-1.9-3.1-3.9-3.4v3.4h-.4Z" fill="#0077ff"/></svg></span>';
    }

    return '';
}

function social_provider_action_label(string $provider, string $mode): string
{
    $label = social_provider_defaults($provider)['label'] ?? $provider;
    return $mode === 'link' ? ('Привязать ' . $label) : ('Войти с ' . $label);
}

function render_social_provider_button(string $provider, ?string $href, string $mode = 'login'): string
{
    $label = social_provider_action_label($provider, $mode);
    $class = 'social-sdk-button social-sdk-button--' . e($provider);
    $content = social_provider_icon_svg($provider) . '<span class="social-sdk-button__text">' . e($label) . '</span>';

    if ($href === null || $href === '') {
        return '<span class="' . $class . ' is-disabled" aria-disabled="true" title="OAuth-приложение не настроено">' . $content . '</span>';
    }

    return '<a class="' . $class . '" data-no-ajax href="' . e($href) . '">' . $content . '</a>';
}

function social_start_url(string $provider, string $mode = 'login', ?string $returnTo = null): string
{
    $params = ['provider' => $provider, 'mode' => $mode];
    if ($returnTo !== null && $returnTo !== '') {
        $params['return_to'] = $returnTo;
    }

    return '/social/start?' . http_build_query($params);
}

function render_social_login_buttons(string $mode = 'login', ?string $returnTo = null): string
{
    $html = '<div class="social-login-actions">';
    foreach (['yandex', 'vk'] as $provider) {
        $href = social_provider_ready($provider) ? social_start_url($provider, $mode, $returnTo) : null;
        $html .= render_social_provider_button($provider, $href, $mode);
    }
    return $html . '</div>';
}

function render_social_join_choice(string $returnTo): string
{
    $html = '<section class="social-join-choice" aria-labelledby="social-join-title">';
    $html .= '<h2 id="social-join-title">Присоединиться через Яндекс ID или VK ID</h2>';
    $html .= '<div class="social-join-choice__actions">';
    $hasReadyProvider = false;
    foreach (['yandex', 'vk'] as $provider) {
        $href = social_provider_ready($provider) ? social_start_url($provider, 'join', $returnTo) : null;
        $hasReadyProvider = $hasReadyProvider || $href !== null;
        $html .= render_social_provider_button($provider, $href, 'login');
    }
    $html .= '</div>';
    if (!$hasReadyProvider) {
        $html .= '<p class="muted social-join-choice__status">Вход через Яндекс ID и VK ID появится после настройки OAuth Client ID/Secret на сервере.</p>';
    }
    $html .= '</section>';

    return $html;
}

function render_yandex_address_picker(array $addresses, string $context = 'person', ?string $personHref = null): void
{
    if ($addresses === []) {
        return;
    }

    $encoded = json_encode(
        $addresses,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
    );
    if (!is_string($encoded) || $encoded === '') {
        return;
    }

    $canFill = $context === 'person';
    echo '<div class="yandex-address-picker" data-yandex-address-picker data-yandex-address-context="' . e($context) . '"';
    if ($personHref) {
        echo ' data-person-href="' . e($personHref) . '"';
    }
    echo '>';
    echo '<script type="application/json" data-yandex-addresses-json>' . $encoded . '</script>';
    echo '<p class="muted yandex-address-picker__label">Адрес из Яндекс ID</p>';

    if (!$canFill) {
        echo '<ul class="yandex-address-picker__list">';
        foreach ($addresses as $address) {
            $label = yandex_address_formatted_line($address);
            if ($label === '') {
                $label = (string) ($address['label'] ?? 'Адрес');
            }
            echo '<li>' . e($label) . '</li>';
        }
        echo '</ul>';
        if ($context === 'join') {
            echo '<p class="muted">Первый адрес будет сохранён в заявке вместе с подтверждённым Яндекс ID.</p>';
        } elseif ($personHref) {
            echo '<p class="muted">Чтобы вручную подставить адрес в карточку, откройте <a href="' . e($personHref) . '">карточку человека</a>.</p>';
        } else {
            echo '<p class="muted">Если у учётной записи есть карточка человека с пустым адресом, первый адрес подставится автоматически при привязке.</p>';
        }
        echo '</div>';
        return;
    }

    echo '<div class="yandex-address-picker__controls">';
    if (count($addresses) > 1) {
        echo '<select class="yandex-address-picker__select" aria-label="Выбор адреса из Яндекс ID">';
        foreach ($addresses as $index => $address) {
            $label = (string) ($address['label'] ?? ('Адрес ' . ($index + 1)));
            echo '<option value="' . (int) $index . '">' . e($label) . '</option>';
        }
        echo '</select>';
    }
    echo '<button type="button" class="ghost yandex-address-picker__fill">Заполнить адрес</button>';
    echo '</div><p class="address-geolocation__status yandex-address-picker__status" role="status" aria-live="polite" hidden></p></div>';
}

function render_social_accounts_panel(array $user): void
{
    $linked = fetch_all('SELECT * FROM social_identities WHERE user_id = ? ORDER BY provider', [$user['id']]);
    $byProvider = array_column($linked, null, 'provider');
    $yandexAddresses = social_yandex_addresses_for_user((int) $user['id']);
    $person = person_linked_to_user((int) $user['id']);
    $personHref = $person ? ('/person?id=' . (int) $person['id']) : null;
    echo '<section class="panel"><h2>Вход через Яндекс ID и VK ID</h2><div class="list-stack">';
    foreach (['yandex', 'vk'] as $provider) {
        $label = social_provider_defaults($provider)['label'];
        $row = $byProvider[$provider] ?? null;
        echo '<div class="list-row"><div><strong>' . e($label) . '</strong><span>' . e($row ? ('привязан: ' . ($row['profile_name'] ?: $row['external_user_id'])) : 'не привязан') . '</span></div>';
        if ($row) {
            echo '<form method="post" action="' . e(app_path('/social-unlink')) . '" class="compact">' . csrf_field() . '<input type="hidden" name="provider" value="' . e($provider) . '"><button class="ghost">Отвязать</button></form>';
        } else {
            $href = social_provider_ready($provider) ? social_start_url($provider, 'link') : null;
            echo '<div class="social-link-actions">' . render_social_provider_button($provider, $href, 'link') . '</div>';
        }
        echo '</div>';
    }
    echo '</div>';
    if ($yandexAddresses !== []) {
        echo '<p class="muted">При привязке Яндекс ID адреса из профиля сохраняются в CRM. Если в карточке человека адрес пустой, подставится первый адрес автоматически.</p>';
        render_yandex_address_picker($yandexAddresses, 'profile', $personHref);
    } else {
        echo '<p class="muted">Для импорта адресов привяжите Яндекс ID с правом login:address.all.read в настройках OAuth-приложения.</p>';
    }
    echo '</section>';
}

function handle_social_account_post(string $path, string $method, array $user): void
{
    if ($method !== 'POST' || $path !== '/social-unlink') {
        return;
    }
    verify_csrf();
    $provider = in_array($_POST['provider'] ?? '', ['yandex', 'vk'], true) ? (string) $_POST['provider'] : '';
    if ($provider !== '') {
        db()->prepare('DELETE FROM social_identities WHERE user_id = ? AND provider = ?')->execute([$user['id'], $provider]);
        audit('social_identity_unlinked', 'user', (int) $user['id'], ['provider' => $provider]);
    }
    ajax_redirect('/profile', 'Вход через сервис отвязан');
}

function handle_geocode_reverse(): never
{
    verify_csrf();
    if (function_exists('demo_geocoder_allowed') && !demo_geocoder_allowed()) {
        json_response(['ok' => false, 'error' => 'Геокодер в демо отключён.'], 403);
    }
    if (!throttle_action('geocode-reverse:' . ($_SERVER['REMOTE_ADDR'] ?? 'local'), 30, 600)) {
        json_response(['ok' => false, 'error' => 'Слишком много запросов к геокодеру. Попробуйте позже.'], 429);
    }
    if (!geocoder_ready()) {
        json_response(['ok' => false, 'error' => 'Геокодер не настроен администратором.'], 503);
    }

    $lat = isset($_POST['lat']) ? (float) str_replace(',', '.', (string) $_POST['lat']) : 0.0;
    $lon = isset($_POST['lon']) ? (float) str_replace(',', '.', (string) $_POST['lon']) : 0.0;
    if (!is_finite($lat) || !is_finite($lon) || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        json_response(['ok' => false, 'error' => 'Некорректные координаты.'], 422);
    }

    $result = yandex_reverse_geocode($lat, $lon);
    if (!$result || trim((string) ($result['address'] ?? '')) === '') {
        json_response(['ok' => false, 'error' => 'Яндекс Геокодер не нашёл адрес по координатам.'], 502);
    }

    json_response([
        'ok' => true,
        'address' => (string) $result['address'],
        'latitude' => (float) $result['latitude'],
        'longitude' => (float) $result['longitude'],
    ]);
}
