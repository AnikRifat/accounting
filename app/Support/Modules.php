<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * The application modules (Accounting, CRM, Organisation) switched from the header. A page belongs to a module by
 * its route name; the few shared pages (profile, company chooser, print) keep the module the user was last in.
 */
final class Modules
{
    public const SESSION_KEY = 'module';

    public const ACCOUNTING = 'accounting';

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
            Str::is(['admin.companies.*', 'admin.users.*', 'admin.roles.*', 'admin.media', 'admin.settings'], $routeName) => self::ORGANISATION,
            default => self::ACCOUNTING,
        };
    }

    /**
     * Modules the user can open, in header order: key => [label, emoji, home route].
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function available(User $user): array
    {
        $organisationHome = collect(self::ORGANISATION_ROUTES)->first(fn (array $route): bool => $user->can($route[1]))[0] ?? null;

        return array_filter([
            self::ACCOUNTING => $user->can('dashboard.view') ? [__('Accounting'), '📒', 'admin.dashboard'] : null,
            self::CRM => $user->can('crm.view') ? [__('CRM'), '🎯', 'admin.crm.dashboard'] : null,
            self::ORGANISATION => $organisationHome ? [__('Organisation'), '🏢', $organisationHome] : null,
        ]);
    }

    /** The module of the current page, else the last one used, limited to the modules the user can open. */
    public static function current(User $user): string
    {
        $available = self::available($user);
        $module = self::forRoute(request()->route()?->getName()) ?? session(self::SESSION_KEY);

        return isset($available[$module]) ? $module : (array_key_first($available) ?? self::ACCOUNTING);
    }
}
