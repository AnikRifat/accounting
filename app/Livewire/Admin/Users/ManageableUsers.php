<?php

namespace App\Livewire\Admin\Users;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Accounts an actor may list and edit. Without all-company access, an actor only manages
 * accounts whose roles cannot reach every company and whose companies it can all see.
 */
class ManageableUsers
{
    /** @return Builder<User> */
    public static function for(User $actor): Builder
    {
        $query = User::where('role', '!=', Permissions::ROOT_ROLE);
        if ($actor->hasPermission('companies.all')) {
            return $query;
        }
        $registry = app(Permissions::class);
        $actorPermissions = $registry->forUser($actor);
        // The role ceiling is used, ignoring denials and inactive status, so a restricted or suspended
        // account cannot be re-enabled into more power than the manager holds. Ceilings are resolved in
        // PHP over every candidate account, which is fine for one business's staff list.
        $ids = (clone $query)->whereDoesntHave('companies', fn (Builder $companies) => $companies->whereNotIn('companies.id', $actor->accessibleCompanyIds()))
            ->get(['id', 'role', 'extra_roles'])
            ->filter(fn (User $user): bool => array_diff($registry->roleCeiling($user->role, $user->extra_roles ?? []), $actorPermissions) === [])
            ->modelKeys();

        return $query->whereKey($ids);
    }
}
