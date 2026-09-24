<?php

return [
    'roles' => [
        'owner' => ['*'],
        'administrator' => ['*'],
        'accountant' => ['admin.access', 'dashboard.view', 'companies.view', 'accounts.*', 'entries.*', 'reports.view', 'employees.*', 'parties.*'],
        'data-entry' => ['admin.access', 'dashboard.view', 'companies.view', 'accounts.view', 'entries.view', 'entries.create', 'employees.view', 'parties.view', 'parties.create'],
        'member' => ['media.view', 'media.upload', 'media.delete'],
    ],
    'catalogue' => [
        'Administration' => ['admin.access', 'dashboard.view'],
        'Companies' => ['companies.view', 'companies.create', 'companies.update', 'companies.all'],
        'Accounting' => ['accounts.view', 'accounts.manage', 'entries.view', 'entries.create', 'entries.update', 'entries.void'],
        'Reports' => ['reports.view'],
        'Parties' => ['parties.view', 'parties.create', 'parties.update'],
        'Employees' => ['employees.view', 'employees.create', 'employees.update'],
        'Users' => ['users.view', 'users.create', 'users.update'],
        'Roles' => ['roles.view', 'roles.create', 'roles.update', 'roles.delete', 'roles.assign', 'permissions.manage'],
        'Settings' => ['settings.view', 'settings.update'],
        'Media' => ['media.view', 'media.upload', 'media.delete', 'media.manage'],
    ],
];
