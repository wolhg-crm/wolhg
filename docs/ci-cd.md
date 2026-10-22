# CI/CD и FTP-деплой

Конвейер GitLab CI описан в [`.gitlab-ci.yml`](../.gitlab-ci.yml).

## Стадии

1. **test** — `make test` (PHP lint, unit/smoke, JS-проверки) и dry-run планировщика FTP-деплоя.
2. **deploy:ftp** — только ветка по умолчанию (`main`), только после успешного `test`. На FTP уходят **только файлы, изменившиеся с прошлого успешного деплоя**.

## Инкрементальный деплой

Скрипт [`scripts/deploy/ftp-deploy.php`](../scripts/deploy/ftp-deploy.php) планирует diff на PHP и передаёт файлы через **lftp** (passive mode, retries):

1. Читает на сервере файл `.deploy-revision` (полный git SHA последнего успешного деплоя).
2. Если файла нет или задано `DEPLOY_FULL=1` — загружает все отслеживаемые deployable-файлы (полный синк кода приложения).
3. Иначе считает `git diff` между `.deploy-revision` и `DEPLOY_TO` (в CI это `$CI_COMMIT_SHA`).
4. Загружает добавленные/изменённые пути **атомарно** (см. ниже), удаляет удалённые (с фильтром политики путей).
5. Обновляет `.deploy-revision` тем же put→rename.

### Атомарная публикация (put → rename)

Обычный FTP `put` пишет прямо в целевой файл. Пока идёт заливка большого `src/Account.php`, cron (`bin/send-emails.php` / `bin/send-notifications.php`) может успеть `require` обрезанный файл и упасть с:

`PHP Parse error: Unclosed '{' on line 1033 … on line 1035`

Именно так выглядел инцидент 2026-09-29 14:20 на Timeweb: MDTM файла совпал с минутным cron, локальная копия при этом была синтаксически валидна.

Деплой теперь:

1. Кладёт файл в `.deploy-staging/<sha>/…`
2. Делает `mv` (RNFR/RNTO) на живой путь — для PHP это мгновенная замена inode
3. Пишет `.deploy-revision` тем же способом
4. Удаляет дерево `.deploy-staging/<sha>/`

`.deploy-staging` закрыт в корневом `.htaccess` и не считается deployable-путём.

Политика путей (что нельзя выкладывать) живёт в [`scripts/deploy/paths.php`](../scripts/deploy/paths.php) и покрыта unit-тестами. Не деплоятся, в частности:

- `tests/`, `docs/`, `scripts/`, `vendor/`, Docker/Make/Node tooling
- `config/local.php`, `.env`, `_once_*.php`
- пользовательский контент в `public/uploads/**` (кроме `.htaccess` / `.gitkeep`)

`config/local.php` по-прежнему создаётся на сервере вручную и **никогда** не перезаписывается CI.

## Переменные GitLab CI/CD

Settings → CI/CD → Variables (masked + protected для пароля):

| Variable | Пример | Обязательно |
|---|---|---|
| `FTP_HOST` | `wolhg.ru` | да |
| `FTP_USER` | FTP-логин хостинга | да |
| `FTP_PASSWORD` | пароль FTP | да (masked, protected) |
| `FTP_REMOTE_PATH` | `/public_html` | да |
| `FTP_PORT` | `21` | нет |
| `FTP_SSL` | `0` / `1` | нет |
| `DEPLOY_FULL` | `1` для разового полного синка | нет |

## Локальный dry-run и деплой

```bash
# План без сети и без секретов
DEPLOY_DRY_RUN=1 make deploy-dry-run

# Реальный деплой (секреты из окружения, не из git)
export FTP_HOST=wolhg.ru
export FTP_USER=...
export FTP_PASSWORD=...
export FTP_REMOTE_PATH=/public_html
make deploy-ftp
```

Пример имён переменных: [`config/ftp.deploy.example.env`](../config/ftp.deploy.example.env).

## Runner

Проект рассчитан на GitLab SaaS Docker runners (`php:8.5-cli-bookworm`) и на shell executor с PHP/Node/`lftp`. Job сам ставит Node и `lftp`, если их нет в окружении.

## После деплоя

Миграции применяются автоматически:

1. Job `deploy:ftp` после загрузки файлов вызывает `POST /system/migrate` (если задан `MIGRATE_TOKEN`) или HTTP GET на сайт (`migrate.auto`).
2. Локально / по cron: `php bin/migrate.php`, затем при необходимости `php bin/verify-schema.php`.

Опциональные CI variables: `MIGRATE_TOKEN`, `MIGRATE_BASE_URL` (по умолчанию `https://wolhg.ru`).

См. также [shared-hosting.md](shared-hosting.md).
