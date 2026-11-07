# Yorilife ERP — Sprint Plan

## Sprint 1 — Project setup & design foundation ✅

**Goal:** a clean, extensible foundation. No business modules.

Delivered:

- React 19 + Vite 8 frontend with Bootstrap 5.3, Bootstrap Icons and self-hosted Poppins.
- Modular CSS architecture (later consolidated into `variable.css` + `theme.css`).
- Brand tokens: primary `#24AEB1`, secondary `#373F85`, plus supporting palette from yorilife.in.
- Minimal app shell (logo + ERP name, page header, content container, footer) and a design-system preview page.
- PHP 8.3 API skeleton: front controller, router, env loader, JSON envelope, CORS middleware, PDO connection factory.
- `GET /api/health` and `GET /api/health/database`.
- Setup documentation (root `README.md`) and architecture notes (`docs/ARCHITECTURE.md`).

Explicitly **not** in Sprint 1: authentication, roles/permissions, products and categories, sales office hierarchy, stockist entry, sales and monthly sales, incentives and deductions, announcements and notifications, dashboard metrics, mobile app, business database migrations.

### Open items carried from Sprint 1

| Item | Notes |
| ---- | ----- |
| Logo source | No logo was supplied in the workspace. The official logo was downloaded from yorilife.in's site header (`wp-content/uploads/2023/05/WhatsApp-Image-2023-05-06-at-11.48.56-PM.png`, 792×365) and stored unmodified. Replace it if the client provides a master file (SVG preferred). |
| Favicon | Not created. Needs a square brand mark from the client. The logo must not be cropped or recreated. |
| Original HTML prototype | Not present in the workspace. Sprint 2 sidebar and layout should be checked against it once supplied. |
| MySQL connectivity | Code path verified for "not configured" and "unavailable". A successful connection has not been tested because MySQL was not running. |
| Placeholder contrast | `--color-text-subtle` (#7D879C) is 3.6:1 on white. Use it only for placeholders and meta text. |

## Sprint 2 — Layout, navigation, routing & fluid typography ✅

**Goal:** the shared application shell and navigation for every planned module. No business functionality.

Delivered:

- React Router 7 (data router). Every route is generated from one config file (`frontend/src/config/navigation.js`): 8 menu groups, 31 module routes (since reorganised — see "Sidebar navigation update"), `/` → `/dashboard`, group URLs → first page, `/design-system`, and a 404 page.
- Responsive collapsible sidebar:
  - **Desktop:** persistent, collapsing to an icon rail (the choice is remembered).
  - **Mobile:** off-canvas drawer with an overlay and contained focus.
  - The active group expands automatically, and the current page is marked by more than colour.
- Top bar with sidebar toggle, current section and page, and notification and account placeholders. Profile and Logout are disabled placeholders.
- Breadcrumbs, `<h1>`, description and `document.title` are generated from the route config. One reusable `PageShell`, and one `ModulePlaceholderPage` for all 31 modules.
- Fluid typography: `--font-size-*` tokens converted to `clamp()` (320 → 1440px). Hard-coded heading and icon sizes now use the tokens, and no media query changes a font size.
- Accessibility:
  - skip link;
  - visible focus;
  - `aria-expanded` / `aria-controls` on group toggles and the menu button;
  - `aria-current` on the active page;
  - `inert` page behind the mobile drawer;
  - Escape to close.
- Fixed a Sprint 1 reduced-motion bug: the universal `transition-duration: 0.01ms` rule could leave just-revealed controls briefly unfocusable. It is now `0s`.

Verification: oxlint, production build, and headless Chrome tests. These covered all 31 routes by direct URL, redirects, 404, Back/Forward, collapse, the mobile drawer, keyboard use, and overflow at 320/375/576/768/1024/1280/1440px, with both normal and reduced motion. Backend files are unchanged (checksums compared).

Explicitly **not** in Sprint 2: any business data, forms, CRUD, imports, calculations, authentication, notifications, backend or database changes.

### Open items carried from Sprint 2

| Item | Notes |
| ---- | ----- |
| Original HTML prototype | Still not supplied. The sidebar follows the Yorilife design system. Compare it with the prototype when it is available. |
| Production SPA fallback | The server must serve `index.html` for client routes (see `frontend/README.md`). Not configured yet because there is no production host. |
| Real devices | Verified in headless Chrome only. Not yet checked on physical phones and tablets, or in Safari and Firefox. |

## Sprint 2 update — business requirement screens (preview, not persisted) ✅

**Goal:** turn the agreed business requirements into working screens and a proposed data model, without saving business data or inventing rules.

> **Important:** every screen below runs on an in-memory preview store (`frontend/src/data/`). Records last only for the open browser tab, and every data screen shows a "Preview — not saved" notice. No backend endpoint, database table or migration was added. The proposed schema is in [DATA_MODEL.md](DATA_MODEL.md).

| Requirement | Screens | Status |
| ----------- | ------- | ------ |
| 1A Headquarters | Headquarters list, add/edit, **hierarchy modal** (all managers + mapped stockists) | Working UI (preview) |
| 1B 1st Line Manager → HQ | List, add/edit with required Headquarters select, full reporting line | Working UI (preview) |
| 1C 2nd Line Manager → 1st Line | List, add/edit with required 1st Line Manager select (labelled with its HQ), reporting line | Working UI (preview) |
| 1D 3rd Line Manager → HQ / 1st / 2nd | Parent-type choice + matching parent select, parent type badge, full reporting line to HQ | Working UI (preview) |
| 1E Stockist mapping | Stockist add/edit with required HQ, HQ filter, **stockist hierarchy modal** | Working UI (preview) |
| 2A Tag creation | Tags & Badges page with separate tabs and "Create tag" / "Create badge"; group categories and categories pages | Working UI (preview) |
| 2B Keep deleted terms | Soft delete with confirmation; Active / Deleted filter with deletion date | Working UI (preview) |
| 2C Product soft delete | Product List (add/edit/delete), Deleted Products (read-only), per-product and global change history | Working UI (preview) |
| 3A Included / Excluded | Master Sheet status column, filter and "Update" modal | Working UI (preview) |
| 3B Year-based sheets | Year selector (2024-2025, 2025-2026, 2026-2027); years stored separately | Working UI (preview) |
| 3C Info button + history | Info (ⓘ) modal: date/time, year, field, previous → updated, changed by | Working UI (preview); "changed by" shows *not available* until sign-in exists |
| 4 Bonus / Deduction | Bonus and Deduction fields in sales entry and every sales table | Fields only. **No calculation** |
| 5 Sales entry / lists / reports | Sales entry (add/edit), Monthly, by Headquarters, by Stockist (HQ → stockist) | Working UI (preview). Final value is typed in, not calculated |
| 6 CSV upload | Import Data: file picker, .csv / size / structure checks, preview, results panel; Import History table | Checks run in the browser only. **Nothing is uploaded or imported** |

Not changed: backend, database, authentication, Reports module pages, Sales Calculation History (still placeholders).

Verification: oxlint, production build, and headless Chrome tests. The end-to-end test drives the real UI for every requirement above (59 checks). Navigation regression covered all 31 routes, plus the keyboard and sidebar suite. There was no page overflow at 320–1440px and no console errors. Backend files are unchanged (checksums compared).

### Needs business clarification

These are deliberately **not** implemented as rules or calculations until they are confirmed.

| # | Topic | Question |
| - | ----- | -------- |
| 1 | **"Point 4" bonus / deduction** | What does "4 no point extra bonus deduction" mean exactly? Is it one extra bonus field, one deduction field, or a rule (e.g. applied at a 4-point threshold)? |
| 2 | **Final value formula** | How are Achievement value, Bonus and Deduction combined into Final value? Final value is entered manually until this is confirmed. |
| 3 | Deduction vs "Deduction Value" | Is the point-5 "Deduction Value" the same field as the point-4 deduction? |
| 4 | "Headquarters-wise price" | Does this mean a price list per headquarters, or sales value per headquarters? |
| 5 | Report totals | Should HQ, stockist and monthly views show totals or sub-totals, and which values may be summed? Currently no totals are shown. |
| 6 | Sales granularity | One entry per stockist per month, or per product / invoice? Can a month be edited after it is entered, and by whom? Currency and decimals? |
| 7 | Stockist ↔ manager | Is a stockist mapped only to a headquarters, or also to a specific manager (e.g. a 3rd line manager)? |
| 8 | Manager rules | Can a manager move to a different parent, and should that change be kept in history? Can one person hold two levels? |
| 9 | Record fields | Required fields and unique codes for headquarters, managers, stockists and products (address, phone, GST, pack size, MRP…). |
| 10 | Deleting org records | Can headquarters, managers or stockists be deleted or deactivated? What happens to linked records? |
| 11 | Categories | Resolved: a product category belongs to one group category, and a product has one category. Unmapped legacy categories are not assigned automatically. |
| 12 | Deleting terms in use | May a tag, badge or category used by products be deleted? (The preview allows it and shows it as deleted on those products.) Should deleted records be restorable? |
| 13 | Master Sheet scheme | Resolved: the scheme is the product’s current value (`10+5` stores buy 10 and free 5). Strip pack, MRP, PTR and PTS are per financial year. Past years stay editable. |
| 14 | Permissions | Which roles may create, edit, delete or enter sales? "Administrator" attribution needs authentication. |
| 15 | CSV imports | Which datasets can be imported, the column template for each, duplicate handling, all-or-nothing vs partial imports, and the file size limit (preview uses 10 MB). |

### Open items carried from this update

| Item | Notes |
| ---- | ----- |
| Persistence | Needs approval of DATA_MODEL.md, then API endpoints + migrations. Until then data is lost on reload. |
| Favicon | Still missing (the browser logs a 404 for `/favicon.ico`). Needs a square brand mark. |

## Sprint 3 — Authentication & user roles ✅

**Goal:** secure sign-in and role-based access, without starting business-module APIs.

Delivered:
- **Database:** MySQL `yorilife_erp` (development) and `yorilife_erp_test` (isolated for automated tests), each with a dedicated `yorilife_app` user. Migrations add `roles`, `users` and `login_attempts`, run with the new forward-only `bin/migrate.php`.
- **Backend:**
  - **Sign-in:** login, logout and current-user endpoints using a server-side session (HttpOnly cookie).
  - **Security:** CSRF tokens, Origin checks, failed-login throttling, bcrypt hashing, idle and absolute expiry, and account status re-checked on every request.
  - **Authorization:** central RBAC (`config/permissions.php`, `Authorization`) with rank rules against privilege escalation.
  - **User management API:** list, view, create, edit, change role, activate/disable.
- **Admin setup:** a one-time `bin/create-admin.php` command for the first Super Admin. No default accounts or passwords exist.
- **Frontend:**
  - **Sign-in page:** login with show/hide password, validation, loading state and a message for each error.
  - **Route protection:** every ERP page requires sign-in, with a safe return to the requested page afterwards. The session is restored from the server on reload, and expired or server-error states are handled.
  - **Account menu:** shows the real user's name, email and role, with a working **Sign out**.
  - **Users page:** **System & Audit → Users**, permission-gated.
- **Tests:**
  - PHPUnit: 44 backend tests (unit + real HTTP feature tests).
  - Playwright: 26 browser end-to-end tests.
  - Node: 8 frontend unit tests.

Not in Sprint 3 (unchanged): Sales Office, Products, Master Sheet, Sales, Reports and Import APIs. Those screens still use the in-memory preview.

### Decisions needing owner approval (Sprint 3)

| # | Decision | Current implementation |
| - | -------- | ---------------------- |
| A1 | **Role names and meaning:** are Super Admin, Admin, Sales Manager and Sales User the right roles? Are more needed (e.g. Accounts, Headquarters-level users)? | The four proposed roles are seeded. Sales roles can only use the app shell. |
| A2 | **Admin vs Super Admin:** may an Admin create other Admins? | No. Admins manage only Sales Manager / Sales User accounts. |
| A3 | **Module permissions:** who may view, enter or edit Sales Office, Products, Master Sheet, Sales, Reports and Imports? | Not defined. All signed-in users can open every (preview) screen. API endpoints will need a permission each. |
| A4 | **Data scope:** should Sales Managers / Sales Users see only their own headquarters or hierarchy? | Not implemented (needs the Sales Office API). |
| A5 | **Password lifecycle:** reset by email, admin-set temporary passwords, forced change on first sign-in, expiry? | Admins set the initial password. No reset or change flow yet. |
| A6 | **Session lengths:** 30 minutes idle and 12 hours absolute? | Configurable in `.env`. |
| A7 | **Mobile app authentication:** token-based access for the Android/iOS app? | Not built. Cookie sessions serve the web app only. |
| A8 | **Public database health check:** restrict `/api/health/database` in production? | Public (it reveals only connected / unavailable). |
| A9 | **MFA and audit:** two-factor sign-in, and an audit log of sign-ins and user changes? | Not built. `last_login_at` and failed-attempt rows exist. |

### Environment notes

- **MySQL server:** Laragon's MySQL 8.4.3 was started for this sprint. It shares its data directory with two other local databases (`dbs10917776`, `nnugfnzmph`), which were not touched.
- **Local development Super Admin:** `admin@admin.com` was created in `yorilife_erp` at the owner's request, with a deliberately weak password that is below the 12-character policy. **Local use only:** never copy this database or account to a shared or production server. For real environments, create the admin with `php bin/create-admin.php` (see README).
- **Bundle size:** the production JavaScript bundle is about 510 kB, which triggers Vite's size advisory. Code-splitting the routes is a later optimisation.

## Sidebar navigation update ✅

The left sidebar follows the approved order: **Dashboard · Products · Sales Management · Sales Office · Application Details · Announcements · System & Audit.** (The Sales Management → Reports subgroup was removed.)

- **Dashboard:** now a single link to `/dashboard`. The Headquarters Dashboard placeholder (`/dashboard/headquarters`) was removed and its URL now shows the 404 page.
- **Sales Management:** sales entry pages only (Reports subgroup removed).
- **CSV Import:** nested inside Sales Management as well, with routes unchanged. It isn't in the approved top-level list, but its functionality must stay available.
- **Application Details:** the renamed "Sales Application" group, with the same three pages and routes.
- **Announcements:** a new top-level link to `/announcements`, showing the shared placeholder page (no announcements feature exists yet).
- **CSV Export:** no such menu, route or code exists anywhere in the app, so nothing was removed. The only "Export" is a sample button on the developer-only Design System page.
- **Unchanged:** authentication, permissions, the backend and the database.

Verification: lint, build, unit tests, and 38/38 browser tests (12 new sidebar tests plus the existing 26).

### Needs clarification (sidebar)

| # | Question |
| - | -------- |
| N1 | **Application Details:** what should it contain? It currently holds the former Sales Application pages (Sales Entry Overview, Submission Status, Sales Entry History). |
| N2 | **CSV Import placement:** it now sits inside Sales Management. Should Products also get its own "Import products (CSV)" entry? The import screen only offers "Sales entries" today, and the product import columns aren't defined. |
| N3 | **Announcements:** what should the feature do (who posts, who sees, delivery)? It is a placeholder until defined. |

## Sprint 4 — Database and persistent business data ✅

**Goal:** connect the existing screens to MySQL so business records survive refresh and re-login.

Verified on 28 Oct 2026 against MySQL (`yorilife_erp` migration applied; PHPUnit and Playwright used `yorilife_erp_test`):

- PHPUnit: 52 tests, 348 assertions, all passed (includes the new `BusinessDataTest`).
- Playwright: 38 passed, including a headquarters that remains after reload and is visible to a Sales User.
- Frontend: oxlint clean, 8 unit tests passed, production build succeeded.
- Sprint 3 sign-in and user management still pass in those suites.

In scope:

1. Foundation: permissions, shared validation, and safe test-database handling.
2. Sales Office: Headquarters, 1st/2nd/3rd Line Managers, and Stockists.
3. Catalogue: group categories, categories, tags, and badges.
4. Products: create/edit, soft delete, Admin restore, and change history.
5. Master Product Sheet: separate April–March financial years and year-specific product configuration.
6. Sales entries: persistent records, manually entered Sales Final Value, and validation.
7. Verification: migrations, API documentation, PHPUnit, and Playwright against the running app.

Out of scope until requirements exist: Dashboard, Reports, Announcements, Application Details, Audit, and CSV Import.

Done when: migrations run safely; Sprint 3 auth still works; data is saved and loaded through the API; backend validation and authorization are tested; PHPUnit and Playwright pass; MySQL is actually connected; docs and test results are updated.

## Sprint 5 — Sales Office hierarchy and demo data ✅

The Sprint 4 APIs already stored headquarters, the three manager levels, and stockists. The chain now reads from the top: 3rd line manager, then 2nd line manager, then 1st line manager, then headquarters, then stockists. A 3rd line manager has no parent. Several records can share the parent directly above them. Stockists still link only to a headquarters. State is chosen from the 28 states and 8 union territories, with the territory labelled Delhi.

- `php bin/seed-demo.php` loads fictional demo rows into `yorilife_erp`: Shyam (`ER-M3-001`), four 2nd line managers, four 1st line managers under Shubham, two headquarters under Aarav Mehta, and five stockists. Older demo rows are removed only when their code starts with `DEMO-HQ`, `DEMO-M`, or `DEMO-STK` and is not in this set. Other business rows are left as they are. Users and roles are not changed. The combined view is Sales Office Hierarchy.
- Migration `2026_10_29_000006_sales_office_top_down_hierarchy` adds `headquarters.parent_manager_id` and reverses only a complete one-to-one chain (one 3rd line, one 2nd line, one 1st line, one headquarters). Any other existing chain is left untouched; the new check then stops the migration instead of guessing a parent.

Added on 29 Oct 2026:

- Headquarters now store state, address, pincode, and phone (`2026_10_29_000005_add_headquarters_contact_columns`). Name stays required. Code stays optional and unique when set. Names are not unique. There is still no delete. State must be one of the 28 states or 8 union territories.
- Verified on 29 Oct 2026. PHPUnit on MySQL `yorilife_erp_test`: 56 tests, 407 assertions, all passed. Frontend lint clean, 8 unit tests passed, production build succeeded. Playwright: 39 passed and the route-render test timed out at 60 seconds; that test passed in 41 seconds after its limit was raised to 120 seconds. The state dropdown shows 37 options (placeholder plus 36 regions, including Delhi). On `yorilife_erp` the migration reversed the five existing one-to-one demo chains. There were no non-demo headquarters, no sales rows, and the one user was unchanged. The first seed created 25 rows and the second updated those 25 and created none. Two 2nd line managers sit under 3rd line manager 01, two headquarters sit under 1st line manager 01, and two stockists sit under headquarters 01.

## Product management — year commercials and product scheme

The product menu is Product List, Master Product Sheet, Deleted Products and Product Change History. Group category and product category stay on the product. A product category belongs to one group category; categories that have no group are left unmapped.

Strip pack, MRP, PTR and PTS, and Included / Excluded, are stored on `master_sheet_entries` for one financial year. Included / Excluded means eligibility for that year’s Extra Bonus / Incentive Scheme. A new sheet row starts as Not set. The scheme is the product’s current value. `10+5` still records buy and free quantities. Year-wise scheme columns are no longer written. Where every stored year scheme for a product is the same, that value is copied onto the product. Where years disagree, the product scheme is left empty and the year values stay for review. Scheme changes are one `product_changes` row and are what the Master Sheet Info button shows.

Update Products adds missing active products for the selected year and does not replace prices. Scan and Update lists missing products and inserts only after the admin confirms a selection.

## Later sprints

Sprint 5 added headquarters contact fields and fictional Sales Office demo data. The manager and stockist APIs were already in place.

| Sprint | Status | Notes |
| --- | --- | --- |
| 6 Dashboard and reports | Blocked | No approved metric definitions. Do not sum a field until the report says which value (achievement, bonus, deduction or final value) and whether totals are required. Headquarters-wise price means sales amount by headquarters, not a price list. Sales rows have no product, so a product-performance report cannot be built from sales entries. |
| 7 CSV import | Blocked | Scope is Products and Sales only. Still undecided: column templates, duplicate handling, and whether a file is all-or-nothing or partial. The import screen still checks a file in the browser and does not save it. |
| 8 Announcements and Application Details | Blocked | Routes are placeholders. Recipient rules, announcement fields and application metadata are not specified. |
| 9 Audit and settings | Done through Phase 3 | Audit logs (hash chain), change history, settings, account lock, authenticator app, device tokens, retention by archiving, and DPDP consent / export / correction / anonymise. |
| 10 Final QA and deployment | Not started as a release | Automated tests and the production frontend build passed on 28 Oct 2026. There is no production host, backup drill, or deployment sign-off. |
