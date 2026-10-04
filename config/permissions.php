<?php

return [
    'roles' => [
        'owner' => ['*'],
        'administrator' => ['*'],
        'accountant' => ['admin.access', 'dashboard.view', 'companies.view', 'accounts.*', 'entries.view', 'entries.create', 'entries.update', 'entries.void', 'entries.delete', 'reports.view', 'users.view', 'users.create', 'users.update', 'parties.*',
            'sales.view', 'sales.create', 'sales.update', 'sales.void', 'sales.delete', 'sales.payments', 'sales.send', 'sales.reports'],
        'data-entry' => ['admin.access', 'dashboard.view', 'companies.view', 'accounts.view', 'entries.view', 'entries.create', 'parties.view', 'parties.create',
            'sales.view', 'sales.create'],
        'member' => ['media.view', 'media.upload', 'media.delete'],
        'sales' => ['admin.access', 'companies.view', 'crm.view', 'crm.leads.create', 'crm.leads.update', 'crm.calls.create', 'crm.calls.update', 'crm.emails.send'],
        'sales-manager' => ['admin.access', 'companies.view', 'crm.*', 'users.view'],
    ],
    'catalogue' => [
        'Administration' => ['admin.access', 'dashboard.view'],
        'Companies' => ['companies.view', 'companies.create', 'companies.update', 'companies.delete', 'companies.all'],
        'Accounting' => ['accounts.view', 'accounts.manage', 'accounts.delete', 'entries.view', 'entries.create', 'entries.update', 'entries.void', 'entries.delete', 'entries.purge'],
        'Reports' => ['reports.view'],
        'Parties' => ['parties.view', 'parties.create', 'parties.update', 'parties.delete'],
        'Employees' => ['users.view', 'users.create', 'users.update', 'users.delete'],
        'Roles' => ['roles.view', 'roles.create', 'roles.update', 'roles.delete', 'roles.assign', 'permissions.manage'],
        'Settings' => ['settings.view', 'settings.update'],
        'Media' => ['media.view', 'media.upload', 'media.delete', 'media.manage'],
        'Sales' => ['sales.view', 'sales.create', 'sales.update', 'sales.void', 'sales.delete', 'sales.payments', 'sales.send', 'sales.setup', 'sales.reports'],
        'CRM' => ['crm.view', 'crm.leads.all', 'crm.leads.create', 'crm.leads.update', 'crm.leads.delete', 'crm.leads.import',
            'crm.calls.create', 'crm.calls.update', 'crm.calls.delete', 'crm.emails.send', 'crm.setup.manage', 'crm.reports.view'],
    ],
];
