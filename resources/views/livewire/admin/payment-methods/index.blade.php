<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Accounting') }}</p><h1>{{ __('Payment methods') }}</h1><p class="muted">{{ __('Cash, bank and mobile banking accounts that receive or pay money, with their current balance.') }}</p></div>@can('accounts.manage')<a class="btn" href="{{ route('admin.payment-methods.create') }}" wire:navigate>{{ __('Add payment method') }}</a>@endcan</div>
    @if($companies === [])
        <div class="panel"><p class="muted">{{ __('You are not assigned to any company yet.') }}</p></div>
    @else
        <div class="panel stack">
            <x-form.select name="companyId" :label="__('Company')" wire:model.live="companyId" :options="$companies" />
            <div class="table-wrap"><table><thead><tr><th>{{ __('Payment method') }}</th><th>{{ __('Type') }}</th><th>{{ __('Details') }}</th><th class="text-right">{{ __('Balance') }}</th><th>{{ __('Status') }}</th><th>{{ __('Actions') }}</th></tr></thead><tbody>
                @forelse($methods as $method)<tr wire:key="method-{{ $method->id }}"><td><strong>{{ $method->name }}</strong><p class="muted">{{ $method->code }}</p></td><td>{{ $method->payment_type?->label() ?? '—' }}</td><td>{{ $method->details ?: '—' }}</td><td class="text-right tabular-nums">{{ \App\Support\Money::format($balances[$method->id] ?? 0) }}</td><td><span class="badge {{ $method->is_active ? '' : 'badge-neutral' }}">{{ $method->is_active ? __('Active') : __('Inactive') }}</span></td><td>@can('accounts.manage')<a class="text-link" href="{{ route('admin.payment-methods.edit', $method) }}" aria-label="{{ __('Edit :name', ['name' => $method->name]) }}" wire:navigate>{{ __('Edit') }}</a>@endcan</td></tr>
                @empty<tr><td colspan="6"><p class="muted">{{ __('No payment methods yet.') }}</p></td></tr>@endforelse
            </tbody></table></div>
        </div>
    @endif
</div>
