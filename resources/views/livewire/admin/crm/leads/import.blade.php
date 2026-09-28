<div class="page">
    <x-notices />
    <x-page-header :title="__('Import leads')" :back="route('admin.crm.leads.index')" :back-label="__('Leads')">
        <x-slot:meta><p><x-badge tone="primary">{{ __('Company: :name', ['name' => $companyName]) }}</x-badge></p></x-slot:meta>
    </x-page-header>
    @error('company')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
    @if($result)
        <x-alert :tone="$result['created'] > 0 ? 'success' : 'warning'" :title="trans_choice(':count lead imported.|:count leads imported.', $result['created'], ['count' => $result['created']])">
            @if($result['duplicates'] > 0)<p>{{ trans_choice(':count row skipped: the phone number is already a lead.|:count rows skipped: the phone number is already a lead.', $result['duplicates'], ['count' => $result['duplicates']]) }}</p>@endif
            @if($result['invalid'] !== [])<p>{{ __('Skipped for a missing or invalid phone number: rows :rows.', ['rows' => implode(', ', array_slice($result['invalid'], 0, 15)).(count($result['invalid']) > 15 ? '…' : '')]) }}</p>@endif
            <p class="mt-2"><a class="text-link" href="{{ route('admin.crm.leads.index') }}" wire:navigate>{{ __('See the leads') }}</a></p>
        </x-alert>
    @endif
    <form wire:submit="import" class="stack">
        <x-card :title="__('File')" :description="__('CSV or Excel (.xlsx), up to :max rows. The first row names the columns; only Phone is required.', ['max' => \App\Services\LeadImporter::MAX_ROWS])">
            <div class="field">
                <label for="import-file">{{ __('Spreadsheet') }}<span class="required-mark" aria-hidden="true"> *</span></label>
                <input id="import-file" type="file" class="form-control" wire:model="file" accept=".csv,.xlsx,text/csv" aria-invalid="{{ $errors->has('file') ? 'true' : 'false' }}" @error('file') aria-describedby="import-file-error" @enderror>
                <p class="muted" wire:loading wire:target="file">{{ __('Uploading…') }}</p>
                @error('file')<p id="import-file-error" class="error">{{ $message }}</p>@enderror
            </div>
            <details class="disclosure"><summary>{{ __('Accepted column names') }}</summary>
                <ul class="muted mt-2 stack-sm">
                    @foreach($columns as $field => $aliases)<li><strong>{{ $aliases[0] }}</strong>@if(count($aliases) > 1) · {{ implode(', ', array_slice($aliases, 1)) }}@endif</li>@endforeach
                </ul>
                <p class="muted mt-2">{{ __('Dates are read as 2026-10-01 or day first (01/10/2026). Unknown services, sources and statuses use the defaults below.') }}</p>
            </details>
            <p><a class="text-link" href="{{ route('admin.crm.leads.import-template') }}">{{ __('Download a template') }}</a></p>
        </x-card>
        <x-card :title="__('Defaults')">
            <div class="form-grid">
                <x-form.select name="statusId" :label="__('Status')" :options="$statuses" wire:model="statusId" required />
                <x-form.select name="serviceId" :label="__('Service')" :options="$services" wire:model="serviceId" />
                <x-form.select name="sourceId" :label="__('Source')" :options="$sources" wire:model="sourceId" />
                @if($canAssign)<x-form.select name="assignTo" :label="__('Assign to')" :options="$assignees" wire:model="assignTo" />@endif
            </div>
        </x-card>
        <x-form.actions :submit="__('Import leads')" :cancel="route('admin.crm.leads.index')" />
    </form>
</div>
