<?php

return [
    'roles' => [
        'owner' => ['*'],
        'administrator' => ['*'],
        'accountant' => ['admin.access', 'dashboard.view', 'companies.view', 'accounts.*', 'entries.*', 'reports.view', 'users.view', 'users.create', 'users.update', 'parties.*'],
        'data-entry' => ['admin.access', 'dashboard.view', 'companies.view', 'accounts.view', 'entries.view', 'entries.create', 'parties.view', 'parties.create'],
        'member' => ['media.view', 'media.upload', 'media.delete'],
    ],
    'catalogue' => [
        'Administration' => ['admin.access', 'dashboard.view'],
        'Companies' => ['companies.view', 'companies.create', 'companies.update', 'companies.all'],
        'Accounting' => ['accounts.view', 'accounts.manage', 'entries.view', 'entries.create', 'entries.update', 'entries.void'],
        'Reports' => ['reports.view'],
        'Parties' => ['parties.view', 'parties.create', 'parties.update'],
        'Employees' => ['users.view', 'users.create', 'users.update'],
        'Roles' => ['roles.view', 'roles.create', 'roles.update', 'roles.delete', 'roles.assign', 'permissions.manage'],
        'Settings' => ['settings.view', 'settings.update'],
        'Media' => ['media.view', 'media.upload', 'media.delete', 'media.manage'],
    ],
];
