# Yorilife ERP — Architecture

## Overview

```
 React web app (frontend/)          Android / iOS apps (future)
          │  JSON over HTTPS                 │
          └──────────────┬───────────────────┘
                         ▼
             PHP REST API (backend/public/index.php)
                         │  PDO
                         ▼
                       MySQL
```

The frontend and backend are independent projects that communicate only over the REST API. The backend has no knowledge of React, so the same endpoints will serve the mobile apps.

## Frontend

| Concern    | Choice |
| ---------- | ------ |
| Build      | Vite 8, React 19, JavaScript (ES modules) |
| UI kit     | Bootstrap 5.3 compiled CSS, themed via CSS variables (no Sass build) |
| Icons      | Bootstrap Icons (font) — no other icon library existed in the project |
| Font       | Poppins 400/500/600/700 via `@fontsource/poppins` (self-hosted, no Google Fonts request) |
| Routing    | React Router 7 data router (`createBrowserRouter`). Routes are generated from `src/config/navigation.js`, which also drives the sidebar, breadcrumbs and page titles |
| API access | Relative `/api/...` URLs; Vite dev server proxies to `localhost:8000` |

### CSS layers (`src/styles/main.css`)

1. **Bootstrap** and **Bootstrap Icons**, then **Poppins**.
2. **variable.css**: every colour, size, radius, shadow and layout value as CSS variables. Also bridges Bootstrap's `--bs-*` root variables to Yorilife tokens, so utilities such as `.text-primary` and `.bg-success-subtle` stay on-brand.
3. **theme.css**: all Yorilife styles, in numbered sections that must keep this order:
   1. *Reset*: media defaults, `overflow-wrap`, `:focus-visible` outline, `prefers-reduced-motion`.
   2. *Typography*: Poppins, heading scale built from the fluid `--font-size-*` tokens, `.eyebrow`.
   3. *Layout*: `.app-shell`, `.app-body`, `.app-sidebar` (+ `.sidebar-group`, `.sidebar-link`), `.app-header` (top bar), `.app-brand`, `.app-container`, `.page-header`, `.page-section`.
   4. *Components*: one theme per Bootstrap component (buttons, forms, cards, tables, badges, alerts, dropdowns, modals, pagination, breadcrumbs, tooltips) plus `.search-field`, `.table-container`, `.status-badge`, `.empty-state`, `.loading-state`, `.skeleton`.
   5. *Utilities*: helpers Bootstrap lacks (`.min-w-0`, `.truncate`, brand text colours, …).
   6. *Responsive*: mobile-first overrides at Bootstrap breakpoints (576 / 768 / 992). Always last.

Naming: Bootstrap class names are kept as-is. Custom blocks use BEM-style names (`.app-brand__logo`, `.empty-state__title`). State classes use `is-*` (`.is-open`, `.is-active`, `.is-sidebar-collapsed`).

### Fluid typography

`--font-size-xs … --font-size-3xl` are `clamp(min, rem + vw, max)` values that grow linearly between 320px and 1440px viewports:

| Token | 320px → 1440px | Used for |
| ----- | -------------- | -------- |
| `xs` | 12 → 13px | captions, badges, footer, form helper text |
| `sm` | 13 → 14px | labels, nav links, breadcrumbs, small buttons |
| `base` | 14 → 15px | body, buttons, inputs, tables |
| `md` | 15 → 17px | card titles, top-bar title |
| `lg` | 16 → 18px | `h4`, section titles, modal titles |
| `xl` | 17 → 20px | `h3` |
| `2xl` | 19 → 24px | page titles (`.page-header__title`), `h2` |
| `3xl` | 22 → 30px | `h1` outside the page header |

The rem part keeps browser zoom working, and media queries never set font sizes.

### Preview data layer (temporary)

Until the database design ([DATA_MODEL.md](DATA_MODEL.md)) is approved, the business screens read and write an in-memory store:
- `ErpDataProvider` holds `{ state, actions }`;
- `erpReducer` is the pure reducer, implementing soft delete and history.

Data lasts for one browser tab and is never sent to the API. To switch to the API, replace the action implementations with `fetch` calls to the PHP backend and load `state` from it. Pages and components don't need to change. Business rules that are not confirmed (final-value formula, report totals, permissions) are deliberately absent from this layer.

### Navigation model

