# Установка CRM Домашних групп

Два сценария: **демо** (сразу есть группы и люди) и **пустая** инстанция для
своей церкви.

## Требования

- PHP **8.5+** с расширениями `pdo_mysql`, `mbstring`, `json`, `openssl`, `curl`
- MySQL 8.x или совместимая MariaDB
- Apache с `mod_rewrite` (или эквивалент)
- Composer на машине сборки (на shared hosting можно залить готовый `vendor/`)

## 1. Docker (демо, быстрее всего)

```bash
cp config/local.example.php config/local.php   # опционально
docker compose up --build
```

Откройте http://localhost:8096

| Логин | Роль | Пароль |
|-------|------|--------|
| `admin` | суперадмин | `password` |
| `pastor` | пастор | `password` |
| `leader` | лидер ДГ | `password` |

Сразу смените пароли. Демо-церковь: slug `example` (`/example/…`).

MySQL: `localhost:3307`, БД `church_care`, пользователь/пароль `church`/`church`.

## 2. Shared hosting

Подробности document root и `.htaccess`: [shared-hosting.md](shared-hosting.md).

Кратко:

```bash
cp config/local.example.php config/local.php
# заполните db, url, security.encryption_key, при необходимости SMTP
composer install --no-dev --prefer-dist --optimize-autoloader
# если на хостинге нет Composer — загрузите каталог vendor/ с машины сборки
```

### Демо-данные

```text
database/init/001_schema.sql
database/init/002_seed.sql
database/init/003_bootstrap_admin.sql
```

### Пустая инстанция (рекомендуется другой церкви)

```text
database/init/001_schema.sql
database/init/empty/002_seed.sql
database/init/003_bootstrap_admin.sql
```

Миграции из `database/migrations/` применятся сами (`migrate.auto`) или:

```bash
php bin/migrate.php
php bin/verify-schema.php
```

Дальше: войти как `admin` / `password` → сменить пароль → `/admin` или ссылка
`/regchurch` для пастора.

## 3. После установки (чеклист)

1. `security.encryption_key` — длинный случайный секрет (не `local-dev-key`).
2. Сменить пароль `admin` (и остальных демо-пользователей, если использовали полный seed).
3. `'url' => 'https://ваш-домен'` для ссылок в письмах.
4. SMTP в `config/local.php` на большинстве shared-хостингов.
5. Cron на `bin/send-emails.php`, `bin/send-notifications.php`, `bin/send-bot-notifications.php` (разнесите по минутам/секундам — см. [shared-hosting.md](shared-hosting.md)).
6. Права на запись: `public/uploads/church/`, `public/uploads/knowledge/`.
7. Политика ПДн и текст согласия: [personal-data-rf.md](personal-data-rf.md).

Опционально: WebPush ([webpush.md](webpush.md)), соцлогин/боты, MCP (по умолчанию выключен).

## 4. Мультицерковность

- URL CRM: `/{slug}/groups`, …
- Корень `/` — выбор церкви
- Публичный каталог ДГ: `/homegroup` и `/{slug}/homegroup`
- Лендинг: `/l/{slug}`

`legacy_default_slug` в конфиге задаёт, куда вести старые закладки без slug.
Если ключ пустой, берётся первая не-демо церковь из БД (иначе `example`).

## 5. CI и деплой

- GitLab: [ci-cd.md](ci-cd.md) (тесты + инкрементальный FTP)
- GitHub Actions: `.github/workflows/test.yml` (`make test`)

`config/local.php` на сервер кладётся вручную и **никогда** не перезаписывается
деплоем.
