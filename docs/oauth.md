# OAuth 2.1 для MCP — wolhg

Реализован полный OAuth 2.1 flow для подключения ChatGPT и Cursor к MCP-серверу `https://wolhg.ru/mcp`.

Спецификации: RFC 9728 (PRM), RFC 8414 (AS metadata), PKCE S256 (RFC 7636), Resource Indicator (RFC 8707), DCR (RFC 7591).

## Endpoint-ы

| Путь | Назначение |
|------|-----------|
| `/.well-known/oauth-protected-resource/mcp/` | RFC 9728 Protected Resource Metadata |
| `/.well-known/oauth-authorization-server` | RFC 8414 AS Metadata |
| `/.well-known/openid-configuration` | OIDC Discovery (подмножество) |
| `/mcp/oauth/authorize/` | Форма согласия (GET+POST) |
| `/mcp/oauth/token/` | Выдача / обновление токена (POST) |
| `/mcp/oauth/register/` | DCR регистрация клиента (POST) |
| `/mcp/oauth/revoke/` | Отзыв токена (POST) |
| `/mcp/oauth/userinfo/` | UserInfo (GET) |

> **Важно:** URL-ы с trailing slash рекламируются намеренно — Apache перенаправляет POST без слеша на GET (301), что ломает DCR и выдачу токенов.

## Подключение ChatGPT

Официальный путь: [Developer mode and MCP connectors](https://help.openai.com/en/articles/12584461-developer-mode-and-full-mcp-connectors-in-chatgpt-beta) + [OAuth 2.1 for MCP](https://developers.openai.com/plugins/build/auth).

1. Платный план ChatGPT → **Settings → Security and login** → включите **Developer mode**.
2. **Settings → Connectors → Create** (или Plugins / MCP connectors в актуальном UI).
3. **Connector URL / MCP server URL:** `https://wolhg.ru/mcp` (**без** trailing slash).
4. **Authentication:** **OAuth** (не Token / API key).
5. Client ID / Secret не заполняйте — ChatGPT сам сделает **DCR** на `/mcp/oauth/register/`.
6. Пройдите браузерный логин на wolhg.ru → форма согласия → scopes.
7. Включите коннектор в чате и вызовите, например, `get_me`.

Discovery: ChatGPT читает `WWW-Authenticate: resource_metadata=…`, затем PRM и AS metadata (`registration_endpoint`, PKCE S256, `authorization_response_iss_parameter_supported`).

> Если после согласия ChatGPT пишет ошибку соединения — проверьте, что POST на `/mcp/oauth/token/` и `/mcp/oauth/register/` не уходит в Apache **301** (тело теряется). URL в metadata заканчиваются на `/` намеренно.

## Подключение Cursor

Cursor поддерживает MCP с OAuth. В `.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "wolhg": {
      "url": "https://wolhg.ru/mcp"
    }
  }
}
```

Cursor автоматически обнаружит OAuth через `WWW-Authenticate: Bearer resource_metadata=…` и выполнит авторизацию. Redirect URI `cursor://anysphere.cursor-mcp/oauth/callback` принимается.

## API-ключи (альтернатива)

Для Cursor также работают API-ключи `wolhg_ak_…` (см. [mcp.md](mcp.md) → «Как создать ключ»).

## Токены

| Тип | Префикс | TTL |
|-----|---------|-----|
| Access token | `wolhg_at_…` | 1 час (3600 с) |
| Refresh token | `wolhg_rt_…` | 30 дней (2592000 с) |
| Authorization code | `wolhg_ac_…` | 10 минут |
| Client ID (DCR) | `wolhg_oc_…` | — |

Хранятся только SHA-256 хэши. Refresh rotation: каждый `/token` с `grant_type=refresh_token` инвалидирует старый токен и выдаёт новую пару.

## Scopes

Те же что у API-ключей, ограничены ролью пользователя, давшего согласие:

| Scope | Назначение |
|-------|-----------|
| `read` | Группы, люди, радар, статистика, встречи |
| `attendance` | Создание встреч, отметка посещаемости |
| `people` | Обновление карточек и профиля |
| `roles` | Смена роли по матрице |
| `church` | Церковная аналитика |

## Важные нюансы (gotchas)

1. `WWW-Authenticate` ДОЛЖЕН содержать `resource_metadata=` URL **со слешом**: `https://wolhg.ru/.well-known/oauth-protected-resource/mcp/`
2. PRM поле `resource` — **без слеша**: `https://wolhg.ru/mcp`
3. `OPTIONS` → 204 без авторизации (CORS preflight)
4. Authenticated `GET /mcp` с `Accept: text/event-stream` → 200 SSE-stub
5. DCR принимает `cursor://anysphere.cursor-mcp/oauth/callback`
6. `iss` включён в ответ authorize redirect
7. Все OAuth URL рекламируются с trailing slash
8. Path-aware discovery тоже JSON: `/.well-known/oauth-authorization-server/mcp`, `/mcp/.well-known/openid-configuration` и т.п. — иначе Cursor падает с `Unexpected token '<', "<!doctype "...` (HTML логина)

## Безопасность и 152-ФЗ

OAuth access token даёт те же права что API-ключ — применяются те же ограничения. Облачные ИИ обрабатывают данные за пределами РФ. Не включайте `people` / `roles` scope без правовой оценки трансграничной передачи.

Подробнее: [personal-data-rf.md](personal-data-rf.md).

## Настройка

Сейчас MCP по умолчанию **выключен** (`mcp.enabled = false` в `config/app.php`). OAuth недоступен, пока MCP выключен.

В `config/local.php`:

```php
'mcp' => [
    'enabled' => true,              // обязательно true, иначе /mcp и OAuth отключены
    'oauth' => [
        'enabled' => true,           // по умолчанию true если mcp.enabled = true
        'advertise_cimd' => false,   // CIMD (Client ID Metadata Document) — не нужен для ChatGPT/Cursor
        'access_ttl' => 3600,        // TTL access token в секундах
        'refresh_ttl' => 2592000,    // TTL refresh token в секундах
    ],
],
```

## Миграция

```bash
mysql … < database/migrations/038_mcp_oauth.sql
```

На свежей установке таблицы уже в `database/init/001_schema.sql`.
