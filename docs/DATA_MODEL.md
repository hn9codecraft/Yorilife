# Yorilife ERP — Proposed Data Model

> **Status:** authentication tables (Sprint 3) and the business tables below (Sprint 4) are **implemented** by `backend/database/migrations/`. Imports are still a proposal.
> The web app loads and saves the same record shapes through `/api/erp` and the resource endpoints. Ids are strings in JSON.
> Open questions that are still not built are listed in [SPRINT_PLAN.md](SPRINT_PLAN.md).

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
| status | ENUM('active','disabled','locked') | Disabled or locked users can't sign in. Locking an account, and resetting a password, also revokes that user's sessions and device tokens |
| auth_epoch | INT UNSIGNED | Increased when sessions must end. A session from an older epoch is rejected |
| last_login_at | DATETIME NULL | |
| created_at, updated_at | DATETIME | |

### `login_attempts`
`identifier_hash` CHAR(64) (SHA-256 of the email typed), `ip_address`, `succeeded`, `attempted_at`, `archived_at`. It is used only to throttle failed sign-ins. Old rows are archived by the retention setting. They are not deleted.

### Authenticator, device tokens and privacy

`user_totp` stores one authenticator secret per user (`enabled_at` is set only after a valid code). `user_recovery_codes` stores SHA-256 hashes of one-time recovery codes. `api_tokens` stores the SHA-256 hash, a short prefix, name, expiry, last use and revocation time of a personal device token. The plaintext token is returned only when it is created. `user_sessions` records the server session id so a password reset or a lock can remove that user's session files. `privacy_consents` and `privacy_requests` record consent and export, correction and anonymise requests. Anonymising masks personal fields on the existing row. It does not delete the row. `audit_chain_head` stores the latest audit hash separately so deleting the newest audit rows is detected.

### `schema_migrations`
`version`, `applied_at`. Maintained by `bin/migrate.php`.

Sessions are stored by PHP in `backend/storage/sessions`, not in the database.

---

## Implemented (Sprint 4): business modules

Differences from the earlier proposal, matching the approved Sprint 4 rules:

- Optional codes on headquarters, managers and stockists are unique when set. Empty codes are stored as NULL. Names are not unique.
- Stockists have `headquarters_id` only. There is no manager foreign key.
- Catalogue names are unique per `kind`, including soft-deleted rows. A term used by an active product cannot be soft-deleted. Deleted products may still point at a later soft-deleted term, so restore keeps the link.
- Product `code` is unique among active products only (`active_code`). Restoring a product whose code is now used by another active product is refused.
- Financial years live in `financial_years` (`label`, `starts_on`, `ends_on`, `created_by`). Seeded years 2024-2025, 2025-2026 and 2026-2027 have `created_by` NULL. A year created in the app starts with no sheet rows. April–March only.
- The current scheme lives on `products`. A value such as `10+5` also sets `scheme_buy_qty` and `scheme_free_qty`. Other text leaves those quantities NULL. Strip pack, MRP, PTR and PTS live on `master_sheet_entries`, one set per financial year. Included / Excluded is that year’s Extra Bonus / Incentive eligibility, and may be unset. Older year-wise `scheme` columns are kept and are not written by the application.
- `sales_entries` has one row per stockist per month. Amounts are `DECIMAL(14,2)`, not negative. `final_value` is stored as entered and is never calculated. There is no product column and no delete API.

## Reference shapes

Conventions: MySQL 8, InnoDB, `utf8mb4`. Every table has `id BIGINT UNSIGNED PK`, `created_at` and `updated_at`. Soft-deletable tables add `deleted_at DATETIME NULL`, and rows are never physically deleted. `*_by` columns reference `users.id`.

## Sales Office hierarchy

```
managers (level 3)                 no parent; top of the hierarchy
     └──* managers (level 2)          parent = 3rd line manager
              └──* managers (level 1) parent = 2nd line manager
                       └──* headquarters   parent = 1st line manager
                                └──* stockists
```

