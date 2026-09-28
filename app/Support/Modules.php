<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * The application modules (Accounting, CRM) switched from the header. A page belongs to a module by its
 * route name; shared pages (companies, employees, roles, settings…) keep the module the user was last in.
 */
final class Modules
{
    public const SESSION_KEY = 'module';

    public const ACCOUNTING = 'accounting';

    public const CRM = 'crm';

    /** Route names that belong to every module. */
    private const SHARED_ROUTES = ['admin.profile', 'admin.users.*', 'admin.companies.*', 'admin.roles.*', 'admin.media', 'admin.settings', 'admin.choose-company', 'admin.print'];

    /** The module a route belongs to, or null for a shared or non-admin route. */
    public static function forRoute(?string $routeName): ?string
    {
        return match (true) {
            $routeName === null, ! str_starts_with($routeName, 'admin.'), Str::is(self::SHARED_ROUTES, $routeName) => null,
            str_starts_with($routeName, 'admin.crm.') => self::CRM,
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
        return array_filter([
            self::ACCOUNTING => $user->can('dashboard.view') ? [__('Accounting'), '📒', 'admin.dashboard'] : null,
            self::CRM => $user->can('crm.view') ? [__('CRM'), '🎯', 'admin.crm.dashboard'] : null,
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