`config/navigation.js` exports `NAV_MENU` and two flattened lists, `NAV_ITEMS` (pages) and `NAV_GROUPS` (groups and subgroups), plus `getRouteMeta(pathname)`:
- **`NAV_MENU`** is the ordered sidebar: top-level links, groups, and groups nested one level deep. Pages have `path`, `label`, `icon`, `description` and an optional `permission`.
- **`getRouteMeta`** returns the page's title, breadcrumbs and `activeIds` (the groups to expand and highlight). `router.jsx`, `Sidebar.jsx`, `Topbar.jsx`, `PageShell.jsx` and `AppLayout.jsx` (for `document.title`) all read from it, so a label or path is defined exactly once.

### Brand palette

| Token | Value | Use |
| ----- | ----- | --- |
| `--color-primary` | `#24AEB1` | Brand teal: accents, focus ring, icons, header stripe |
| `--color-primary-600` | `#1A7F81` | Filled buttons, links, checked controls (4.78:1 with white) |
| `--color-primary-700` | `#156769` | Hover/active |
| `--color-secondary` | `#373F85` | Brand navy: secondary buttons, ERP label (9.49:1 with white) |
| `--color-accent` | `#EB3A7B` | Website accent pink. Use sparingly |
| `--color-text` | `#1D2A38` | Body and headings (website heading colour) |
| `--color-text-muted` | `#5F6B7E` | Secondary text (5.40:1) |
| `--color-text-subtle` | `#7D879C` | Placeholders and meta only (website grey, 3.61:1) |
| `--color-background` | `#F5F8FA` | Page background |
| `--color-surface` | `#FFFFFF` | Cards, header, tables |
| `--color-border` | `#E3E8EE` | Dividers and borders |
| success / warning / danger / info | `#1F8A5B` / `#D98E04` / `#D23C47` / `#3165C8` | Status, with `-bg` and `-text` pairs (all ≥ 6.4:1) |

Sources: the brief (primary, secondary) and yorilife.in (teal `#24AEB1`, hover teal `#1C8687`, heading `#1D2A38`, grey `#7D879C`, pink `#EB3A7B`). `#1A7F81` was chosen instead of the site's `#1C8687` (4.37:1) because it reaches WCAG AA for normal-size text.

### Logo

`frontend/public/assets/images/yorilife-logo.png` is the original 792×365 PNG from the yorilife.in header, byte-identical (SHA-256 `FB096428…D75F`). It is rendered only through `BrandLogo.jsx`, with fixed height and `width: auto`, so the aspect ratio is always preserved: 30px tall on phones, 36px on small tablets, 40px from 768px.

## Backend

No framework. A small, conventional structure that a framework could replace later without changing the API contract.

```
backend/
├── public/            Web root — index.php (front controller) + .htaccess
├── bootstrap.php      Autoload, .env, error handling, DB config
├── bin/               CLI: migrate.php, create-admin.php
├── config/            app.php, database.php, auth.php (sessions, throttling, password rules),
│                      permissions.php (role → permission map)
├── routes/api.php     Route table (+ per-route guards)
├── controllers/       HTTP layer: Health, Auth, User (App\Controllers)
├── services/          AuthService, UserService, Authorization, PasswordHasher, UserValidator, UserPresenter
├── middleware/        Cors, Origin, Auth (authenticated + can) (App\Middleware)
├── repositories/      SQL per table: User, Role, LoginAttempt (App\Repositories)
├── database/          PDO factory, Migrator, migrations/*.sql
├── utils/             Env, Router, Request, HttpException, JsonResponse, Session
├── storage/           sessions (git-ignored, not web-accessible)
└── tests/             PHPUnit: Unit + Feature (real HTTP against a test server)
```

### Request flow

`public/index.php` → `bootstrap.php` → `Session::configure` → `CorsMiddleware` (preflight) → `Router` → global `OriginMiddleware` → route guards (`AuthMiddleware::authenticated()`, `AuthMiddleware::can('…')`) → `Controller` → `Service` → `Repository` → `JsonResponse`. Any guard or service can throw `HttpException`, which the router turns into the standard JSON error.

### Migrations

`php bin/migrate.php` applies `database/migrations/*.sql` in name order and records each in `schema_migrations`:
- **Forward-only:** there are no down/drop steps.
- **`--status` is read-only:** it shows the target database, its existing tables and any pending files.
- **MySQL commits DDL immediately:** a failing file stops the run and stays unrecorded, so it can be fixed and re-run.

