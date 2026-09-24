<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Permissions::class);
    }

    public function boot(): void
    {
        foreach (app(Permissions::class)->catalogue() as $permission) {
            Gate::define($permission, fn (User $user): bool => $user->hasPermission($permission));
        }
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->user()?->id ?? $request->ip()));
        RateLimiter::for('auth', fn (Request $request): array => [
            Limit::perMinute(30)->by($request->ip()),
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);
    }
}
