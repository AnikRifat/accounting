<?php

namespace Database\Seeders;

use App\Models\ApplicationSetting;
use App\Models\Employee;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Throwaway demo data for trying the starter locally.
 *
 * Creates one account per role with a single generated password printed once,
 * so no credential is ever hardcoded or committed. Re-running reuses the same
 * accounts and prints a fresh password.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (App::environment('production')) {
            throw new RuntimeException('DemoSeeder is local-only and refuses to run in production.');
        }

        $password = Str::password(16, symbols: false);

        RolePermission::updateOrCreate(
            ['role' => 'support-agent'],
            [
                'label' => 'Support Agent',
                'permissions' => app(Permissions::class)->sanitise([
                    'admin.access', 'dashboard.view', 'users.view', 'media.view', 'media.upload',
                ]),
                'is_active' => true,
            ],
        );
        app(Permissions::class)->flush();

        $accounts = [
            ['Demo Owner', 'demo-owner@example.test', Permissions::ROOT_ROLE],
            ['Demo Administrator', 'demo-admin@example.test', 'administrator'],
            ['Demo Employee', 'demo-employee@example.test', 'employee'],
            ['Demo Support Agent', 'demo-support@example.test', 'support-agent'],
            ['Demo Member', 'demo-member@example.test', 'member'],
        ];

        foreach ($accounts as [$name, $email, $role]) {
            $user = User::firstOrNew(['email' => $email]);
            $user->fill(['name' => $name, 'password' => $password])
                ->forceFill(['role' => $role, 'is_active' => true, 'email_verified_at' => now()])
                ->save();

            if ($role === 'employee' || $role === 'support-agent') {
                Employee::updateOrCreate(
                    ['user_id' => $user->id],
                    ['employee_code' => 'EMP-'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT), 'job_title' => $name],
                );
            }
        }

        // A suspended account, so the disabled-access path is visible in the UI.
        User::firstOrNew(['email' => 'demo-suspended@example.test'])
            ->fill(['name' => 'Demo Suspended', 'password' => $password])
            ->forceFill(['role' => 'employee', 'is_active' => false, 'email_verified_at' => now()])
            ->save();

        ApplicationSetting::updateOrCreate(['key' => 'app_name'], ['value' => 'Starter Demo']);

        $this->command?->newLine();
        $this->command?->info('Demo data seeded. Password for every demo account (shown once):');
        $this->command?->line('  '.$password);
        $this->command?->newLine();
        $this->command?->table(
            ['Email', 'Role'],
            [...array_map(fn (array $a): array => [$a[1], $a[2]], $accounts), ['demo-suspended@example.test', 'employee (suspended)']],
        );
        $this->command?->line('Admin: http://127.0.0.1:8000/admin/login    Frontend: http://127.0.0.1:3000/login');
    }
}
