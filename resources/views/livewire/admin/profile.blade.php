<div class="page">
    <x-notices />
    <x-page-header :title="__('Profile')" :description="__('Manage your personal details and change your account password.')" />

    <div class="stack">
        <form wire:submit="updatePassword">
            <x-card :title="__('Change password')" :description="__('Ensure your account is using a long, random password to stay secure.')">
                <div class="form-grid">
                    <div class="span-full">
                        <x-form.input name="current_password" :label="__('Current password')" type="password" wire:model="current_password" required autocomplete="current-password" />
                    </div>
                    <x-form.input name="password" :label="__('New password')" type="password" wire:model="password" required autocomplete="new-password" :help="__('12+ characters with letters and numbers.')" />
                    <x-form.input name="password_confirmation" :label="__('Confirm new password')" type="password" wire:model="password_confirmation" required autocomplete="new-password" />
                </div>
                <x-slot:footer>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="updatePassword">{{ __('Update password') }}</x-button>
                </x-slot:footer>
            </x-card>
        </form>

        <form wire:submit="updateProfile">
            <x-card :title="__('Profile information')" :description="__('Update your name, email address, and contact details.')">
                <div class="form-grid">
                    <x-form.input name="name" :label="__('Full name')" wire:model="name" required maxlength="150" autocomplete="name" />
                    <x-form.input name="email" :label="__('Email address')" type="email" wire:model="email" required maxlength="255" autocomplete="email" />
                    <div class="span-full">
                        <x-form.input name="phone" :label="__('Phone number')" type="tel" wire:model="phone" maxlength="40" autocomplete="tel" />
                    </div>
                </div>
                <x-slot:footer>
                    <x-button type="submit" wire:loading.attr="disabled" wire:target="updateProfile">{{ __('Save profile') }}</x-button>
                </x-slot:footer>
            </x-card>
        </form>

        <x-card :title="__('Account details')" :description="__('Your account role and organisation assignment.')">
            <div class="form-grid">
                <div>
                    <label class="field-label">{{ __('Role') }}</label>
                    <div class="mt-1 flex items-center gap-2">
                        <x-badge tone="primary">{{ $permissions->label($user->role) }}</x-badge>
                        @foreach($user->extra_roles ?? [] as $extraRole)
                            <x-badge>{{ $permissions->label($extraRole) }}</x-badge>
                        @endforeach
                    </div>
                </div>
                @if($user->employee_code)
                    <div>
                        <label class="field-label">{{ __('Employee code') }}</label>
                        <p class="mt-1 font-medium text-heading">{{ $user->employee_code }}</p>
                    </div>
                @endif
                @if($user->designation)
                    <div>
                        <label class="field-label">{{ __('Designation') }}</label>
                        <p class="mt-1 font-medium text-heading">{{ $user->designation }}</p>
                    </div>
                @endif
                @if($user->department)
                    <div>
                        <label class="field-label">{{ __('Department') }}</label>
                        <p class="mt-1 font-medium text-heading">{{ $user->department }}</p>
                    </div>
                @endif
                @if(! $user->isRoot())
                    <div class="span-full">
                        <label class="field-label">{{ __('Assigned companies') }}</label>
                        <p class="mt-1 text-heading">
                            {{ $user->hasPermission('companies.all') ? __('All companies') : ($user->companies->pluck('name')->join(', ') ?: __('No companies assigned.')) }}
                        </p>
                    </div>
                @endif
            </div>
        </x-card>
    </div>
</div>
