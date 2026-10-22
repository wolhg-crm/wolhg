# Empty install seed

Use this instead of `../002_seed.sql` when you want a blank CRM (no demo people,
groups, or meetings).

Import order:

1. `../001_schema.sql`
2. `002_seed.sql` (this folder)
3. `../003_bootstrap_admin.sql`

Then open the site, sign in as `admin` / `password`, change the password, and
create your real church via `/admin` or a permanent `/regchurch` invite.

Do **not** place this file next to the Docker `database/init/` scripts that
MySQL auto-loads — Docker already uses the full demo seed.
