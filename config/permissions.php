<?php

return [
    'roles' => [
        'owner' => ['*'],
        'administrator' => ['*'],
        'employee' => ['admin.access', 'dashboard.view', 'media.view', 'media.upload', 'media.delete'],
        'member' => ['media.view', 'media.upload', 'media.delete'],
    ],
    'catalogue' => [
        'Administration' => ['admin.access', 'dashboard.view'],
        'Users' => ['users.view', 'users.create', 'users.update'],
        'Employees' => ['employees.view', 'employees.create', 'employees.update'],
        'Roles' => ['roles.view', 'roles.create', 'roles.update', 'roles.delete', 'roles.assign', 'permissions.manage'],
        'Settings' => ['settings.view', 'settings.update'],
        'Media' => ['media.view', 'media.upload', 'media.delete', 'media.manage'],
    ],
];
