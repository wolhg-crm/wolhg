# Загрузка на обычный PHP-хостинг

Проект можно загрузить на Apache-хостинг с PHP и MySQL без Docker. Нужны PHP 8.5, расширения `pdo_mysql`, `mbstring`, `json`, `openssl`, `curl`, включенный `mod_rewrite` и база MySQL 8.x или совместимая MariaDB.

## Вариант 1: хостинг позволяет выбрать корень сайта

Это лучший вариант. Загрузите проект целиком выше публичной директории и укажите document root на папку `public/`. В этом режиме работает `public/.htaccess`, а `config/`, `src/`, `database/`, `bin/` и `vendor/` остаются вне веб-доступа.

## Вариант 2: доступен только `public_html`

Если хостер разрешает загружать файлы только прямо в `public_html`, загрузите содержимое проекта в `public_html`. Корневые `index.php` и `.htaccess` направят запросы в `public/` и закроют служебные каталоги от прямого доступа.

## Настройка

1. Скопируйте `config/local.example.php` в `config/local.php`.
2. Впишите данные MySQL в блок `db`. Для обычного хостинга укажите `'host' => 'localhost'` (не `db` — это имя сервиса только для Docker). Если панель хостинга показывает отдельный hostname БД — используйте его. При необходимости задайте `unix_socket`, `connect_timeout` и `connect_retries` (см. `config/local.example.php`).
3. Загрузите `config/local.php` на сервер вручную: файл не попадает в git и исключён из FTP/SFTP-деплоя (CI и локальный `scripts/deploy/ftp-deploy.php` его не загружают).
4. Для production замените `security.encryption_key` на длинный случайный секрет.

Автоматический деплой только изменённых файлов через GitLab CI: [ci-cd.md](ci-cd.md).
4. Импортируйте SQL в новую базу. **Демо** (группы и люди):

```bash
database/init/001_schema.sql
database/init/002_seed.sql
database/init/003_bootstrap_admin.sql
```

**Пустая** инстанция (только церковь-заготовка + admin):

```bash
database/init/001_schema.sql
database/init/empty/002_seed.sql
database/init/003_bootstrap_admin.sql
```

Дальше недостающие файлы из `database/migrations/` применятся сами (`migrate.auto`) или через `php bin/migrate.php`. Ручной список по номерам больше не нужен для новой установки; в каталоге по-прежнему лежат, в частности, `017_miracles.sql`, `018_prayer_public_submit.sql`, `019_miracle_public_submit.sql`, `020_person_group_memberships.sql`, `025_church_deleted_at.sql`, `038_mcp_oauth.sql` и остальные numbered SQL.
Мультигородность: CRM живёт по адресам `/{slug}/…` (например `/example/groups`). Корень `/` — выбор церкви. Публичная карта `/homegroup` показывает домашние группы всех церквей; `/{slug}/homegroup` — только группы этой церкви. Мини-лендинг церкви — `/l/{slug}` (старый `/{slug}/about` редиректит сюда). Формы молитвенной нужды и свидетельства привязаны к церкви: `/{slug}/prayer-request`, `/{slug}/miracle-share` (корневые `/prayer-request` и `/miracle-share` редиректят на церковь по умолчанию). `/homegroup/join` остаётся на корне платформы (и доступен как `/{slug}/homegroup/join`). Старые закладки CRM редиректятся на slug по умолчанию (`legacy_default_slug` или первая церковь в БД). Суперадминистратор на `/admin` может менять настройки церкви и мягко удалять церковь (`churches.deleted_at`); удалённые церкви не попадают в выбор и в URL-тенант.

Краткая установка (демо / пустая инстанция, Composer/`vendor/`): [install.md](install.md).

После загрузки кода и SQL проверьте схему:

```bash
php bin/verify-schema.php
```

### Автомиграции

Приложение само применяет недостающие файлы из `database/migrations/` при веб-запросе (`migrate.auto`, по умолчанию включено) и через CLI:

```bash
php bin/migrate.php
php bin/migrate.php --dry-run
```

История хранится в таблице `schema_migrations`. После FTP-деплоя GitLab CI вызывает `POST /system/migrate` (если задан `MIGRATE_TOKEN`) либо дергает главную страницу, чтобы сработал `migrate.auto`.

Токен: `migrate.token` в `config/local.php`, либо HMAC от `security.encryption_key` (заголовок `X-Migrate-Token`).

Каталоги `public/uploads/knowledge/` и `public/uploads/church/` должны быть доступны для записи PHP.

Если на хостинге нет Composer, загрузите каталог `vendor/` вместе с проектом. Если Composer есть, можно выполнить `composer install --no-dev --prefer-dist --optimize-autoloader`.

## Почта (Timeweb SMTP)

В `config/local.php` задайте абсолютный URL сайта — он подставляется в ссылки писем:

```php
'url' => 'https://your-domain.ru',
```

На Timeweb PHP `mail()` часто не работает. Включите SMTP:

```php
'mail' => [
    'driver' => 'smtp',
    'from' => 'crm@your-domain.ru',
    'smtp' => [
        'host' => 'smtp.timeweb.ru',
        'port' => 465,
        'encryption' => 'ssl', // или 587 + 'tls'
        'username' => 'crm@your-domain.ru',
        'password' => 'mailbox-password',
    ],
],
```

Создайте почтовый ящик в панели Timeweb на вашем домене и используйте его логин/пароль. После смены настроек верните failed-письма в очередь:

```sql
UPDATE email_notifications
SET status = 'pending', error_message = NULL
WHERE status = 'failed';
```

Затем запустите `php bin/send-emails.php`.

## Cron

Для фоновых отправок добавьте cron-задачи, если хостер поддерживает PHP CLI. Разнесите их по соседним минутам, чтобы три PHP-процесса не стартовали одновременно (на shared-хостинге есть лимит процессов; иначе возможны `SQLSTATE[HY000] [2002] Resource temporarily unavailable`):

```cron
* * * * * php /path/to/project/bin/send-emails.php
* * * * * sleep 15; php /path/to/project/bin/send-notifications.php
* * * * * sleep 30; php /path/to/project/bin/send-bot-notifications.php
```

Каждый `bin/send-*.php` берёт flock: повторный запуск, пока предыдущий ещё работает, тихо пропускается (exit 0). Подключение к MySQL делает несколько попыток при транзиентных ошибках 2002/2003/1040.

Если в логах систематически остаётся `Resource temporarily unavailable` после деплоя этого кода — проверьте нагрузку CPU/MySQL в панели хостинга и не запускайте тяжёлые CLI рядом с пиковым трафиком.

Если cron недоступен, основное веб-приложение всё равно будет работать, но email, WebPush и bot-уведомления не будут отправляться автоматически.

Настройка WebPush (VAPID-ключи, подписка служителей, диагностика): [webpush.md](webpush.md).
