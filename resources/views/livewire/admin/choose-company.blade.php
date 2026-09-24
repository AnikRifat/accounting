<div>
    <div class="page-header"><div><p class="eyebrow">{{ __('Company') }}</p><h1>{{ __('Choose a company') }}</h1><p class="muted">{{ __('New records belong to one company. Pick it here; the header switches to that company.') }}</p></div></div>
    <div class="panel stack">
        @forelse($companies as $company)
            <button type="button" class="btn btn-secondary company-choice" wire:key="choose-{{ $company->id }}" wire:click="choose({{ $company->id }})" wire:loading.attr="disabled">
                <strong>{{ $company->name }}</strong> <span class="muted">{{ $company->code }}</span>
            </button>
        @empty
            <p class="muted">{{ __('You have no active company to record into. Ask your administrator to assign you one.') }}</p>
        @endforelse
    </div>
</div>
