<div class="page">
    <x-page-header :title="__('Choose a company')" :description="$forCreate ? __('New records belong to one company. Pick it here; the header switches to that company.') : __('This page shows one company at a time. Pick it here; the header switches to that company.')" />
    <x-card>
        @forelse($companies as $company)
            <button type="button" class="company-choice" wire:key="choose-{{ $company->id }}" wire:click="choose({{ $company->id }})" wire:loading.attr="disabled">
                <span class="flex items-center gap-3"><span class="stat-icon" aria-hidden="true">🏢</span><strong class="text-heading">{{ $company->name }}</strong></span>
                <x-badge>{{ $company->code }}</x-badge>
            </button>
        @empty
            <x-empty-state emoji="🏢" :title="$forCreate ? __('No company to record into') : __('No company available')" :description="$forCreate ? __('You have no active company to record into. Ask your administrator to assign you one.') : __('Ask your administrator to assign you a company.')" />
        @endforelse
    </x-card>
</div>