Fictional Sales Office demo rows are loaded with `php bin/seed-demo.php` from `backend/`. The chain is Shyam (`ER-M3-001`), four 2nd line managers, four 1st line managers under Shubham, two headquarters under Aarav Mehta (`HQ 1` with three stockists and `HQ 2` with two), and no offices under Ali, Mohit, or Raj. `GET /api/erp` includes that tree as `hierarchy`. The seeder updates the current demo codes and deletes only older `DEMO-HQ`, `DEMO-M`, and `DEMO-STK` rows that are no longer in that set, including sales rows that belong only to a removed demo stockist. It refuses `APP_ENV=production` and any database name ending in `_test`. A second run updates the same rows and creates none.

## Authentication & authorization

### Why server-side sessions (not JWT)

The browser always talks to one origin: in development Vite proxies `/api`, and in production the app and `/api` are served from the same domain. So a classic server session is the simplest secure option:
- **Opaque cookie:** the browser holds only a random, HttpOnly session ID.
- **Instant revocation:** signing out, disabling an account or changing a role takes effect on the very next request.
- **No tokens in JavaScript:** nothing is kept in `localStorage`.

A future mobile app sends `Authorization: Bearer` with a personal device token (see below). It does not use the session cookie or a CSRF token. Web sign-in stays a cookie session.

### Endpoints

| Method & path | Guard | Purpose |
| ------------- | ----- | ------- |
| `POST /api/auth/login` `{email, password}` | public, throttled | Starts a session; returns `{user, csrfToken}` |
| `POST /api/auth/logout` | signed in + CSRF | Destroys the server session, expires the cookie |
| `GET /api/auth/me` | signed in | Current user, permissions and CSRF token. The frontend calls it on every page load |
| `GET /api/roles` | `users.view` | Roles, with `assignable` for the current user |
| `GET /api/users`, `GET /api/users/{id}` | `users.view` | List / view (never includes password hashes) |
| `POST /api/users` `{name, email, role, password}` | `users.create` + CSRF | Create a user |
| `PATCH /api/users/{id}` any of `{name, email, role, status}` | per field (below) + CSRF | Edit, change role, activate/disable |
| `GET /api/erp` | `sales_office.view` | All business records for the screens |
| `GET /api/search?q=` | signed in | Records the user may view: sales office, catalogue, products, financial years, sales entries. Users only when the caller has `users.view`. Omits password hashes and sales amounts |
| `GET/POST /api/headquarters`, `PATCH /api/headquarters/{id}`, `POST /api/headquarters/{id}/delete` | view / `sales_office.manage` | Headquarters office: name, state, optional link to a location-headquarters name, 1st line manager, address, pincode, phone, email, unique code. New codes are `HQ-` plus digits. Delete also removes that office's stockists and sales entries. The location headquarters record stays |
| `POST /api/location-headquarters`, `PATCH /api/location-headquarters/{id}`, `POST /api/location-headquarters/{id}/delete` | `sales_office.manage` | Location headquarters name, `HQ-` code, address, pincode, phone, and email for one state. Saving does not create an office. Delete is refused while an office still links to it |
| `GET/POST /api/locations`, `PATCH /api/locations/{id}`, `POST /api/locations/{id}/delete` | view / `sales_office.manage` | Location states, codes, and their headquarters names. Delete is refused while a manager or a headquarters is assigned |
| `GET/POST /api/managers`, `PATCH /api/managers/{id}`, `POST /api/managers/{id}/delete` | view / `sales_office.manage` | Manager hierarchy. A 3rd line manager can store optional email and phone, and location state ids. A 2nd line manager stores a subset of that parent's states. A 1st line manager stores a subset of the 2nd line manager's states, plus an optional email and phone. Delete is refused while a child manager or headquarters still points at the record |
| `GET/POST /api/stockists`, `PATCH /api/stockists/{id}`, `POST /api/stockists/{id}/delete` | view / `sales_office.manage` | Stockists linked only to a headquarters. A new code is `ST-` plus digits. Address, pincode, phone, and email are optional. Delete also removes that stockist's sales entries |
| `GET/POST /api/catalogue-terms`, `PATCH …/{id}` | view / `catalogue.manage` | Group categories and product categories. A category requires `parentId` |
| `POST /api/catalogue-terms/{id}/delete` and `/restore` | `catalogue.manage` / `catalogue.restore` | Soft delete is refused while an active category or product still depends on it. Admin restore |
| `GET/POST /api/products`, `PATCH /api/products/{id}` | view / `products.manage` | Product identity, group, category and current scheme. One history row per save |
| `POST /api/products/{id}/delete` and `/restore` | `products.delete` / `products.restore` | Soft delete and Admin restore. Restore is refused when the code is used by another active product |
| `POST /api/financial-years` `{label}` | `financial_years.manage` | April–March year. Starts with no sheet rows |
| `PUT /api/master-sheet/{year}/{productId}` | `master_sheet.update` | That year’s strip pack, MRP, PTR, PTS and Included/Excluded. A scheme in the same request updates the product once |
| `GET /api/master-sheet/{year}/scan` | `master_sheet.update` | Lists active products missing from that year. Does not insert |
| `POST /api/master-sheet/{year}/add-products` | `master_sheet.update` | Adds only the selected missing products. Inclusion starts unset |
| `POST /api/master-sheet/{year}/update-products` | `master_sheet.update` | Adds every missing active product. Does not change existing year rows |
| `GET/POST /api/sales-entries`, `PATCH /api/sales-entries/{id}` | view / `sales.create` or `sales.update` | One entry per stockist per month. `finalValue` is stored, never calculated. No delete |

