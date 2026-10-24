<?php

declare(strict_types=1);

/*
 * Role → permission map (the single place authorization rules are defined).
 *
 * Sprint 3 only defines permissions for user management. Every active,
 * signed-in user may use the application shell. Business-module permissions
 * (Sales Office, Products, Sales, Reports, Imports) are NOT defined yet — they
 * need owner approval (see docs/SPRINT_PLAN.md).
 *
 * `rank` prevents privilege escalation: a user may only manage accounts and
 * assign roles ranked BELOW their own (Super Admin may manage everyone).
 * Role slugs must match rows in the `roles` table.
 */
return [
    'permissions' => [
        'users.view' => 'View users and their roles',
        'users.create' => 'Create users',
        'users.update' => 'Edit user name and email',
        'users.change_status' => 'Activate or disable users',
        'users.assign_role' => 'Change a user’s role',
    ],

    'roles' => [
        'super_admin' => ['rank' => 100, 'permissions' => ['*']],
        'admin' => [
            'rank' => 80,
            'permissions' => ['users.view', 'users.create', 'users.update', 'users.change_status', 'users.assign_role'],
        ],
        'sales_manager' => ['rank' => 50, 'permissions' => []],
        'sales_user' => ['rank' => 10, 'permissions' => []],
    ],
];
