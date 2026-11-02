<?php

declare(strict_types=1);

/*
 * Role → permission map (the single place authorization rules are defined).
 *
 * Sprint 3: user management. Sprint 4 (owner-approved defaults): business
 * modules — Super Admin and Admin have full access; Sales Manager and Sales
 * User may only view the business screens. Only Admin / Super Admin restore.
 * There are no delete permissions for headquarters, managers, stockists or
 * sales entries (not part of Sprint 4).
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
    'sales.view', 'sales.create', 'sales.update',
];
$viewOnly = ['sales_office.view', 'catalogue.view', 'products.view', 'master_sheet.view', 'sales.view'];

return [
    'permissions' => [
        'users.view' => 'View users and their roles',
        'users.create' => 'Create users',
        'users.update' => 'Edit user name and email',
        'users.change_status' => 'Activate or disable users',
        'users.assign_role' => 'Change a user’s role',
        'sales_office.view' => 'View headquarters, managers and stockists',
        'sales_office.manage' => 'Add and edit headquarters, managers and stockists',
        'catalogue.view' => 'View product group categories and product categories',
        'catalogue.manage' => 'Create, edit and delete catalogue terms',
        'catalogue.restore' => 'Restore deleted catalogue terms',
        'products.view' => 'View products and product history',
        'products.manage' => 'Add and edit products',
        'products.delete' => 'Delete products (soft delete)',
        'products.restore' => 'Restore deleted products',
        'financial_years.manage' => 'Add financial years',
        'master_sheet.view' => 'View the Master Product Sheet and its history',
        'master_sheet.update' => 'Update Master Product Sheet entries',
        'sales.view' => 'View sales entries',
        'sales.create' => 'Add sales entries',
        'sales.update' => 'Edit sales entries',
    ],

    'roles' => [
        'super_admin' => ['rank' => 100, 'permissions' => ['*']],
        'admin' => [
            'rank' => 80,
            'permissions' => ['users.view', 'users.create', 'users.update', 'users.change_status', 'users.assign_role', ...$business],
        ],
        'sales_manager' => ['rank' => 50, 'permissions' => $viewOnly],
        'sales_user' => ['rank' => 10, 'permissions' => $viewOnly],
    ],
];