Errors use the standard envelope:
- **401** `unauthenticated` / `session_expired` / `invalid_credentials`;
- **403** `forbidden` / `account_disabled` / `csrf_token_invalid` / `origin_not_allowed` / `cannot_change_own_role` / `cannot_change_own_status` / `role_not_assignable` / `cannot_manage_user`;
- **415** for a non-JSON body, **422** `validation_failed` (with `details.fields`), and **429** `too_many_attempts` (with `Retry-After`).

### Session handling (`utils/Session.php`, `services/AuthService.php`)

- **Native PHP sessions** stored in `backend/storage/sessions`.
- **Hardened settings:** `use_strict_mode` (forged IDs rejected), cookies only (never IDs in URLs), and 160-bit IDs.
- **Cookie:** `HttpOnly`, `SameSite=Lax`, `Path=/`. `Secure` plus the `__Host-` name prefix whenever `APP_ENV` isn't `local`.
- **On sign-in:** `session_regenerate_id(true)`, which prevents session fixation.
- **Expiry:** 30 minutes idle, and 12 hours absolute regardless of activity (both configurable). An expired session is destroyed on the server.
- **Re-checked on every request:** the user is reloaded from the database, so a disabled account or changed role takes effect immediately. A password reset, a lock or a disable also drops that user's other sessions and device tokens (`users.auth_epoch` plus `user_sessions`).
- **Authenticator app (optional):** TOTP, 6 digits, 30-second step, with one-time recovery codes. A Super Admin can require it per role under Security settings. Until the code is entered, or until setup is finished when the role requires it, only the sign-in completion routes are available. Enable, disable and use are audited. The secret and the recovery codes are not.
- **Device tokens:** `POST /api/auth/tokens` returns a `ylt_…` token once. Later calls send `Authorization: Bearer`. The hash, prefix, expiry and last use are stored. Create, revoke, first use and a rejected token are audited.
- **Retention:** each data type has a number of days in Settings. `POST /api/retention/apply` (or `php bin/archive-retention.php`) marks older rows archived. It does not delete them.
- **Privacy (DPDP):** consent records, a person's own export (`GET /api/privacy/me`), correction-request tracking, and anonymise-on-request. Anonymise masks personal fields on the existing row. It does not delete the row and does not rewrite the audit hash chain.
- **Audit chain:** each row links to the previous hash. `audit_chain_head` stores the newest hash separately, so deleting the newest rows is reported as `tail_truncated`.
- **No locking delay:** the session is closed right after reading, so a user's parallel requests aren't serialised.

### Request forgery protection

