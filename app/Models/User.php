<?php

namespace App\Models;

use App\Concerns\HasMedia;
use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    protected $attributes = ['role' => 'member', 'is_active' => true, 'extra_roles' => '[]', 'denied_permissions' => '[]'];

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasMedia, Notifiable;

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed',
            'is_active' => 'boolean', 'extra_roles' => 'array', 'denied_permissions' => 'array'];
    }

    public function isRoot(): bool
    {
        return $this->role === Permissions::ROOT_ROLE;
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function hasPermission(string $permission): bool
    {
        return app(Permissions::class)->allows($this, $permission);
    }
}
