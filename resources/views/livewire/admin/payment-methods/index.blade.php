<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Accounting') }}</p><h1>{{ __('Payment methods') }}</h1><p class="muted">{{ __('Cash, bank and mobile banking accounts that receive or pay money, with their current balance.') }}</p></div>@can('accounts.manage')<a class="btn" href="{{ route('admin.payment-methods.create') }}" wire:navigate>{{ __('Add payment method') }}</a>@endcan</div>
    @if(! $hasCompanies)
        <div class="panel"><p class="muted">{{ __('You are not assigned to any company yet.') }}</p></div>
    @else
        <div class="panel stack">
            @forelse($groups as $group)
                <div class="table-wrap" wire:key="company-{{ $group['company']->id }}"><table>@if($showCompany)<caption class="text-left font-semibold">{{ $group['company']->name }}</caption>@endif<thead><tr><th>{{ __('Payment method') }}</th><th>{{ __('Type') }}</th><th>{{ __('Details') }}</th><th class="text-right">{{ __('Balance') }}</th><th>{{ __('Status') }}</th><th>{{ __('Actions') }}</th></tr></thead><tbody>
                    @foreach($group['methods'] as $method)<tr wire:key="method-{{ $method->id }}"><td><strong>{{ $method->name }}</strong><p class="muted">{{ $method->code }}</p></td><td>{{ $method->payment_type?->label() ?? '—' }}</td><td>{{ $method->details ?: '—' }}</td><td class="text-right tabular-nums">{{ \App\Support\Money::format($balances[$method->id] ?? 0) }}</td><td><span class="badge {{ $method->is_active ? '' : 'badge-neutral' }}">{{ $method->is_active ? __('Active') : __('Inactive') }}</span></td><td>@can('accounts.manage')<a class="text-link" href="{{ route('admin.payment-methods.edit', $method) }}" aria-label="{{ __('Edit :name', ['name' => $method->name]) }}" wire:navigate>{{ __('Edit') }}</a>@endcan</td></tr>@endforeach
                </tbody><tfoot><tr><th scope="row" colspan="3">{{ $showCompany ? __('Total for :company', ['company' => $group['company']->name]) : __('Total') }}</th><td class="text-right tabular-nums"><strong>{{ \App\Support\Money::format($group['total']) }}</strong></td><td colspan="2"></td></tr></tfoot></table></div>
            @empty
                <p class="muted">{{ __('No payment methods yet.') }}</p>
            @endforelse
            @if($showCompany && $groups->isNotEmpty())
                <p class="text-right"><strong>{{ __('Grand total: :amount', ['amount' => \App\Support\Money::format($grandTotal)]) }}</strong></p>
            @endif
        </div>
    @endif
</div>