- **CSRF token:** each session has a random 256-bit token. It is returned by login/me, kept in the frontend's memory only, and required as `X-CSRF-Token` on every authenticated POST/PUT/PATCH/DELETE (compared with `hash_equals`).
- **Origin check:** a state-changing request carrying an `Origin` header must come from `APP_URL` or `CORS_ALLOWED_ORIGINS`.
- **JSON-only bodies:** request bodies must be `application/json`, which a cross-site HTML form can't send without a CORS preflight.
- **CORS:** credentials are allowed only for the exact listed origins, never `*`.

### Passwords and sign-in

- **Hashing:** `password_hash` with bcrypt at cost 12 (`PasswordHasher`). There is no custom cryptography, and older hashes are upgraded automatically on the next sign-in.
- **Password policy:** 12 to 72 bytes, because bcrypt ignores anything past 72 and a longer password is rejected rather than silently truncated.
- **No account discovery:**
  - Unknown emails are checked against a real dummy hash, so response times match.
  - Wrong password and unknown email return the same message.
  - "Account disabled" is only revealed after a correct password.
- **Throttling** (`login_attempts` table, identity stored as SHA-256): 5 failures per account and 30 per IP within 15 minutes give 429. A successful sign-in clears that account's failures.
  - Only `REMOTE_ADDR` is trusted. Behind a reverse proxy, the IP limit needs trusted-proxy configuration (not built).
