# Contributing

## Development

```bash
docker compose up --build
# http://localhost:8096 — demo logins use password "password" (see README)
make test
```

After editing `public/assets/app.css` or `public/assets/app.js`:

```bash
npm run build
```

## Pull requests

- Keep changes focused; prefer several small PRs over one mixed commit.
- Add or update tests under `tests/` for behaviour you change.
- Do **not** commit:
  - `config/local.php`, `.env`, FTP/SFTP configs with passwords;
  - production one-off SQL that names real people or logins;
  - member photos or other personal media under `public/uploads/`;
  - operational data dumps.
- Schema changes belong in `database/migrations/NNN_description.sql` and must be
  idempotent where practical (`IF NOT EXISTS`, safe updates).

## Code style

- PHP 8.5+, strict types, existing module layout under `src/`.
- Avoid new runtime dependencies unless discussed; Composer packages need a
  clear reason and lockfile update.
