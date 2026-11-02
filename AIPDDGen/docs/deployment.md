# Deploying to a live server

Target: any shared host with PHP 8.2+ and MySQL/MariaDB (cPanel hosting is the
expected path). The app has no build step and no Composer dependencies — copy
the files, import the database, set `.env.local`, done.

## 0. What you deploy

```
AIPDDGen/
  public/          ← web root points here (index.php, router.php, assets)
  src/ bin/ migrations/ templates/ lang/ config/
  database/pdd_generator_deploy.sql   ← clean schema + seed data
  storage/         ← writable (logs, sessions, uploads); create on the server
  .env.example     ← copy to .env.local on the server (never commit it)
```

`.env.local`, `storage/*` (except `.gitkeep`), `.tools/` and `.dsh/` are excluded
from git. The repository is public, so cPanel **Git Version Control → Clone**
works without credentials.

## 1. Get the code onto the server

**cPanel Git Version Control (recommended)**

1. cPanel → **Git™ Version Control** → **Create** → Clone a repository.
2. URL: `https://github.com/bodaboraapp-commits/carbonanalytica.git`
3. Branch: `main`; repository path: e.g. `~/repositories/carbonanalytica`.
4. Click **Create**, then copy the `AIPDDGen/` folder into the domain directory
   (or deploy directly into it).

**Or upload the ZIP** produced from the repo (code only; do not include
`storage/live` data).

## 2. Web root

Set the domain/subdomain document root to `.../AIPDDGen/public`.
If you cannot change the docroot, put the app one level above `public_html` and
copy `public/*` into `public_html`, adjusting nothing else — `public/index.php`
detects the base path automatically (`public/.htaccess` already routes requests
to `index.php`).

## 3. Configuration

```bash
cp .env.example .env.local
```

Edit `.env.local`:

```ini
APP_ENV=production
APP_URL=https://your-domain.example
APP_DEBUG=false

DB_HOST=localhost          # cPanel usually localhost
DB_PORT=3306               # NOT 3307 (that is the local XAMPP port)
DB_NAME=<cpanel_db_name>
DB_USER=<cpanel_db_user>
DB_PASSWORD=<db_password>
DB_TEST_NAME=<db_name>_test

# Email: either Resend (recommended) or SMTP
EMAIL_TRANSPORT=resend
RESEND_API_KEY=re_...
RESEND_FROM="AI PDD Generator <no-reply@your-domain.example>"

# Or SMTP:
# EMAIL_TRANSPORT=smtp
# SMTP_HOST=smtp.your-host.example
# SMTP_PORT=587
# SMTP_USER=...
# SMTP_PASSWORD=...
# SMTP_ENCRYPTION=tls
```

Create `storage/logs`, `storage/sessions`, `storage/uploads` and make them
writable (`chmod 775`, owner = PHP user).

## 4. Database

Two equivalent options:

**A. Import the ready dump** (fastest, includes demo + staff accounts):

cPanel → phpMyAdmin → select the database → **Import** →
`database/pdd_generator_deploy.sql`. See [database/README.md](../database/README.md).

**B. Build from migrations** (SSH available):

```bash
php bin/setup.php     # creates DB if allowed + applies migrations
php bin/seed.php      # idempotent seed data
```

The migration ledger (`schema_migrations`) is included in the dump, so after
importing it the app considers migrations 0001–0006 already applied.

## 5. Click-to-update the database (browser)

After the first import/setup, every later deployment can be finished in the
browser:

1. Sign in as a platform admin (`admin@pddgen.local` initially).
2. Open the clickable link: **`https://your-domain.example/admin/database`**
   (local: `http://127.0.0.1:8000/admin/database`).
3. **Apply pending migrations** — runs the new `migrations/*.sql` files against
   the live database and records them. Additive only; nothing is dropped.
4. **Run seed now** — idempotent: creates missing staff account, demo workspace,
   methodology catalogue and template structure if they are absent.

The page shows the database name/host, table count, PHP version, every applied
migration with its timestamp, pending migrations, and seed readiness
(`Present`/`Missing`). It is accessible only to platform admins and every action
is audited (`admin.database.migrated`, `admin.database.seeded`).

## 6. Post-deploy checklist

- [ ] `APP_URL` is the HTTPS URL; `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] `storage/` writable; no `storage/` path inside the web root.
- [ ] HTTPS redirect + secure cookies (`.env.local`: `SESSION_SECURE_COOKIES=true`).
- [ ] **Change the seeded passwords** (`admin@pddgen.local`, `demo@pddgen.local`)
      from the profile/reset flow, or delete the demo workspace.
- [ ] Delete `index.php` leftovers and any test databases from the server.
- [ ] Send a test invitation/reset e-mail to confirm the transport works.
- [ ] Open `/admin/database` → "All migrations are applied".
- [ ] Register a cron job if you later add scheduled purges (step 10); none needed today.

## 7. Updating an existing deployment

```bash
git pull                 # or update via cPanel Git Version Control
# then in the browser, as admin:
#   https://your-domain.example/admin/database → Apply pending migrations
# no SSH required
```

If SSH is available, `php bin/migrate.php` does the same non-interactively.

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| Database connection refused | cPanel uses port 3306; XAMPP uses 3307. Check `DB_PORT` and the DB user's privileges for that database. |
| 500 on any page | `storage/logs/app.log`; check `storage/` permissions. |
| Login works but redirects to `/verify-otp` | Two-factor was switched on for that account; enter the emailed code or turn it off from `/admin` (sign-in security card). |
| "CSRF token mismatch" | Session cookie not stored: check `APP_URL`, HTTPS, `SESSION_SECURE_COOKIES`. |
| Emails not arriving | `EMAIL_TRANSPORT` settings; in non-production the body is mirrored to `storage/logs/mail.log`. |
| cPanel Git clone asks for username | Repo must be public, or add a deploy key/cPanel SSH key. The repo is public today. |
