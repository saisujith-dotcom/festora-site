# Festora: College Event Registration and Management System

Mini project for the Web Development course. Students browse and register for college events; organizers create events, manage schedules, announcements, capacity and participant lists.

**Stack:** HTML5, CSS3 (Flexbox + Grid, responsive), JavaScript (DOM, events, validation, AJAX/JSON), PHP 8.4 (PDO), MySQL-compatible database. TiDB Cloud is the deployment target; compatibility remains to be verified.

## Features

| Area | What it does |
|---|---|
| Home | Upcoming events and latest announcements |
| Events | AJAX search by keyword, filter by category, optional past events (JSON API) |
| Event page | Description, venue, date and time, schedule, event announcements, seat progress |
| Registration | One-click register / cancel, duplicate prevention, capacity enforced inside a DB transaction |
| My Registrations | Student's own registrations with cancel option |
| Organizer dashboard | Stats, create / edit / delete events (full CRUD) |
| Participants | Per-event list, remove participant, CSV export |
| Schedule | Add and remove timetable items per event |
| Announcements | General or event-specific announcements |
| Auth | Signup (students), login, logout (POST + CSRF), sessions, role-based access control |

## Local setup

### Fresh installation

1. Install PHP 8.4 with `pdo_mysql`, `mbstring`, and `curl` (the regression suite uses local HTTP checks), plus a local MySQL-compatible server. The application does not require XAMPP. TiDB Cloud compatibility is not yet verified.
2. In MySQL Workbench, create a **new, empty** database for this installation, for example:
   ```sql
   CREATE DATABASE festora CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
   Do not select an existing database containing tables or data.
3. Configure `FESTORA_DB_HOST`, `FESTORA_DB_NAME`, `FESTORA_DB_USER`, and `FESTORA_DB_PASS` in your private local environment, or define the corresponding `DB_*` constants in a private `config.local.php`. That file is excluded by `.gitignore`; never put real credentials in `config.php`, Docker build arguments, or source control. Use a restricted database account, not a root account. There is no root/empty-password fallback; incomplete configuration fails closed.
4. Run schema migrations from a terminal in the project directory:
   ```
   php scripts/migrate.php --database=festora
   ```
   Replace `festora` with the exact new database name. The target database must already exist. On first run it must contain no tables, views, routines or events. The command is CLI-only; it is not an application page or API.
5. To populate an empty **development** database with demo records, set `FESTORA_ENV=development` for the CLI process and run:
   ```
   php scripts/seed-demo.php --database=festora
   ```
   PowerShell example: `$env:FESTORA_ENV = 'development'; php scripts/seed-demo.php --database=festora`.
   Demo seeding is separate from schema migration, requires every Festora application table to be empty, and refuses to run outside development. It is optional; do not seed production.
6. To start the local PHP server, use its protected-file router:
   ```
   php -S localhost:8000 -t public router.php
   ```
   Then open `http://localhost:8000/`. The project root and router are private; only `public/` is the web root. See [docs/deployment.md](docs/deployment.md) for the Render Apache configuration and TiDB Cloud compatibility gates.

The migration account needs permission to inspect its target schema and create the migration-history table and application tables. Grant only the database privileges needed for the planned migrations; use a separate, restricted runtime account in production where deployment configuration supports it.

### Administrator provisioning

The interface calls privileged accounts organizers; the stored role remains `organizer` for compatibility with existing accounts. The “Admin” terminology describes the current organizer capabilities and is not a second role. Do not rename roles without a product/data migration decision.

After migrations are complete, provision an initial organizer using the CLI-only utility. Provide the password through standard input (not as a command-line argument or source code); the utility hashes it and refuses duplicate email addresses:

```
php scripts/provision-admin.php --database=festora --email=admin@example.edu --name="Festora Admin" --password-stdin
```

Pipe a password from an approved local secret manager or other protected input method. Do not use a command such as `echo password` that records a password in shell history. The target database must have the verified migration ledger and expected schema. The command is denied over HTTP.

### Database safety

The versioned files in `database/migrations/` are the fresh-install and upgrade workflow. Do not import a reset dump into an existing or production database. SQL dumps are excluded from the runtime image; only the migration files are included.

### Upgrades and migration safety

- Every schema change for installations created by the migration workflow must be a new, consecutively numbered SQL file in `database/migrations/`. Never edit a migration that has already been applied.
- Before upgrading, make and verify a restorable database backup, test the new migration against a restored disposable copy, then run `php scripts/migrate.php --database=YOUR_DATABASE`.
- The CLI records each migration version and SHA-256 checksum in `festora_schema_migrations`. Re-running the command skips verified applied migrations. Missing, changed, unknown, failed or incomplete migration history is rejected.
- MySQL DDL statements can implicitly commit. The runner records a migration as started before executing its SQL and marks failures when possible, but it cannot promise transactional rollback. If a migration fails, it may have partially changed the schema; the runner blocks automatic retry. Stop, preserve the database, and restore a verified pre-migration backup or obtain a DBA-reviewed recovery plan. Do not delete migration-history rows to force a retry.
- The runner refuses to initialize an untracked non-empty database. Existing installations created with the legacy reset script have no migration history and are not automatically adopted or upgraded. Back them up and arrange a separately reviewed schema-baseline/adoption procedure before using versioned upgrades.

### Environment: development vs production

Festora is treated as **production** unless the environment variable `FESTORA_ENV` is set to exactly `development`. Unset, empty, misspelled or any other value means production. The Host header or a loopback address never switches it on.

