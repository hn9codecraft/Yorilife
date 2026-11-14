<?php

declare(strict_types=1);

/*
 * Role → permission map (the single place authorization rules are defined).
 *
 * Sprint 3: user management. Sprint 4 (owner-approved defaults): business
 * modules — Super Admin and Admin have full access; Sales Manager and Sales
 * User may only view the business screens, except Bonus Calc, which a Sales
 * Manager may edit. Only Admin / Super Admin restore.
 * There are no delete permissions for headquarters, managers or stockists
 * (not part of Sprint 4). The old Sales Management content (sales entries)
 * was removed on 2026-11-14 with sales.create / sales.update. Bonus Calc
 * uses sales.view to open the pages and sales.manage to edit them.
 * sales.manage is held by Super Admin, Admin and Sales Manager — the roles
 * that could create and update sales — and not by Sales User.
 *
 * `rank` prevents privilege escalation: a user may only manage accounts and
 * assign roles ranked BELOW their own (Super Admin may manage everyone).
 * Role slugs must match rows in the `roles` table.
 */
$business = [
    'sales_office.view', 'sales_office.manage',
    'catalogue.view', 'catalogue.manage', 'catalogue.restore',
    'products.view', 'products.manage', 'products.delete', 'products.restore',
    'financial_years.manage',
    'master_sheet.view', 'master_sheet.update',
    'sales.view', 'sales.manage',
    'imports.manage',
    'announcements.view', 'announcements.manage', 'announcements.publish', 'announcements.reports',
];
$viewOnly = ['sales_office.view', 'catalogue.view', 'products.view', 'master_sheet.view', 'sales.view'];

return [
    'permissions' => [
        'users.view' => 'View users and their roles',
        'users.create' => 'Create users',
        'users.update' => 'Edit user name and email',
        'users.change_status' => 'Activate or disable users',
        'users.assign_role' => 'Change a user’s role',
        'sales_office.view' => 'View headquarters, employees, managers and stockists',
        'sales_office.manage' => 'Add and edit headquarters, employees, managers and stockists',
        'catalogue.view' => 'View product types and product groups (Products > Category)',
        'catalogue.manage' => 'Create, edit and delete product types and product groups',
        'catalogue.restore' => 'Restore deleted catalogue terms',
        'products.view' => 'View products and product history',
        'products.manage' => 'Add and edit products',
        'products.delete' => 'Delete products (soft delete)',
        'products.restore' => 'Restore deleted products',
        'financial_years.manage' => 'Add financial years',
        'master_sheet.view' => 'View the Master Product Sheet and its history',
        'master_sheet.update' => 'Update Master Product Sheet entries',
        'sales.view' => 'Open Bonus Calc and its month sheets',
        'sales.manage' => 'Add employees to Bonus Calc and enter month quantities',
        'imports.manage' => 'Import Sales Office CSV files, download import files and backups, and roll imports back',
        'announcements.view' => 'See announcements, templates and the employee list',
        'announcements.manage' => 'Create and edit announcement drafts, templates and the employee list',
        'announcements.publish' => 'Publish, schedule, pin, edit live and recall announcements',
        'announcements.reports' => 'See announcement tracking and export recipient lists',
        'audit.view' => 'View Audit Logs and Change History',
        'settings.view' => 'View system settings',
        'settings.edit' => 'Edit company, general and retention settings',
        'privacy.manage' => 'Record consent and handle personal-data requests',
    ],

    'roles' => [
        'super_admin' => ['rank' => 100, 'permissions' => ['*']],
        'admin' => [
            'rank' => 80,
            'permissions' => ['users.view', 'users.create', 'users.update', 'users.change_status', 'users.assign_role', 'audit.view', 'settings.view', 'settings.edit', 'privacy.manage', ...$business],
        ],
        'sales_manager' => ['rank' => 50, 'permissions' => [...$viewOnly, 'sales.manage']],
        'sales_user' => ['rank' => 10, 'permissions' => $viewOnly],
    ],
];
