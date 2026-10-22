<?php

declare(strict_types=1);

/**
 * Streamable HTTP MCP endpoint (stateless), patterned after smmpublisher's embedded /mcp.
 * Protocol: JSON-RPC 2.0 over POST /mcp with Bearer API key.
 */

function mcp_is_enabled(): bool
{
    $cfg = app_config('mcp.enabled');
    // Fail-closed: missing/empty config keeps MCP frozen.
    if ($cfg === null || $cfg === '') {
        return false;
    }
    return (bool) $cfg;
}

function mcp_protocol_version(): string
{
    return '2025-03-26';
}

function mcp_server_info(): array
{
    return [
        'name' => 'wolhg',
        'version' => '1.0.0',
        'title' => (string) (app_config('name') ?: 'CRM Домашних групп'),
    ];
}

function mcp_bearer_token_from_request(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers ?: [] as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }
    if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m) === 1) {
        return $m[1];
    }
    return '';
}

/**
 * Entry point for public/index.php early route.
 */
function mcp_handle_http_request(): void
{
    if (!mcp_is_enabled()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'MCP отключён'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Accept, Authorization, Content-Type, Last-Event-ID, Mcp-Protocol-Version, Mcp-Session-Id');
        header('Access-Control-Expose-Headers: Mcp-Session-Id');
        header('Access-Control-Max-Age: 86400');
        header('Cache-Control: no-store');
        http_response_code(204);
        return;
    }

    if ($method === 'GET') {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $wantsSSE = str_contains($accept, 'text/event-stream');

        if ($wantsSSE) {
            // ChatGPT / Cursor probe: authenticated GET with Accept: text/event-stream → 200 SSE stub.
            $token = mcp_bearer_token_from_request();
            if ($token !== '') {
                $principal = mcp_resolve_bearer_token($token);
                if ($principal !== null) {
                    header('Content-Type: text/event-stream; charset=utf-8');
                    header('Cache-Control: no-cache');
                    header('X-Accel-Buffering: no');
                    mcp_cors_headers_for_response();
                    echo ": wolhg mcp standalone SSE\n\n";
                    flush();
                    return;
                }
            }
            // Unauthenticated SSE probe → 401 with resource_metadata
            mcp_send_unauthorized('missing_token');
            return;
        }

        // Regular GET → advertise endpoint info
        header('Content-Type: application/json; charset=utf-8');
        $oauthEnabled = function_exists('mcp_oauth_is_enabled') && mcp_oauth_is_enabled();
        echo json_encode([
            'ok' => true,
            'server' => mcp_server_info(),
            'protocolVersion' => mcp_protocol_version(),
            'transport' => 'streamable-http',
            'auth' => $oauthEnabled ? 'OAuth 2.1 (Bearer wolhg_at_…) or API key (Bearer wolhg_ak_…)' : 'Bearer wolhg_ak_…',
            'oauth' => $oauthEnabled ? [
                'authorization_server' => function_exists('mcp_oauth_issuer_url') ? mcp_oauth_issuer_url() : null,
                'docs' => '/docs/oauth.md',
            ] : null,
            'docs' => '/docs/mcp.md',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($method === 'DELETE') {
        // Stateless: nothing to tear down.
        http_response_code(204);
        return;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST, DELETE, OPTIONS');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $token = mcp_bearer_token_from_request();
    if ($token === '') {
        mcp_send_unauthorized('missing_token');
        return;
    }

    $remoteKey = 'mcp:' . ($_SERVER['REMOTE_ADDR'] ?? 'local');
    if (!throttle_action($remoteKey, 120, 60)) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Too many requests'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $principal = mcp_resolve_bearer_token($token);
    if ($principal === null) {
        mcp_send_unauthorized('invalid_token');
        return;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Empty body'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Batch or single JSON-RPC message.
    $isBatch = array_is_list($decoded) && isset($decoded[0]) && is_array($decoded[0]);
    $messages = $isBatch ? $decoded : [$decoded];
    $responses = [];
    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }
        $response = mcp_handle_jsonrpc($principal, $message);
        if ($response !== null) {
            $responses[] = $response;
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    header('MCP-Protocol-Version: ' . mcp_protocol_version());
    if ($responses === []) {
        http_response_code(202);
        return;
    }
    echo json_encode($isBatch ? $responses : $responses[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function mcp_send_unauthorized(string $reason): void
{
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');

    $wwwAuth = 'Bearer realm="wolhg-mcp", error="invalid_token", error_description="' . $reason . '"';

    // If OAuth is enabled, include resource_metadata for MCP discovery (RFC 9728)
    if (function_exists('mcp_oauth_is_enabled') && mcp_oauth_is_enabled()
        && function_exists('mcp_oauth_www_authenticate')
        && function_exists('mcp_oauth_protected_resource_metadata_url')
    ) {
        $errorCode = in_array($reason, ['missing_token'], true) ? null : 'invalid_token';
        $wwwAuth = mcp_oauth_www_authenticate(
            mcp_oauth_protected_resource_metadata_url(),
            [],
            $errorCode,
            $reason,
        );
    }

    header('WWW-Authenticate: ' . $wwwAuth);
    echo json_encode(['error' => 'unauthorized', 'reason' => $reason], JSON_UNESCAPED_UNICODE);
}

function mcp_cors_headers_for_response(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Expose-Headers: Mcp-Session-Id');
}

/**
 * @param array{user: array<string, mixed>, scopes: list<string>, key_id: int} $principal
 * @param array<string, mixed> $message
 * @return array<string, mixed>|null null for notifications
 */
function mcp_handle_jsonrpc(array $principal, array $message): ?array
{
    $jsonrpc = (string) ($message['jsonrpc'] ?? '2.0');
    $id = $message['id'] ?? null;
    $method = (string) ($message['method'] ?? '');
    $params = $message['params'] ?? [];
    if (!is_array($params)) {
        $params = [];
    }

    $isNotification = !array_key_exists('id', $message);

    if ($method === 'notifications/initialized' || $method === 'notifications/cancelled') {
        return null;
    }

    if ($method === '') {
        return mcp_jsonrpc_error($id, -32600, 'Invalid Request');
    }

    try {
        $result = match ($method) {
            'initialize' => [
                'protocolVersion' => mcp_protocol_version(),
                'capabilities' => [
                    'tools' => new stdClass(),
                ],
                'serverInfo' => mcp_server_info(),
                'instructions' => 'CRM домашних групп. Инструменты ограничены scope API-ключа и ролью пользователя. Чувствительные заметки через MCP не отдаются. Соблюдайте 152-ФЗ: не передавайте персональные данные в публичные чаты и учитывайте трансграничную передачу при облачных ИИ.',
            ],
            'ping' => new stdClass(),
            'tools/list' => [
                'tools' => mcp_tools_for_scopes($principal['scopes']),
            ],
            'tools/call' => mcp_jsonrpc_tools_call($principal, $params),
            'resources/list' => ['resources' => []],
            'prompts/list' => ['prompts' => []],
            default => null,
        };
    } catch (Throwable $e) {
        return mcp_jsonrpc_error($id, -32000, $e->getMessage());
    }

    if ($result === null) {
        if ($isNotification) {
            return null;
        }
        return mcp_jsonrpc_error($id, -32601, 'Method not found: ' . $method);
    }

    if ($isNotification) {
        return null;
    }

    return [
        'jsonrpc' => $jsonrpc !== '' ? $jsonrpc : '2.0',
        'id' => $id,
        'result' => $result,
    ];
}

/**
 * @param array{user: array<string, mixed>, scopes: list<string>, key_id: int} $principal
 * @param array<string, mixed> $params
 * @return array{content: list<array{type: string, text: string}>, isError?: bool, structuredContent?: mixed}
 */
function mcp_jsonrpc_tools_call(array $principal, array $params): array
{
    $name = trim((string) ($params['name'] ?? ''));
    $arguments = $params['arguments'] ?? [];
    if (!is_array($arguments)) {
        if (is_string($arguments) && $arguments !== '') {
            $decoded = json_decode($arguments, true);
            $arguments = is_array($decoded) ? $decoded : [];
        } else {
            $arguments = [];
        }
    }
    if ($name === '') {
        return [
            'content' => [['type' => 'text', 'text' => 'name is required']],
            'isError' => true,
        ];
    }

    $outcome = mcp_call_tool($principal, $name, $arguments);
    if (empty($outcome['ok'])) {
        return [
            'content' => [['type' => 'text', 'text' => (string) ($outcome['error'] ?? 'error')]],
            'isError' => true,
        ];
    }

    $payload = $outcome['data'];
    $text = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($text === false) {
        $text = '{}';
    }

    return [
        'content' => [['type' => 'text', 'text' => $text]],
        'structuredContent' => $payload,
    ];
}

/**
 * @param mixed $id
 * @return array<string, mixed>
 */
function mcp_jsonrpc_error($id, int $code, string $message): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ];
}

/**
 * Profile UI + POST handlers for API keys.
 */
function mcp_render_api_keys_panel(array $user): void
{
    if (!mcp_is_enabled()) {
        return;
    }

    $allowedScopes = mcp_scopes_allowed_for_role((string) $user['role']);
    $labels = mcp_api_key_scope_labels();
    $keys = mcp_list_api_keys($user);
    $mcpUrl = rtrim((string) (app_config('url') ?: ''), '/') . '/mcp';
    if ($mcpUrl === '/mcp') {
        $mcpUrl = '/mcp';
    }

    $oauthEnabled = function_exists('mcp_oauth_is_enabled') && mcp_oauth_is_enabled();
    echo '<section class="panel" id="mcp-api-keys"><h2>API-ключи (MCP)</h2>';
    echo '<p class="muted">Ключи дают агентам (Cursor, ChatGPT и др.) доступ к CRM через <code>' . e($mcpUrl) . '</code>. '
        . 'Токен показывается <strong>один раз</strong>. Права = пересечение scopes ключа и вашей роли. '
        . 'Не передавайте персональные данные в публичные ИИ без правовой оценки трансграничной передачи (152-ФЗ).</p>';
    if ($oauthEnabled) {
        echo '<p class="muted"><strong>OAuth (ChatGPT + Cursor):</strong> укажите URL <code>' . e($mcpUrl) . '</code> — '
            . 'клиент сам выполнит OAuth-авторизацию без ручного ключа. '
            . 'Подробнее: <a href="/docs/oauth.md">docs/oauth.md</a>.</p>';
    }

    if (!mcp_api_keys_table_ready()) {
        echo '<p class="flash error">Таблица API-ключей недоступна. Обновите страницу через минуту — миграция применяется автоматически. '
            . 'Если ошибка останется, выполните <code>php bin/migrate.php</code> или примените '
            . '<code>database/migrations/021_mcp_api_keys.sql</code>.</p></section>';
        return;
    }

    echo '<form method="post" action="' . e(app_path('/mcp-api-key-create')) . '" class="stack">' . csrf_field();
    echo '<label>Название<input name="name" required maxlength="120" placeholder="Cursor на ноутбуке"></label>';
    echo '<fieldset class="stack"><legend>Области доступа</legend>';
    foreach ($allowedScopes as $scope) {
        echo '<label class="checkbox-row"><input type="checkbox" name="scopes[]" value="' . e($scope) . '"'
            . ($scope === 'read' ? ' checked' : '') . '> '
            . e($labels[$scope] ?? $scope) . '</label>';
    }
    echo '</fieldset>';
    echo '<label>Срок действия (необязательно)<input name="expires_at" type="date"></label>';
    echo '<button class="primary">Создать ключ</button></form>';

    if ($keys === []) {
        echo '<p class="muted">Активных ключей пока нет.</p></section>';
        return;
    }

    echo '<div class="table-wrap"><table><thead><tr><th>Название</th><th>Префикс</th><th>Scopes</th><th>Статус</th><th>Последнее использование</th><th></th></tr></thead><tbody>';
    foreach ($keys as $key) {
        $status = !empty($key['active']) ? 'активен' : ($key['revoked_at'] ? 'отозван' : 'истёк');
        echo '<tr><td>' . e((string) $key['name']) . '</td><td><code>' . e((string) $key['token_prefix']) . '…</code></td><td>'
            . e(implode(', ', $key['scopes'] ?? [])) . '</td><td>' . e($status) . '</td><td>'
            . e((string) ($key['last_used_at'] ?? '—')) . '</td><td>';
        if (!empty($key['active'])) {
            echo '<form method="post" action="' . e(app_path('/mcp-api-key-revoke')) . '" class="inline">' . csrf_field()
                . '<input type="hidden" name="id" value="' . (int) $key['id'] . '">'
                . '<button class="ghost" data-confirm="Отозвать ключ? Агенты сразу потеряют доступ.">Отозвать</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

function mcp_handle_account_post(string $path, string $method, array $user): void
{
    if ($method !== 'POST') {
        return;
    }

    if ($path !== '/mcp-api-key-create' && $path !== '/mcp-api-key-revoke') {
        return;
    }

    if (!mcp_is_enabled()) {
        ajax_redirect('/profile', 'MCP отключён');
    }

    if ($path === '/mcp-api-key-create') {
        verify_csrf();
        try {
            $created = mcp_create_api_key(
                $user,
                (string) ($_POST['name'] ?? ''),
                $_POST['scopes'] ?? [],
                trim((string) ($_POST['expires_at'] ?? '')) ?: null
            );
            $_SESSION['mcp_new_api_key'] = $created['token'];
            ajax_redirect('/profile#mcp-api-keys', 'Ключ создан. Скопируйте токен ниже — он больше не отобразится.');
        } catch (Throwable $e) {
            ajax_redirect('/profile#mcp-api-keys', $e->getMessage());
        }
    }

    if ($path === '/mcp-api-key-revoke') {
        verify_csrf();
        $ok = mcp_revoke_api_key($user, (int) ($_POST['id'] ?? 0));
        ajax_redirect('/profile#mcp-api-keys', $ok ? 'Ключ отозван' : 'Ключ не найден');
    }
}

function mcp_flash_new_key_banner(): void
{
    if (!mcp_is_enabled()) {
        unset($_SESSION['mcp_new_api_key']);
        return;
    }
    $token = (string) ($_SESSION['mcp_new_api_key'] ?? '');
    if ($token === '') {
        return;
    }
    unset($_SESSION['mcp_new_api_key']);
    echo '<div class="flash success mcp-token-flash"><p>Новый API-ключ (скопируйте сейчас):</p>'
        . '<code class="mcp-token-value">' . e($token) . '</code></div>';
}
