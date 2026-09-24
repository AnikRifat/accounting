<?php

namespace App\Support;

use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Permissions
{
    public const ROOT_ROLE = 'owner';

    /** @var array<string, RolePermission>|null */
    private ?array $rows = null;

    public function flush(): void
    {
        $this->rows = null;
    }

    public function catalogue(): array
    {
        return array_values(array_unique(array_merge(...array_values(config('permissions.catalogue')))));
    }

    public function systemRoles(): array
    {
        return array_values(array_diff(array_keys(config('permissions.roles')), [self::ROOT_ROLE]));
    }

    public function rows(): array
    {
        return $this->rows ??= RolePermission::all()->keyBy('role')->all();
    }

    public function assignableRoles(): array
    {
        return array_values(array_diff(array_unique([...$this->systemRoles(), ...array_keys($this->rows())]), [self::ROOT_ROLE]));
    }

    public function enabledRoles(): array
    {
        return array_values(array_filter($this->assignableRoles(), fn (string $role): bool => $this->isActive($role)));
    }

    public function isActive(string $role): bool
    {
        return $this->rows()[$role]->is_active ?? true;
    }

    public function isCustom(string $role): bool
    {
        return $role !== self::ROOT_ROLE && ! in_array($role, $this->systemRoles(), true);
    }

    public function label(string $role): string
    {
        return $this->isCustom($role) ? ($this->rows()[$role]->label ?? Str::headline($role)) : Str::headline($role);
    }

    public function forRole(string $role): array
    {
        if ($role === self::ROOT_ROLE) {
            return $this->catalogue();
        }
        $grants = $this->isCustom($role)
            ? ($this->rows()[$role]->permissions ?? [])
            : config("permissions.roles.{$role}", []);

        $expanded = [];
        foreach ($this->catalogue() as $ability) {
            foreach ($grants as $grant) {
                if ($grant === '*' || $grant === $ability || (str_ends_with($grant, '.*') && str_starts_with($ability, substr($grant, 0, -1)))) {
                    $expanded[] = $ability;
                    break;
                }
            }
        }

        return $expanded;
    }

    public function roleCeiling(string $role, array $extraRoles = []): array
    {
        $roles = array_unique([$role, ...array_intersect($extraRoles, $this->systemRoles())]);

        return array_values(array_unique(array_merge(...array_map(fn (string $key): array => $this->forRole($key), $roles))));
    }

    public function forUser(User $user): array
    {
        if (! $user->is_active) {
            return [];
        }
        if ($user->isRoot()) {
            return $this->catalogue();
        }

        return array_values(array_diff($this->roleCeiling($user->role, $user->extra_roles ?? []), $user->denied_permissions ?? []));
    }

    public function allows(User $user, string $ability): bool
    {
        return in_array($ability, $this->forUser($user), true);
    }

    public function sanitise(array $permissions): array
    {
        if (array_diff($permissions, $this->catalogue()) !== []) {
            throw ValidationException::withMessages(['permissions' => 'Unknown permission.']);
        }

        return array_values(array_intersect($this->catalogue(), $permissions));
    }

    public function holders(string $role): int
    {
        return User::where('role', $role)->orWhereJsonContains('extra_roles', $role)->count();
    }
}
