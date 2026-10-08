<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The application modules (Accounting, Sales, CRM, Organisation) switched from the header. A page belongs to a module by
 * its route name; the few shared pages (profile, company chooser, print) keep the module the user was last in.
 * config/modules.php makes a module available to an install; Settings switches the available ones on and off, and a
 * company can switch Sales and CRM off for itself (Company::MODULE_COLUMNS).
 */
final class Modules
{
    public const SESSION_KEY = 'module';

    public const ACCOUNTING = 'accounting';

    public const SALES = 'sales';

    public const CRM = 'crm';

    public const ORGANISATION = 'organisation';

    /** Route names that belong to every module. */
    private const SHARED_ROUTES = ['admin.profile', 'admin.choose-company', 'admin.print'];

    /** Organisation pages, in the order a user without the first one falls through to: [route name, ability]. */
    private const ORGANISATION_ROUTES = [['admin.companies.index', 'companies.view'], ['admin.users.index', 'users.view'],
        ['admin.roles.index', 'roles.view'], ['admin.media', 'media.view'], ['admin.settings', 'settings.view']];

    /** The module a route belongs to, or null for a shared or non-admin route. */
    public static function forRoute(?string $routeName): ?string
    {
        return match (true) {
            $routeName === null, ! str_starts_with($routeName, 'admin.'), Str::is(self::SHARED_ROUTES, $routeName) => null,
            str_starts_with($routeName, 'admin.crm.') => self::CRM,
            str_starts_with($routeName, 'admin.sales.') => self::SALES,
            Str::is(['admin.companies.*', 'admin.users.*', 'admin.roles.*', 'admin.media', 'admin.settings'], $routeName) => self::ORGANISATION,
            default => self::ACCOUNTING,
        };
    }

    /**
     * Whether the module runs: available in this install (config/modules.php) and switched on in Settings.
     * Organisation is always on; Sales needs Accounting.
     */
    public static function enabled(string $module): bool
    {
        return match ($module) {
            self::ORGANISATION => true,
            self::SALES => self::switchedOn(self::SALES) && self::switchedOn(self::ACCOUNTING),
            default => self::switchedOn($module),
        };
    }

    private static function switchedOn(string $module): bool
    {
        return (bool) config('modules.'.$module) && Configuration::get('modules.'.$module);
    }

    /**
     * Enabled modules the user can open, in header order: key => [label, emoji, home route]. Sales and CRM also need
     * one of the user's companies to use them (a user without companies still sees them).
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function available(User $user): array
    {
        $organisationHome = collect(self::ORGANISATION_ROUTES)->first(fn (array $route): bool => $user->can($route[1]))[0] ?? null;
        $usage = Company::visibleTo($user)->toBase()->selectRaw(collect(Company::MODULE_COLUMNS)
            ->map(fn (string $column, string $module): string => "max({$column}) as {$module}")->prepend('count(*) as companies')->implode(', '))->first();
        $used = fn (string $module): bool => ! $usage->companies || (bool) $usage->{$module};

        return array_filter([
            self::ACCOUNTING => self::enabled(self::ACCOUNTING) && $user->can('dashboard.view') ? [__('Accounting'), '📒', 'admin.dashboard'] : null,
            self::SALES => self::enabled(self::SALES) && $user->can('sales.view') && $used(self::SALES) ? [__('Sales'), '🧾', 'admin.sales.dashboard'] : null,
            self::CRM => self::enabled(self::CRM) && $user->can('crm.view') && $used(self::CRM) ? [__('CRM'), '🎯', 'admin.crm.dashboard'] : null,
            self::ORGANISATION => $organisationHome ? [__('Organisation'), '🏢', $organisationHome] : null,
        ]);
    }

    /** The module of the current page, else the last one used, limited to the modules the user can open. */
    public static function current(User $user): string
    {
        $available = self::available($user);
        $module = self::forRoute(request()->route()?->getName()) ?? session(self::SESSION_KEY);

        return isset($available[$module]) ? $module
            : (array_key_first($available) ?? (self::enabled(self::ACCOUNTING) ? self::ACCOUNTING : self::ORGANISATION));
    }
}
