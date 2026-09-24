<div class="page">
    <x-notices />
    <x-page-header :title="__('Chart of accounts')" :description="$all ? __('Accounts of all your companies, combined by name, with their current balance. Voided entries are excluded.') : __('Accounts with their current balance. Voided entries are excluded.')">
        @can('accounts.manage')<x-slot:actions><x-button icon="plus" :href="route('admin.accounts.create')" :navigate="false" wire:click.prevent="openSheet('create')">{{ __('Add account') }}</x-button></x-slot:actions> @endcan
    </x-page-header>
    @if(! $hasCompanies)
        <x-card><x-empty-state emoji="🔒" :title="__('No company yet')" :description="__('You are not assigned to any company yet.')" /></x-card>
    @else
        @foreach($groups as $type => $rows)
            @if($rows->isNotEmpty())
                <x-card flush wire:key="group-{{ $type }}">
                    <x-table :caption="\App\Enums\AccountType::from($type)->label()">
                        <x-slot:head><th>{{ \App\Enums\AccountType::from($type)->label() }}</th>@if($all)<th>{{ __('Companies') }}</th>@else<th>{{ __('Status') }}</th>@endif<th class="num">{{ __('Balance') }}</th>@unless($all)<th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th>@endunless</x-slot:head>
                        @if($all)
                            @foreach($rows as $row)
                                <tr wire:key="combined-{{ $type }}-{{ $loop->index }}"><td><strong>{{ $row['name'] }}</strong><p class="muted">{{ $row['codes'] }}</p></td>
                                    <td><ul class="stack-sm">@foreach($row['accounts'] as $account)<li wire:key="part-{{ $account->id }}" class="flex flex-wrap items-center gap-2"><span>{{ $account->company->name }}</span><x-money class="muted" :value="$balances[$account->id] ?? 0" />@unless($account->is_active)<x-badge>{{ __('Inactive') }}</x-badge>@endunless @if(! $account->is_system)@can('accounts.manage')<x-button variant="ghost" size="sm" icon="pencil" wire:click="edit({{ $account->id }})" :label="__('Edit :name in :company', ['name' => $account->name, 'company' => $account->company->name])" />@endcan @can('accounts.delete')<x-button variant="ghost" size="sm" icon="trash" class="text-danger" x-on:click="$dispatch('open-delete', { kind: 'account', id: {{ $account->id }} })" :label="__('Delete :name in :company', ['name' => $account->name, 'company' => $account->company->name])" />@endcan @endif</li>@endforeach</ul></td>
                                    <td class="num"><x-money :value="$row['balance']" /></td></tr>
                            @endforeach
                        @else
                            @foreach($rows as $account)
                                <tr wire:key="account-{{ $account->id }}"><td><strong>{{ $account->code }} · {{ $account->name }}</strong>@if($account->is_cash)<p class="muted">{{ $account->payment_type?->label() }}@if($account->details) · {{ $account->details }}@endif</p>@endif</td>
                                    <td><span class="flex flex-wrap gap-1">@if($account->is_system)<x-badge tone="info">{{ __('System') }}</x-badge>@endif<x-badge.active :active="$account->is_active" /></span></td>
                                    <td class="num"><x-money :value="$balances[$account->id] ?? 0" /></td>
                                    <td><div class="row-actions">@if(! $account->is_system)@can('accounts.manage')<x-button variant="ghost" size="sm" icon="pencil" :href="route('admin.accounts.edit', $account)" :navigate="false" wire:click.prevent="openSheet('edit:{{ $account->id }}')" :label="__('Edit :name', ['name' => $account->name])">{{ __('Edit') }}</x-button>@endcan @can('accounts.delete')<x-button variant="ghost" size="sm" icon="trash" class="text-danger" x-on:click="$dispatch('open-delete', { kind: 'account', id: {{ $account->id }} })" :label="__('Delete :name', ['name' => $account->name])" />@endcan @endif</div></td></tr>
                            @endforeach
                        @endif
                    </x-table>
                </x-card>
            @endif
        @endforeach
    @endif
    <x-sheet :label="__('Account')">
        @if($this->sheetAction() === 'create')
            <livewire:admin.accounts.form :key="'sheet-'.$sheet" />
        @elseif($this->sheetAction() === 'edit')
            <livewire:admin.accounts.form :account="\App\Models\Account::query()->whereIn('company_id', auth()->user()->accessibleCompanyIds())->findOrFail((int) $this->sheetArgument())" :key="'sheet-'.$sheet" />
        @endif
    </x-sheet>
</div>
