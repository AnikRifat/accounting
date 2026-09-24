<?php

namespace App\Models;

use App\Support\Permissions;
use Database\Factories\RolePermissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['role', 'label', 'permissions', 'is_active'])]
class RolePermission extends Model
{
    /** @use HasFactory<RolePermissionFactory> */
    use HasFactory;

    /** The registry caches role rows, so any saved change must reach it. */
    protected static function booted(): void
    {
        static::saved(fn () => app(Permissions::class)->flush());
    }

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_active' => 'boolean'];
    }
}
