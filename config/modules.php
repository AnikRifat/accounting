<?php

/*
 * The modules this install runs. A disabled module leaves the header, its pages and public links return 404 and its
 * scheduled jobs stop; its data and permissions stay, so switching it back on restores everything.
 * Sales posts to the ledger and uses Accounting's parties and payment methods, so it runs only with Accounting on.
 * Organisation (companies, employees, roles, settings) is always on. Run `php artisan config:clear` after a change
 * when the config is cached.
 */
return [
    'accounting' => env('MODULE_ACCOUNTING', true),
    'sales' => env('MODULE_SALES', true),
    'crm' => env('MODULE_CRM', true),
];