- **Nothing sensitive is returned or logged:**
  - Passwords, hashes, session IDs and CSRF tokens are never in responses (except the user's own CSRF token) and never in URLs or logs.
  - `UserPresenter` whitelists the user fields that can be returned.

### Roles and permissions (`config/permissions.php`, `services/Authorization.php`)

One role per user (`users.role_id`). Roles are rows in `roles`; their permissions are a small map in code. A permissions table is deliberately not built yet, because the only permissions that exist are for user management.

| Role | Rank | Permissions (Sprint 3) |
| ---- | ---- | ---------------------- |
| Super Admin | 100 | all (`users.view/create/update/change_status/assign_role`) |
| Admin | 80 | all user-management permissions, limited by rank |
| Sales Manager | 50 | none yet (can use the app shell) |
| Sales User | 10 | none yet (can use the app shell) |

Rules, enforced on the server for every request:
- **Permission per operation.**
- **Rank limits:** you may only manage accounts, and assign roles, ranked *below* yours. Super Admin may manage everyone.
- **Nobody changes their own role or status.**
- **Unknown request fields are rejected,** e.g. `password_hash` or `id`.
- **Role values from the client are ignored;** the role always comes from the database.
- **Disabled users have no permissions** and can't sign in.

The frontend hides menu items and buttons the user can't use. That's convenience only: the API rejects such requests anyway, with 403.

### Frontend

- **`api/client.js`:** `fetch` with `credentials: 'same-origin'`. It adds the CSRF header and turns errors into `ApiError`. A 401 tells the auth layer the session ended.
- **`auth/AuthProvider`:** states `loading → authenticated | unauthenticated (reason: expired | signed_out) | error`. It is restored by calling `/api/auth/me` on every load, never from a stored flag.
- **Route protection:**
  - `auth/RequireAuth` wraps the whole ERP shell and redirects to `/login?next=…`.
  - `/login` is outside the shell.
  - `safeRedirect` only allows in-app paths, which prevents open redirects.
  - `RequirePermission` shows a "no access" page for items with a `permission` in `navigation.js`.
- **Preview data reset:** the in-memory preview store is re-created per signed-in user.

### Data import (Excel), history and rollback

Super Admin and Admin (`imports.manage`) import every Sales Office list from one Excel workbook (menu: Sales Office > Data Import). Data is read from the sheet named `Import` (or the only sheet); an Instructions sheet is allowed. Columns: Data Type, Code, Name, Parent Code, State List, Address, Pincode, Phone Number, Email, Notes. Rows can be in any order: they are applied Location, Location HQ, 3rd, 2nd and 1st line managers, Headquarters, Stockist, so a parent in the same file is saved before its children, and a child of a failed row fails with "Parent row N has errors". State lists are separated with `|`. An existing code updates that record; a new code creates one. A blank optional cell keeps the stored value; a value in a column that does not apply to the Data Type is ignored with a warning.

- **Template** `GET /api/imports/template`: `yorilife-import-template.xlsx` with an Import sheet (every cell formatted as text, so leading zeros survive) and an Instructions sheet.
- **Upload** `POST /api/imports` (multipart `file`, optional `type=unified`): `.xlsx` only (`.xls`, `.xlsm`, macros rejected), up to 10 MB and 2000 data rows. `ImportXlsx` reads it with ext-zip and XMLReader: no DTD/external entities, entry-count, unpacked-size and compression-ratio limits, formulas are never evaluated (a formula or error cell fails its row), numbers lose scientific notation, hidden rows are imported with a warning. The file is stored as `storage/imports/{id}/original.xlsx` (path: `IMPORT_STORAGE_PATH`) with its SHA-256. Every row is validated through `SalesOfficeService` inside a transaction that is always rolled back, so the preview saves nothing.
- **Confirm** `POST /api/imports/{id}/confirm`: verifies the file hash, writes `backup.json` (locations, location headquarters, managers, manager_states, headquarters, stockists) with its SHA-256, then applies the valid rows in one transaction (a savepoint per row). Each created or updated record is stored in `import_changes` with before/after snapshots.
- **Rollback** `POST /api/imports/{id}/rollback/preview` and `/rollback` (optional `changeIds`): one or more runs per import while records are still pending, newest change first, each run in one transaction. Records not selected stay pending for a later run; a record is rolled back at most once (restored, deleted and skipped records are never offered again, and selecting one is a 422). The list and detail expose `canRollback`, `pendingRollback` and `rollbacks` (one entry per run from `import_rollbacks`: who, when, counts and the records with Restore/Delete), shown nested under the import row in Import History. Updated records are restored; created records are deleted. A record changed after the import, missing, or (when created) now referenced by other records is skipped and reported. Status: `rolled_back` once nothing is pending or skipped, otherwise `partially_rolled_back`.
- **Downloads** `GET /api/imports/{id}/file` (the original workbook), `/errors` (CSV with Row, Data Type, Column, Error and the row's cells), `/backup` (`import-{id}-backup.csv`: only the existing records the import replaced, one row each with Data Type, Code, a Previous/New pair for Name, Parent Code, State List, Address, Pincode, Phone Number and Email, Changed Fields and rollback Notes; created records are not listed, and an import that replaced nothing gets a single note row; `backup.json` itself stays on disk for rollback and is never sent). CSV downloads neutralise formula cells (`=`, `+`, `-`, `@`, tab); the backup is served only if its hash matches.
- **Audit**: `import_audit_logs` records upload, import, rollback and each download with user, IP and time. Tables: `imports`, `import_errors`, `import_changes`, `import_audit_logs` plus `import_rollbacks` (migrations `2026_11_03_000017`, `2026_11_04_000018`, which adds `data_type` to `import_errors` and `import_changes`, and the additive `2026_11_05_000019` for `import_rollbacks`). Imports made with the earlier per-list CSV flow stay in Import History and can still be downloaded and rolled back; they can no longer be confirmed.

### API conventions

- Base path `/api`. Resource-oriented URLs; versioning (`/api/v1`) to be decided before the mobile app ships.
- Response envelope:

  ```json
  { "success": true,  "data": { } }
  { "success": false, "error": { "code": "not_found", "message": "Endpoint not found." } }
  ```

- Status codes: 200/201 success, 204 preflight, 400 invalid JSON, 401 not signed in / session ended, 403 not allowed, 404 unknown route or record, 405 wrong method (with `Allow` header), 415 non-JSON body, 422 validation (`details.fields`), 429 throttled (`Retry-After`), 500 unexpected, 503 dependency unavailable.
- Headers on every JSON response: `Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`.

### Security baseline

- Configuration only from environment variables. `.env` is git-ignored, and `.env.example` holds placeholders only.
- `display_errors` is always off. Uncaught exceptions are logged and returned as a generic 500 (the message is included only when `APP_DEBUG=true`).
- Database failures are logged server-side. Clients receive only `database_unavailable`.
- PDO with `ERRMODE_EXCEPTION`, native prepared statements (`EMULATE_PREPARES=false`), `utf8mb4`.
- CORS allows only origins listed in `CORS_ALLOWED_ORIGINS`.
- Only `backend/public/` should be web-accessible (`storage/`, `.env`, `bin/` must not be).
- Authentication, sessions, CSRF and RBAC: see [Authentication & authorization](#authentication--authorization).
- `/api/health` and `/api/health/database` remain public (no session is started for them). Whether to restrict the database check in production is an open decision.
