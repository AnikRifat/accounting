<div class="page">
    <x-notices />
    <x-page-header :title="__('Email lead')" :back="route('admin.crm.leads.show', $lead)" :back-label="$lead->displayName()">
        <x-slot:meta><p class="btn-group"><x-badge tone="primary">{{ __('Company: :name', ['name' => $lead->company->name]) }}</x-badge></p></x-slot:meta>
    </x-page-header>
    @unless($delivers)
        <x-alert tone="warning" :title="__('Email is not set up')">{{ __('The server writes email to the log instead of sending it, so this email will not reach the lead. Choose SMTP server in Settings > Mail.') }}@can('settings.update') <a class="text-link" href="{{ route('admin.settings', ['tab' => 'mail']) }}" wire:navigate>{{ __('Open mail settings') }}</a>@endcan</x-alert>
    @endunless
    @error('send')<x-alert tone="danger" :title="__('Not sent')">{{ $message }}</x-alert>@enderror
    <form wire:submit="send" class="stack">
        <x-card :title="__('Email')" :description="__('Sent now through the mail settings. Replies come to your own email address.')">
            <div class="form-grid">
                <x-form.input name="to" :label="__('To')" type="email" wire:model="to" required maxlength="255" autocomplete="off" />
                <x-form.input name="cc" :label="__('Cc')" wire:model="cc" maxlength="500" autocomplete="off" :help="__('Optional. Separate addresses with commas.')" />
                <div class="span-full"><x-form.input name="subject" :label="__('Subject')" wire:model="subject" required maxlength="200" autocomplete="off" /></div>
                <div class="field span-full">
                    <label for="message">{{ __('Message') }}<span class="required-mark" aria-hidden="true"> *</span></label>
                    <textarea id="message" name="message" class="form-control h-auto py-2" rows="12" wire:model="message" maxlength="10000" required aria-invalid="{{ $errors->has('message') ? 'true' : 'false' }}" @error('message') aria-describedby="message-error" @enderror></textarea>
                    @error('message')<p id="message-error" class="error">{{ $message }}</p>@enderror
                </div>
            </div>
        </x-card>
        <x-form.actions :submit="__('Send email')" :cancel="route('admin.crm.leads.show', $lead)" />
    </form>
</div>
