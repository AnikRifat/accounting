<?php

use App\Http\Controllers\Api\V1\MediaController;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Media\Index as MediaIndex;
use App\Livewire\Admin\Roles\Form as RoleForm;
use App\Livewire\Admin\Roles\Index as RoleIndex;
use App\Livewire\Admin\Settings;
use App\Livewire\Admin\Users\Form as UserForm;
use App\Livewire\Admin\Users\Index as UserIndex;
use App\Livewire\Auth\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/media/{media}/download', [MediaController::class, 'download'])->middleware('signed')->name('media.download');
Route::redirect('/', '/admin');
Route::livewire('/admin/login', Login::class)->middleware('guest')->name('login');
Route::post('/admin/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::prefix('admin')->name('admin.')->middleware(['auth', 'active', 'can:admin.access'])->group(function (): void {
    Route::livewire('/', Dashboard::class)->middleware('can:dashboard.view')->name('dashboard');
    foreach (['users' => false, 'employees' => true] as $module => $employee) {
        Route::livewire('/'.$module, UserIndex::class)->defaults('employee', $employee)->middleware('can:'.$module.'.view')->name($module.'.index');
        Route::livewire('/'.$module.'/create', UserForm::class)->defaults('employee', $employee)->middleware('can:'.$module.'.create')->name($module.'.create');
        Route::livewire('/'.$module.'/{user}/edit', UserForm::class)->defaults('employee', $employee)->middleware('can:'.$module.'.update')->name($module.'.edit');
    }
    Route::livewire('/roles', RoleIndex::class)->middleware('can:roles.view')->name('roles.index');
    Route::livewire('/roles/create', RoleForm::class)->middleware(['can:roles.create', 'can:permissions.manage'])->name('roles.create');
    Route::livewire('/roles/{role}/edit', RoleForm::class)->middleware('can:roles.update')->name('roles.edit');
    Route::livewire('/settings', Settings::class)->middleware('can:settings.view')->name('settings');
    Route::livewire('/media', MediaIndex::class)->middleware('can:media.view')->name('media');
});