### `headquarters`
| Column | Type | Notes |
| ------ | ---- | ----- |
| name | VARCHAR(150) NOT NULL | Names are not unique |
| state | VARCHAR(100) NOT NULL on write | Added in Sprint 5. Nullable only for rows created before that migration |
| address | VARCHAR(255) NOT NULL on write | Headquarters address |
| pincode | CHAR(6) NOT NULL on write | Six digits |
| phone | VARCHAR(20) NOT NULL on write | Stored as entered, including a country code or leading zero. Demo numbers are fictional |
| email | VARCHAR(254) NULL | Required when the request sends `locationId` |
| location_id | FK → locations NULL | The Location state. Filled from the location headquarters, `locationId`, or a matching state name |
| location_headquarters_id | FK → location_headquarters NULL | The location-headquarters name this office uses. Deleting that name clears the link and leaves the office |
| code | VARCHAR(50) NULL | Unique when set; stored as text so `HQ-001` keeps its zeros |
| parent_manager_id | FK → managers NULL | The 1st line manager this headquarters belongs to. Required on create and update |

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
- level 3 → no parent;
- level 2 → parent is a level-3 manager;
- level 1 → parent is a level-2 manager;
- `parent_headquarters_id` stays in the table and is null for every current row. A level cannot be skipped.
- `locations` holds the states managed on the Location page (`id`, `name`, `code`). The first six rows are Gujarat `GUJ`, Andhra Pradesh `AP`, Arunachal Pradesh `ARP`, Assam `ASM`, Bihar `BIH` and Chhattisgarh `CG`. Names and codes are unique without regard to case. Codes are stored in uppercase.
- `headquarters.location_id` points at one of those states. `headquarters.state` keeps the state name. `headquarters.email` is the office email. A new code uses `HQ-` plus digits (`HQ-001`). Older codes are left unchanged. The headquarters still belongs to one 1st line manager, and stockists still belong to a headquarters.
- `manager_states` links a manager to those rows (`manager_id`, `location_id`). A 3rd line manager can have several. A 2nd line manager chooses one or more of the states already assigned to their 3rd line manager. A 1st line manager chooses one or more of the states already assigned to their 2nd line manager, and can store an optional email and phone. Renaming a location updates the name everywhere it is assigned. A location that is still assigned cannot be deleted.
- `managers.email` and `managers.phone` are optional. The 3rd line manager form may leave both empty. A provided email must be valid and unique. Phone is stored as text, including a leading zero or country code. A manager who still has child managers or headquarters cannot be deleted.
- Location headquarters names live in `location_headquarters` and store the state, name, code, address, pincode, phone, and email. The phone is stored as entered and can include a country code. `POST /api/location-headquarters` adds that record and does not create an office. A new code is `HQ-` plus digits. Existing codes are not rewritten.
- The Headquarters form sends `locationHeadquartersId` and the 1st line manager. The office copies the name, state, code, and contact details from that location headquarters and does not create another one. A second office cannot use the same location headquarters. A request that omits `locationHeadquartersId` and sends `locationId` still stores that location. A request that omits both still checks `state` against `backend/config/states.php`. Older codes are not rewritten. Deleting a headquarters office also removes the stockists and sales entries recorded for it, and clears any manager link that still points at that office. The location headquarters record stays and can be deleted from the Location page once no office uses it. A location that is still assigned to a manager or a headquarters cannot be deleted.

A manager's headquarters is derived by walking up the chain and is not stored, so it can't drift.

### `stockists`
| Column | Type | Notes |
| ------ | ---- | ----- |
| name | VARCHAR(150) NOT NULL | |
| code | VARCHAR(50) NULL | Unique when set. A new code is `ST-` plus digits (`ST-001`). Older codes are left unchanged |
| address | VARCHAR(255) NULL | Optional |
| pincode | VARCHAR(6) NULL | Optional. Six digits when provided |
| phone | VARCHAR(20) NULL | Optional. Stored as entered, including a country code |
| email | VARCHAR(255) NULL | Optional |
| headquarters_id | FK → headquarters NOT NULL | Direct HQ mapping (required). The stockist's state is the headquarters state |

A stockist's hierarchy view shows the managers under its headquarters. Deleting a stockist also removes the sales entries recorded for it. Whether a stockist should also be assigned to a specific manager is still to be confirmed; it would be a nullable `manager_id`.

## Products

### `catalogue_terms`
Group categories and product categories share this table. A product category’s `parent_id` is its group category. Tags and badges remain in the table from earlier sprints; they are not a product-menu module.

| Column | Type | Notes |
| ------ | ---- | ----- |
| kind | ENUM('group_category','category','tag','badge') NOT NULL | |
| parent_id | FK → catalogue_terms NULL | Set for a product category. A group category has no parent. Existing unmapped categories stay NULL until an admin chooses a group |
| name | VARCHAR(100) NOT NULL | |
| deleted_at | DATETIME NULL | Soft delete: hidden from pickers, kept for history |

A group category cannot be deleted while an active product category belongs to it. A product category cannot be deleted while an active product uses it.

### `products`
| Column | Type | Notes |
| ------ | ---- | ----- |
| name | VARCHAR(200) NOT NULL | |
| code | VARCHAR(50) NULL | |
| category_id | FK → catalogue_terms NOT NULL on write | kind = category, and that category’s parent is the chosen group |
| scheme | VARCHAR(50) NULL | Current product scheme, stored as entered |
| scheme_buy_qty, scheme_free_qty | INT UNSIGNED NULL | Set when the scheme is `buy+free`, such as `10+5` |
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

### `financial_years`
`label` VARCHAR(9) UNIQUE (e.g. `2025-2026`), April–March `starts_on` / `ends_on`.

### `master_sheet_entries`
One row per product per financial year. Editing one year does not change another.

| Column | Type | Notes |
| ------ | ---- | ----- |
| year_id | FK → financial_years | |
| product_id | FK → products | Deleted products keep their past entries |
| inclusion | ENUM('included','excluded') NULL | NULL means Not set. Eligibility for that year’s Extra Bonus / Incentive Scheme |
| strip_pack | VARCHAR(50) NULL | Year-specific |
| mrp, ptr, pts | DECIMAL(14,2) NULL | Year-specific, not negative |
| scheme, scheme_buy_qty, scheme_free_qty | kept, not written | Previous year-wise values. The current scheme is on `products` |
| UNIQUE | (year_id, product_id) | |

### `master_sheet_changes`
Inclusion changes for a year. Scheme changes are stored once in `product_changes`. Older scheme rows in this table are still shown with product history, without a second copy.

| Column | Type |
| ------ | ---- |
| year_id, product_id | FKs |
| field | VARCHAR(50) |
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