- PowerShell: `$env:FESTORA_ENV = 'development'; php -S localhost:8000 -t public router.php`
- macOS/Linux: `FESTORA_ENV=development php -S localhost:8000 -t public router.php`

### Demo data (development only)

Demo data is optional and may only be seeded into an empty development database. The seeder refuses to run outside development. Never seed production or expose development account credentials.

### Login rate limiting

Failed and repeated logins are throttled with state kept in files, not the database: 5 failures per 15 minutes per account + IP, 20 failures per 15 minutes per account, and 30 failures per 15 minutes per IP. The state and its HMAC secret live outside the project and web root: `%LOCALAPPDATA%\Festora` on Windows, `$XDG_STATE_HOME/festora` or `~/.local/state/festora` elsewhere, or the absolute path in `FESTORA_STATE_DIR`. If that storage cannot be used, login is refused (fails closed) rather than left unlimited. Behind a reverse proxy the client IP is the proxy's address, because forwarded headers are not trusted.

### Regression tests

Run the complete repeatable regression suite from the project directory:

```
php tests/run.php
```

The suite requires the PHP extensions listed above and a dedicated local MySQL test account with permission to create and drop databases. It generates random `ftest_...` database names, checks each name is absent before creation, tests migrations/seeding/concurrency against those disposable schemas, then drops only the databases it created. It uses the configured database host, so never point it at TiDB Cloud, production, or a server containing data that must be preserved. Do not interrupt the suite during cleanup; if interrupted, inspect the exact disposable names before removing any abandoned test databases.

## Folder structure

```
festora/
├── public/                               sole intended HTTP document root
│   ├── index.php, events.php, event.php  public route wrappers
│   ├── register.php, my-registrations.php
│   ├── login.php, signup.php, logout.php
│   ├── admin/*.php                       organizer route wrappers
│   ├── api/events.php                    JSON route wrapper
│   └── assets/                           public CSS, JavaScript and SVGs
├── index.php, events.php, event.php      private page controllers
├── register.php, my-registrations.php
├── login.php, signup.php, logout.php
├── admin/, api/                          private controllers
├── includes/                             bootstrap (DB, session, CSRF), header, footer, card
├── database/migrations/                  versioned schema-only SQL migrations
├── scripts/migrate.php                   CLI-only migration runner
├── scripts/seed-demo.php                 CLI-only development demo seeder
├── scripts/provision-admin.php           CLI-only organizer provisioning
├── tests/                                CLI-only DB, security and HTTP regression suite
├── scripts/db-common.php                 shared CLI database safeguards
├── config.php, config.local.php          private runtime configuration
└── router.php                            private local-development router
```

## Database design (ER summary)

```
users (1) ──< events            organizer_id
users (1) ──< registrations >── (1) events      UNIQUE(event_id, user_id)
events (1) ──< schedule_items
events (1) ──< announcements    event_id nullable = general announcement
```

Tables: `users`, `events`, `registrations`, `schedule_items`, `announcements`. Foreign keys use `ON DELETE CASCADE`.

## Security measures

- Prepared statements (PDO) everywhere, so no SQL injection
- `password_hash` / `password_verify`; session ID regenerated on login
- Output escaped with `htmlspecialchars`
- CSRF token on every POST form
- Role checks on all `admin/` pages; open-redirect protection on login `next`
- Server-side validation mirrors client-side validation
- Row lock (`SELECT ... FOR UPDATE`) prevents overbooking the last seat
- Database credentials are kept out of Git: `config.local.php` is git-ignored; `config.php` holds no secrets

## Testing checklist

1. Sign up with invalid data (short password, bad email), then confirm errors appear in the browser and on the server.
2. Log in as student, register for an event, then confirm it appears in My Registrations.
3. Try to register twice, then confirm the duplicate is blocked.
4. Register until "AI in Everyday Life" (capacity 4) is full, then confirm further registration is refused.
5. Cancel a registration, then confirm the seat count goes back up.
6. As a student, open `/admin/index.php`, then confirm access is denied (403).
7. As organizer, create, edit and delete an event; add schedule items; post an announcement; export CSV.
8. Search "code", filter by "Workshop", then confirm results update without a page reload.
9. With JavaScript disabled, confirm event listing and GET search still render usable results. The automated suite covers server-rendered fallback, but does not replace manual keyboard/screen-reader/responsive review.
10. Run `php tests/run.php` for automated disposable-database, concurrency, CLI, session, CSRF, HTTP route, and core-flow checks. Never use the configured application database as a destructive test target.

## Deployment and optimization

- `public/` is the sole intended document root; controllers, configuration, database files, migrations, scripts and tests remain outside it. Use `php -S localhost:8000 -t public router.php` locally. The repository test suite verifies all routes through that root and checks private-path denials, but the Render image and TiDB connection requirements still need target-specific validation. See [docs/deployment.md](docs/deployment.md) for the Render deployment gates.
- Render builds the PHP 8.4 + Apache image from the root `Dockerfile`; Apache serves only `public/`. Set application secrets in Render's environment settings, never in the image or build arguments. Review [docs/deployment.md](docs/deployment.md) before deployment: TiDB connection TLS/port requirements, migration compatibility, and persistent session/rate-limit storage are still unresolved gates.
- No file upload handler or upload storage exists. Event images are external URLs only. Do not add uploads without private storage, content validation, and non-executable file serving.
- SEO basics already included: semantic HTML, per-page `<title>` and meta description, `lang` attribute, viewport tag.
- Performance: no external libraries, a single CSS and a single JS file, indexed queries.

