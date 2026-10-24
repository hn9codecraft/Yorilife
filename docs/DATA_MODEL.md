# Yorilife ERP — Proposed Data Model

> **Status:** the authentication tables below are **implemented** (Sprint 3). The business-module tables are a **proposal for review, not implemented**: no migrations or tables exist for them, and the API does not store that data.
> The frontend preview keeps the same shapes in memory (`frontend/src/data/erpReducer.js`), so the screens can switch to the API without changes.
> `*_by` columns in the proposal will reference `users.id`.
> Open questions that affect this design are listed in [SPRINT_PLAN.md](SPRINT_PLAN.md#needs-business-clarification).

## Implemented (Sprint 3): authentication

These tables **exist** and are created by migrations in `backend/database/migrations/` (`php bin/migrate.php`). All `DATETIME` values are UTC.

### `roles`
| Column | Type | Notes |
| ------ | ---- | ----- |
| id | SMALLINT UNSIGNED PK | |
| slug | VARCHAR(50) UNIQUE | `super_admin`, `admin`, `sales_manager`, `sales_user` (seeded by the migration; proposed names pending approval) |
| name, description | VARCHAR | Display text |
| created_at, updated_at | DATETIME | |

Permissions per role are in `backend/config/permissions.php` (keyed by slug), not in a table, until module permissions are approved.

### `users`
| Column | Type | Notes |
| ------ | ---- | ----- |
| id | BIGINT UNSIGNED PK | |
| name | VARCHAR(150) | |
| email | VARCHAR(254) UNIQUE | Sign-in identity, stored trimmed + lower-case |
| password_hash | VARCHAR(255) | bcrypt (cost 12). Never returned by the API |
| role_id | FK → roles | One role per user |
| status | ENUM('active','disabled') | Disabled users can't sign in; existing sessions end on their next request |
| last_login_at | DATETIME NULL | |
| created_at, updated_at | DATETIME | |

### `login_attempts`
`identifier_hash` CHAR(64) (SHA-256 of the email typed), `ip_address`, `succeeded`, `attempted_at`. It is used only to throttle failed sign-ins, and rows older than 30 days are pruned.

### `schema_migrations`
`version`, `applied_at`. Maintained by `bin/migrate.php`.

Sessions are stored by PHP in `backend/storage/sessions`, not in the database.

---

## Proposed (not implemented): business modules

Conventions: MySQL 8, InnoDB, `utf8mb4`. Every table has `id BIGINT UNSIGNED PK`, `created_at` and `updated_at`. Soft-deletable tables add `deleted_at DATETIME NULL`, and rows are never physically deleted. `*_by` columns reference the future `users` table and stay `NULL` until authentication exists.

## Sales Office hierarchy

```
headquarters 1───* stockists
     │
     ├──* managers (level 1)              parent = headquarters
     │        └──* managers (level 2)     parent = 1st line manager
     └──────── managers (level 3)         parent = headquarters OR 1st line OR 2nd line
```

### `headquarters`
| Column | Type | Notes |
| ------ | ---- | ----- |
| name | VARCHAR(150) NOT NULL | |
| code | VARCHAR(50) NULL | Uniqueness to confirm |

### `managers`
One table for all three levels. The parent is either a headquarters or another manager.

| Column | Type | Notes |
| ------ | ---- | ----- |
| level | TINYINT NOT NULL | 1, 2 or 3 |
| name | VARCHAR(150) NOT NULL | |
| code | VARCHAR(50) NULL | Employee code |
| parent_headquarters_id | FK → headquarters NULL | Set when the parent is a headquarters |
| parent_manager_id | FK → managers NULL | Set when the parent is a manager |

Rules (enforced in the API, plus a CHECK where MySQL allows):
- exactly one of `parent_headquarters_id` / `parent_manager_id` is set;
- level 1 → parent is a headquarters;
- level 2 → parent is a level-1 manager;
- level 3 → parent is a headquarters, a level-1 or a level-2 manager.

A manager's headquarters is derived by walking up the chain and is not stored, so it can't drift.

### `stockists`
| Column | Type | Notes |
| ------ | ---- | ----- |
| name | VARCHAR(150) NOT NULL | |
| code | VARCHAR(50) NULL | |
| headquarters_id | FK → headquarters NOT NULL | Direct HQ mapping (required) |

A stockist's hierarchy view shows the managers under its headquarters. Whether a stockist should also be assigned to a specific manager is still to be confirmed; it would be a nullable `manager_id`.

## Products

### `catalogue_terms`
Tags, badges, group categories and categories share one shape but stay separate concepts via `kind`.

| Column | Type | Notes |
| ------ | ---- | ----- |
| kind | ENUM('group_category','category','tag','badge') NOT NULL | |
| name | VARCHAR(100) NOT NULL | |
| deleted_at | DATETIME NULL | Soft delete: hidden from pickers, kept for history |

### `products`
| Column | Type | Notes |
| ------ | ---- | ----- |
| name | VARCHAR(200) NOT NULL | |
| code | VARCHAR(50) NULL | |
| category_id | FK → catalogue_terms NULL | kind = category |
| deleted_at | DATETIME NULL | Soft delete: leaves the Product List and appears under Deleted Products |

### `product_terms` (pivot)
`product_id` FK, `term_id` FK (kind tag or badge), primary key (`product_id`, `term_id`).

### `product_changes`
Append-only audit trail for products.

| Column | Type | Notes |
| ------ | ---- | ----- |
| product_id | FK → products | |
| action | ENUM('created','updated','deleted') | |
| changes | JSON | `[{ field, from, to }]`, stored as readable values at the time of change |
| changed_by | FK → users NULL | |
| changed_at | DATETIME | |

## Master Product Sheet

### `master_sheet_years`
`label` VARCHAR(9) UNIQUE (e.g. `2025-2026`). How years are opened or closed is still to be confirmed.

### `master_sheet_entries`
One row per product per year, so editing one year can never overwrite another.

| Column | Type | Notes |
| ------ | ---- | ----- |
| year_id | FK → master_sheet_years | |
| product_id | FK → products | Deleted products keep their past entries |
| inclusion | ENUM('included','excluded') NOT NULL | No row = "Not set" |
| scheme | VARCHAR(50) NULL | Stored as text exactly as entered (e.g. `10+5`); never calculated |
| UNIQUE | (year_id, product_id) | |

### `master_sheet_changes`
Append-only history shown by the Info button.

| Column | Type |
| ------ | ---- |
| year_id, product_id | FKs |
| field | VARCHAR(50) (`inclusion`, `scheme`) |
| old_value, new_value | VARCHAR(255) NULL |
| changed_by | FK → users NULL |
| changed_at | DATETIME |

## Sales

### `sales_entries`
| Column | Type | Notes |
| ------ | ---- | ----- |
| sales_month | DATE NOT NULL | First day of the month |
| headquarters_id | FK → headquarters NOT NULL | Stored as at the time of sale, so history survives stockist re-mapping |
| stockist_id | FK → stockists NOT NULL | |
| achievement_value | DECIMAL(14,2) NULL | |
| bonus | DECIMAL(14,2) NULL | "Point 4" field. Meaning to confirm |
| deduction | DECIMAL(14,2) NULL | Same as "Deduction Value"? To confirm |
| final_value | DECIMAL(14,2) NULL | **Entered, not calculated** until the formula is approved |
| entered_by | FK → users NULL | Administrator-only entry once roles exist |

Whether there can be more than one entry per stockist per month (a unique key on `stockist_id` + `sales_month`) is still to be confirmed.

## Imports

### `import_batches`
`import_type`, `original_filename`, `stored_path`, `status` ENUM('pending','processing','completed','failed'), `total_rows`, `successful_rows`, `failed_rows`, `imported_by`, `started_at`, `finished_at`.

### `import_errors`
`batch_id` FK, `line_number`, `column_name`, `message`.
