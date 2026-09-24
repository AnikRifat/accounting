<?php

namespace App\Livewire\Admin;

use App\Models\ApplicationSetting;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Settings extends Component
{
    public string $appName = '';

    public string $supportEmail = '';

    public bool $registrationEnabled = true;

    public function mount(): void
    {
        Gate::authorize('settings.view');
        $settings = ApplicationSetting::values();
        $this->appName = $settings['app_name'];
        $this->supportEmail = $settings['support_email'];
        $this->registrationEnabled = $settings['registration_enabled'];
    }

    public function save(): void
    {
        Gate::authorize('settings.update');
        $this->validate(['appName' => ['required', 'string', 'max:80'], 'supportEmail' => ['nullable', 'email', 'max:255'], 'registrationEnabled' => ['boolean']]);
        DB::transaction(function (): void {
            foreach (['app_name' => $this->appName, 'support_email' => $this->supportEmail, 'registration_enabled' => $this->registrationEnabled] as $key => $value) {
                ApplicationSetting::updateOrCreate(['key' => $key], ['value' => $value]);
            }
        });
        session()->flash('success', 'Application settings saved.');
    }

    public function render(): View
    {
        return view('livewire.admin.settings')->layout('layouts.admin');
    }
}
