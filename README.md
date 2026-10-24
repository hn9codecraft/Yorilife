# Yorilife ERP

ERP system for **Yorilife Sciences Pvt. Ltd.** — a React web app backed by a PHP REST API that will also serve the future Android/iOS apps.

```
Yorilife/
├── frontend/   React 19 + Vite 8 + React Router 7 + Bootstrap 5.3 (web ERP)
├── backend/    PHP 8.3 REST API (no framework), MySQL via PDO
└── docs/       Sprint plan, architecture, data model
```

Current status: **Sprint 3 (authentication and user roles) is complete.**
- **Signing in:** every ERP page requires sign-in, with a server-side session and role-based access. Super Admins and Admins can manage users.
- **Business screens:** the Sprint 2 screens still run on an in-memory **preview only** and are not saved.
- **Where the details are:**
  - architecture and security: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md);
  - status and open decisions: [docs/SPRINT_PLAN.md](docs/SPRINT_PLAN.md);
  - tables: [docs/DATA_MODEL.md](docs/DATA_MODEL.md);
  - frontend: [frontend/README.md](frontend/README.md).

## Requirements

| Tool     | Version tested          | Notes                                    |
| -------- | ----------------------- | ---------------------------------------- |
| Node.js  | 24.14.1 (npm 11.11.0)   | Node 20.19+ or 22.12+ required by Vite 8 |
| PHP      | 8.3.30                  | Extensions: `pdo`, `pdo_mysql`, `json`, `mbstring`, `session` (`curl` for tests) |
| Composer | bundled with Laragon    | Autoloading + PHPUnit (dev)              |
| MySQL    | 8.4.3 (Laragon)         | **Required** — sign-in data lives here   |
| Chrome   | any current             | Only for the browser end-to-end tests    |

On this machine PHP is not on `PATH`. Laragon's binary is at
`C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`. Use the Laragon terminal or add that folder to `PATH`.

## First-time setup

1. **Start MySQL** (Laragon: **Start All**).
2. **Create the databases and an app user.** Use your own strong password, and never use `root` outside local development:

   ```sql
   CREATE DATABASE yorilife_erp      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE DATABASE yorilife_erp_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;  -- automated tests only
   CREATE USER 'yorilife_app'@'localhost' IDENTIFIED BY 'choose-a-strong-password';
   GRANT ALL PRIVILEGES ON yorilife_erp.*      TO 'yorilife_app'@'localhost';
   GRANT ALL PRIVILEGES ON yorilife_erp_test.* TO 'yorilife_app'@'localhost';
   ```

   In production, run migrations with a separate account that has schema rights, and give the app account only `SELECT, INSERT, UPDATE, DELETE`.
3. **Configure the backend:**
   ```bash
   cd backend
   composer install
   cp .env.example .env          # set DB_USERNAME / DB_PASSWORD; see comments for session settings
   php bin/migrate.php --status  # shows target database, existing tables, pending migrations
   php bin/migrate.php           # applies pending migrations (never drops anything)
   ```
4. **Create the first Super Admin.** This is a one-time step. The command refuses to run if a Super Admin already exists or the email is taken, and it never accepts the password as a command-line argument:
   ```bash
   php bin/create-admin.php --email=owner@yorilife.in --name="Full Name"
   # prompts for the password twice (12+ characters).
   # On Windows the console shows what you type; alternatively:
   php bin/create-admin.php --email=… --name="…" --password-stdin < password.txt   # then delete the file
   ```
   No default accounts or passwords exist. Further users are created by an administrator on **System & Audit → Users**.

## Running locally

```bash
# Terminal 1 — API
cd backend
php -S localhost:8000 -t public public/index.php      # or: composer serve

# Terminal 2 — web app
cd frontend
npm install
npm run dev                                           # http://localhost:5173 → sign-in page
```

Vite proxies `/api/*` to `http://localhost:8000`, so the browser sees one origin and the session cookie stays first-party.

| URL | Purpose |
| --- | ------- |
| `GET http://localhost:8000/api/health` | API is running (public) |
| `GET http://localhost:8000/api/health/database` | MySQL connectivity, 200 / 503 (public) |

The auth and user endpoints are listed in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md#authentication--authorization).

For Apache, point the document root at `backend/public/`; the included `.htaccess` routes everything to `index.php`. In production, serve the web app and `/api` from **the same HTTPS domain**, and set `APP_ENV=production`, which turns on Secure cookies.

## Tests

| Command | What it runs | Needs |
| ------- | ------------ | ----- |
| `cd backend && php vendor/bin/phpunit` | 44 unit and API tests (auth, sessions, CSRF, throttling, RBAC, user management) against a PHP server it starts on :8099 | MySQL + `yorilife_erp_test` |
| `cd frontend && npm run lint` | oxlint | — |
| `cd frontend && npm run build` | Production build | — |
| `cd frontend && npm run test:unit` | Node unit tests (safe redirects, CSV parser) | — |
| `cd frontend && npm run test:e2e` | 26 browser tests in Chrome: login, logout, expiry, route protection, user management, Sprint 2 regression, 7 viewport widths. Starts an isolated API (:8098, test DB) and Vite (:5174) | MySQL, Chrome; set `PHP_BIN` if `php` isn't on PATH |

The test suites **wipe users in the test database** before each test. They refuse to run unless the database name ends in `_test`, and they never touch `yorilife_erp`.

On this machine:
```bash
cd frontend
PHP_BIN="C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe" npm run test:e2e
```

## Troubleshooting `/api/health/database`

- `503 … "database_not_configured"`: `DB_DATABASE` or `DB_USERNAME` is empty in `.env`.
- `503 … "database_unavailable"`: the server is unreachable or the credentials are wrong. The real driver error goes to the PHP error log, never to the response.
