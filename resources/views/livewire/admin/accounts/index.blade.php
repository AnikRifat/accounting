<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Accounting') }}</p><h1>{{ __('Chart of accounts') }}</h1><p class="muted">{{ $all ? __('Accounts of all your companies, combined by name, with their current balance. Voided entries are excluded.') : __('Accounts with their current balance. Voided entries are excluded.') }}</p></div>@can('accounts.manage')<a class="btn" href="{{ route('admin.accounts.create') }}" wire:navigate>{{ __('Add account') }}</a>@endcan</div>
    @if(! $hasCompanies)
        <div class="panel"><p class="muted">{{ __('You are not assigned to any company yet.') }}</p></div>
    @else
        <div class="panel stack">
            @foreach($groups as $type => $rows)
                @if($rows->isNotEmpty())
                    <div class="table-wrap" wire:key="group-{{ $type }}"><table><caption class="sr-only">{{ \App\Enums\AccountType::from($type)->label() }}</caption><thead><tr><th>{{ \App\Enums\AccountType::from($type)->label() }}</th>@if($all)<th>{{ __('Companies') }}</th>@else<th>{{ __('Status') }}</th>@endif<th class="text-right">{{ __('Balance') }}</th>@unless($all)<th>{{ __('Actions') }}</th>@endunless</tr></thead><tbody>
                        @if($all)
                            @foreach($rows as $row)<tr wire:key="combined-{{ $type }}-{{ $loop->index }}"><td><strong>{{ $row['name'] }}</strong><p class="muted">{{ $row['codes'] }}</p></td>
                                <td><ul class="grid gap-1">@foreach($row['accounts'] as $account)<li wire:key="part-{{ $account->id }}" class="flex flex-wrap items-center gap-2 text-sm"><span>{{ $account->company->name }}</span><span class="tabular-nums muted">{{ \App\Support\Money::format($balances[$account->id] ?? 0) }}</span>@unless($account->is_active)<span class="badge badge-neutral">{{ __('Inactive') }}</span>@endunless @if(! $account->is_system)@can('accounts.manage')<button class="text-link" type="button" wire:click="edit({{ $account->id }})" aria-label="{{ __('Edit :name in :company', ['name' => $account->name, 'company' => $account->company->name]) }}">{{ __('Edit') }}</button>@endcan @endif</li>@endforeach</ul></td>
                                <td class="text-right tabular-nums">{{ \App\Support\Money::format($row['balance']) }}</td></tr>@endforeach
                        @else
                            @foreach($rows as $account)<tr wire:key="account-{{ $account->id }}"><td><strong>{{ $account->code }} · {{ $account->name }}</strong>@if($account->is_cash)<p class="muted">{{ $account->payment_type?->label() }}@if($account->details) · {{ $account->details }}@endif</p>@endif</td><td>@if($account->is_system)<span class="badge badge-neutral">{{ __('System') }}</span> @endif<span class="badge {{ $account->is_active ? '' : 'badge-neutral' }}">{{ $account->is_active ? __('Active') : __('Inactive') }}</span></td><td class="text-right tabular-nums">{{ \App\Support\Money::format($balances[$account->id] ?? 0) }}</td><td>@if(! $account->is_system)@can('accounts.manage')<a class="text-link" href="{{ route('admin.accounts.edit', $account) }}" aria-label="{{ __('Edit :name', ['name' => $account->name]) }}" wire:navigate>{{ __('Edit') }}</a>@endcan @endif</td></tr>@endforeach
                        @endif
                    </tbody></table></div>
                @endif
            @endforeach
        </div>
    @endif
</div>
