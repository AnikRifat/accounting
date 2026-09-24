<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Accounting') }}</p><h1>{{ __('Chart of accounts') }}</h1><p class="muted">{{ __('Accounts of each company with their current balance. Voided entries are excluded.') }}</p></div>@can('accounts.manage')<a class="btn" href="{{ route('admin.accounts.create') }}" wire:navigate>{{ __('Add account') }}</a>@endcan</div>
    @if($companies === [])
        <div class="panel"><p class="muted">{{ __('You are not assigned to any company yet.') }}</p></div>
    @else
        <div class="panel stack">
            <x-form.select name="companyId" :label="__('Company')" wire:model.live="companyId" :options="$companies" />
            @foreach($groups as $type => $accounts)
                @if($accounts->isNotEmpty())
                    <div class="table-wrap" wire:key="group-{{ $type }}"><table><caption class="sr-only">{{ \App\Enums\AccountType::from($type)->label() }}</caption><thead><tr><th>{{ \App\Enums\AccountType::from($type)->label() }}</th><th>{{ __('Status') }}</th><th class="text-right">{{ __('Balance') }}</th><th>{{ __('Actions') }}</th></tr></thead><tbody>
                        @foreach($accounts as $account)<tr wire:key="account-{{ $account->id }}"><td><strong>{{ $account->code }} · {{ $account->name }}</strong>@if($account->is_cash)<p class="muted">{{ $account->payment_type?->label() }}@if($account->details) · {{ $account->details }}@endif</p>@endif</td><td>@if($account->is_system)<span class="badge badge-neutral">{{ __('System') }}</span> @endif<span class="badge {{ $account->is_active ? '' : 'badge-neutral' }}">{{ $account->is_active ? __('Active') : __('Inactive') }}</span></td><td class="text-right tabular-nums">{{ \App\Support\Money::format($balances[$account->id] ?? 0) }}</td><td>@if(! $account->is_system)@can('accounts.manage')<a class="text-link" href="{{ route('admin.accounts.edit', $account) }}" aria-label="{{ __('Edit :name', ['name' => $account->name]) }}" wire:navigate>{{ __('Edit') }}</a>@endcan @endif</td></tr>@endforeach
                    </tbody></table></div>
                @endif
            @endforeach
        </div>
    @endif
</div>
