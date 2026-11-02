# Deployment database

`pdd_generator_deploy.sql` — a clean, ready-to-import MySQL/MariaDB dump of the
AI PDD Generator schema **plus seed data**.

Contents:

- all 32 tables (schema + migrations ledger marked as applied 0001–0009)
- 6 provisional methodologies (Gold Standard / Verra shortlist)
- Gold Standard Template Guide v1.4 structure (draft, 21 sections)
- demo workspace: 2 projects, 1 approved fact sheet, 1 draft in review
- staff console: 5 review tasks
- accounts:
  - `admin@pddgen.local` / `AdminPassw0rd!123` — platform admin
  - `demo@pddgen.local` / `DemoPassw0rd!123` — tenant demo

> **Change the seeded passwords immediately after going live.** Both accounts are
> created with known passwords for the first sign-in.

## Import options

### A. Browser — staff console (no phpMyAdmin, no SSH)

After the code is deployed, sign in as a platform admin and open:

**`https://your-domain.example/admin/database/import`**

The page shows the dump file, its SHA-256 and the current database state. Use
**Validate file (dry run)** first, then **Import dump now**. If the database
already has data, tick the replacement confirmation (the dump rebuilds all
tables). A fresh deployment with no accounts can use the token URL instead:
`/deploy/import?token=<DEPLOY_TOKEN>` (see `docs/deployment.md`).

### B. phpMyAdmin (cPanel)

1. Create the database and a database user in cPanel.
2. Open phpMyAdmin → select the database → **Import** → choose
   `database/pdd_generator_deploy.sql` → **Go**.
3. Edit `.env.local` on the server with the same DB name/user/password.

### C. Command line

```bash
mysql -u <user> -p <database> < database/pdd_generator_deploy.sql
```

### D. Skip the dump (fresh install with SSH)

```bash
php bin/setup.php
php bin/seed.php
```

`bin/setup.php` creates the database if needed and applies all migrations;
`bin/seed.php` is idempotent and adds the same seed data.

## After importing

1. Point `.env.local` at the database (`DB_HOST`, `DB_PORT`, `DB_NAME`,
   `DB_USER`, `DB_PASSWORD`) and set `APP_URL` to the live URL.
2. Open the site, sign in as `admin@pddgen.local`, then go to
   **Admin → Database** (`/admin/database`) and confirm "All migrations are applied".
   The same page applies any future migrations with one click.
3. Change both seeded passwords and delete the demo workspace if it is not wanted.
