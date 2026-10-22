# CRM Домашних групп

PWA на PHP и MySQL для домашних групп церкви. Мультицерковная архитектура:
каждая церковь — тенант со своим URL `/{slug}/…`, роли ограничивают доступ.

Лицензия: [MIT](LICENSE).

## Быстрый старт

```bash
docker compose up --build
```

Откройте http://localhost:8096. Демо-логины: `admin` / `pastor` / `leader`
(пароль у всех **`password`** — смените сразу).

Полная установка (Docker, shared hosting, пустая инстанция без демо-данных):
[docs/install.md](docs/install.md).

## Конфигурация

Базовые значения — `config/app.php`. Секреты и production:

```bash
cp config/local.example.php config/local.php
```

`config/local.php` не в git. Приложение не читает настройки из переменных окружения.

## Shared hosting

Apache + PHP 8.5 + MySQL. Document root → `public/`, либо весь проект в
`public_html` (корневые `index.php` и `.htaccess` защищают служебные каталоги).

Если на хостинге нет Composer, загрузите `vendor/` с машины, где выполнен
`composer install --no-dev`.

Инструкции: [docs/install.md](docs/install.md) · [docs/shared-hosting.md](docs/shared-hosting.md).

## CI/CD

- **GitHub Actions** — `.github/workflows/test.yml` (`make test`)
- **GitLab CI** — тесты и инкрементальный FTP-деплой: [docs/ci-cd.md](docs/ci-cd.md)

Секреты FTP — только в CI variables, не в репозитории.

## Сборка фронтенда

```bash
npm run build
```

Минифицированные `app.min.css` / `app.min.js` коммитятся; на хостинге отдельный
build не нужен.

## Почта, WebPush, MCP

- Почта: SMTP в `config/local.php`, очередь — `bin/send-emails.php`
- WebPush доставляет браузерные push о критичных срабатываниях **Радара заботы** (человек не был на встречах больше 6 недель): [docs/webpush.md](docs/webpush.md)
- MCP (Cursor/ChatGPT): функционал **временно выключен** (`mcp.enabled = false`); включение в `config/local.php` — [docs/mcp.md](docs/mcp.md)

## Персональные данные

Каждая церковь-оператор сама соблюдает закон о ПДн.
Чеклист для РФ: [docs/personal-data-rf.md](docs/personal-data-rf.md).

## Документация

| Документ | О чём |
|----------|--------|
| [docs/install.md](docs/install.md) | Установка демо / пустой инстанции |
| [docs/shared-hosting.md](docs/shared-hosting.md) | Timeweb и аналоги |
| [docs/ci-cd.md](docs/ci-cd.md) | GitLab CI + FTP |
| [docs/implementation-status.md](docs/implementation-status.md) | Статус функций |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Как вносить изменения |
| [SECURITY.md](SECURITY.md) | Сообщения об уязвимостях |
