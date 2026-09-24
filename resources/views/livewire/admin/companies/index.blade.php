<div class="page">
    <x-notices />
    <x-page-header :title="__('Companies')" :description="__('Each company keeps its own books, parties and reports.')">
        @can('companies.create')<x-slot:actions><x-button icon="plus" x-on:click="$dispatch('open-create-company')" aria-haspopup="dialog" aria-controls="create-company">{{ __('Add company') }}</x-button></x-slot:actions> @endcan
    </x-page-header>
    <x-card flush>
        <x-slot:toolbar><x-toolbar><x-form.input name="search" :label="__('Search by name or code')" wire:model.live.debounce.300ms="search" type="search" maxlength="100" :placeholder="__('Name or code…')" /></x-toolbar></x-slot:toolbar>
        <x-table :caption="__('Companies')">
            <x-slot:head><th>{{ __('Company') }}</th><th>{{ __('Code') }}</th><th>{{ __('Phone') }}</th><th>{{ __('Status') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @forelse($companies as $company)
                <tr wire:key="company-{{ $company->id }}">
                    <td><strong>{{ $company->name }}</strong>@if($company->address)<p class="muted">{{ $company->address }}</p>@endif</td>
                    <td><x-badge>{{ $company->code }}</x-badge></td>
                    <td class="nowrap">{{ $company->phone ?: '—' }}</td>
                    <td><x-badge.active :active="$company->is_active" /></td>
                    <td><div class="row-actions">@can('companies.update')<x-button variant="ghost" size="sm" icon="pencil" :href="route('admin.companies.edit', $company)" :navigate="false" wire:click.prevent="openSheet('edit:{{ $company->id }}')" :label="__('Edit :name', ['name' => $company->name])">{{ __('Edit') }}</x-button>@endcan</div></td>
                </tr>
            @empty
                <x-table.empty colspan="5" emoji="🏢">{{ __('No companies found.') }}</x-table.empty>
            @endforelse
        </x-table>
        {{ $companies->links() }}
    </x-card>
    <x-sheet :label="__('Company')">
        @if($this->sheetAction() === 'edit')
            <livewire:admin.companies.form :company="\App\Models\Company::visibleTo(auth()->user())->findOrFail((int) $this->sheetArgument())" :key="'sheet-'.$sheet" />
        @endif
    </x-sheet>
</div>
