<div class="page">
    <x-notices />
    <x-page-header :title="__('Payment methods')" :description="__('Cash, bank and mobile banking accounts that receive or pay money, with their current balance.')">
        @can('accounts.manage')<x-slot:actions><x-button icon="plus" :href="route('admin.payment-methods.create')">{{ __('Add payment method') }}</x-button></x-slot:actions> @endcan
    </x-page-header>
    @if(! $hasCompanies)
        <x-card><x-empty-state emoji="🔒" :title="__('No company yet')" :description="__('You are not assigned to any company yet.')" /></x-card>
    @else
        @forelse($groups as $group)
            <x-card flush :title="$showCompany ? $group['company']->name : null" wire:key="company-{{ $group['company']->id }}">
                <x-table :caption="$group['company']->name">
                    <x-slot:head><th>{{ __('Payment method') }}</th><th>{{ __('Type') }}</th><th>{{ __('Details') }}</th><th class="num">{{ __('Balance') }}</th><th>{{ __('Status') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
                    @foreach($group['methods'] as $method)
                        <tr wire:key="method-{{ $method->id }}">
                            <td><strong>{{ $method->name }}</strong><p class="muted">{{ $method->code }}</p></td>
                            <td>{{ $method->payment_type?->label() ?? '—' }}</td>
                            <td>{{ $method->details ?: '—' }}</td>
                            <td class="num"><x-money :value="$balances[$method->id] ?? 0" /></td>
                            <td><x-badge.active :active="$method->is_active" /></td>
                            <td><div class="row-actions">@can('accounts.manage')<x-button variant="ghost" size="sm" icon="pencil" :href="route('admin.payment-methods.edit', $method)" :label="__('Edit :name', ['name' => $method->name])">{{ __('Edit') }}</x-button>@endcan</div></td>
                        </tr>
                    @endforeach
                    <x-slot:foot><tr class="total-row"><th scope="row" colspan="3">{{ $showCompany ? __('Total for :company', ['company' => $group['company']->name]) : __('Total') }}</th><td class="num"><x-money :value="$group['total']" /></td><td colspan="2"></td></tr></x-slot:foot>
                </x-table>
            </x-card>
        @empty
            <x-card><x-empty-state emoji="💳" :title="__('No payment methods yet.')" :description="__('Add the cash, bank and mobile banking accounts that receive or pay money.')">@can('accounts.manage')<x-button icon="plus" :href="route('admin.payment-methods.create')">{{ __('Add payment method') }}</x-button>@endcan</x-empty-state></x-card>
        @endforelse
        @if($showCompany && $groups->isNotEmpty())
            <div class="card table-footer"><span>{{ __('Grand total: :amount', ['amount' => \App\Support\Money::format($grandTotal)]) }}</span></div>
        @endif
    @endif
</div>
