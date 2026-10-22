# MCP — CRM домашних групп

MCP встроен в основной PHP-сервис (как в smmpublisher). Endpoint:

`https://<ваш-хост>/mcp`

Сейчас по умолчанию **выключено** (`mcp.enabled = false` в `config/app.php`): endpoint `/mcp`, OAuth и секция «API-ключи (MCP)» в профиле и гайде скрыты. Чтобы включить снова — `'mcp' => ['enabled' => true]` в `config/local.php`.

## Аутентификация

Два варианта:

1. **API-ключ** `wolhg_ak_…` — для Cursor с ручной настройкой и скриптов.
2. **OAuth 2.1 access token** `wolhg_at_…` — для ChatGPT и автоматического OAuth flow в Cursor.

Подробнее про OAuth: [oauth.md](oauth.md).

### Как создать ключ

1. Войдите в CRM под своей учётной записью.
2. Откройте **Профиль** (`/profile`) → секция **API-ключи (MCP)**.
3. Выберите название, scopes и при необходимости срок действия.
4. Скопируйте токен `wolhg_ak_…` — он показывается **один раз**.
5. URL MCP: `https://<host>/mcp` (абсолютный URL берётся из `config.url`).

Scopes — пересечение с тем, что разрешено **роли** владельца ключа:

| Scope | Назначение |
|-------|------------|
| `read` | группы, люди, радар, статистика ДГ, встречи |
| `attendance` | создать встречу, отметить присутствующих |
| `people` | обновить профиль / карточку, контакт |
| `roles` | смена роли по матрице переходов |
| `church` | церковная аналитика (+ право `view_church_analytics`) |

Дополнительно каждый инструмент проверяет CRM-права (`can_edit_group_data`, `can_action`, `can_transition_role`, `scoped_*`). Ключ администратора не обходит scope church без права аналитики и не обходит матрицу ролей.

## Cursor

**Вариант A — OAuth (рекомендуется):** просто укажите URL, Cursor сам выполнит OAuth flow:
```json
{
  "mcpServers": {
    "wolhg": {
      "url": "https://your-host/mcp"
    }
  }
}
```

**Вариант B — API-ключ:**
```json
{
  "mcpServers": {
    "wolhg": {
      "url": "https://your-host/mcp",
      "headers": {
        "Authorization": "Bearer wolhg_ak_..."
      }
    }
  }
}
```

Пример: [`.cursor/mcp.json.example`](../.cursor/mcp.json.example).

## ChatGPT

MCP URL: `https://your-host/mcp`. Тип аутентификации: OAuth. Подробности: [oauth.md](oauth.md).

## Tools

| Tool | Scope | Кратко |
|------|-------|--------|
| `get_me` | read | текущий пользователь и capabilities |
| `list_groups` | read | доступные домашние группы |
| `list_people` / `get_person` | read | люди без sensitive_notes |
| `get_care_radar` / `list_inactive_people` | read | кто в риске / давно не ходил |
| `get_group_stats` | read | здоровье ДГ (90 дней) |
| `get_church_stats` | church | церковная аналитика |
| `list_meetings` / `get_meeting` | read | встречи и посещаемость |
| `create_meeting` | attendance | создать встречу |
| `save_meeting_attendance` | attendance | зафиксировать кто пришёл |
| `update_profile` | people | своя учётная запись |
| `update_person` | people | поля карточки |
| `add_contact_log` | people | записать контакт |
| `change_user_role` / `list_assignable_roles` | roles | повышение/понижение роли |

Все вызовы пишутся в `audit_logs` (`mcp_tool_call`, `mcp_api_key_create`, …).

## Безопасность и 152-ФЗ

См. подробный чеклист: [personal-data-rf.md](personal-data-rf.md).

Критично: облачные ИИ (Cursor Cloud, ChatGPT) могут обрабатывать персональные данные **за пределами РФ**. Не подключайте production-ключ к облачным агентам без правовой оценки трансграничной передачи и согласия субъектов.

Чувствительные заметки (`sensitive_notes_encrypted`) через MCP **никогда не отдаются**.

## Миграция

```bash
mysql … < database/migrations/021_mcp_api_keys.sql
```

На свежей установке таблица уже в `database/init/001_schema.sql`.

## Протокол

Streamable HTTP, JSON-RPC 2.0, `protocolVersion` `2025-03-26`, режим **stateless** (как smmpublisher). Методы: `initialize`, `tools/list`, `tools/call`, `ping`.
