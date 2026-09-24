<?php

namespace App\Livewire\Admin;

use App\Models\Employee;
use App\Models\Media;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Dashboard extends Component
{
    public function render(): View
    {
        Gate::authorize('dashboard.view');

        return view('livewire.admin.dashboard', [
            'userCount' => Gate::allows('users.view') ? User::where('role', '!=', Permissions::ROOT_ROLE)->count() : null,
            'employeeCount' => Gate::allows('employees.view') ? Employee::count() : null,
            'roleCount' => Gate::allows('roles.view') ? count(app(Permissions::class)->assignableRoles()) : null,
            'mediaCount' => Gate::allows('media.view') ? Media::visibleTo(auth()->user())->count() : null,
        ])->layout('layouts.admin');
    }
}
