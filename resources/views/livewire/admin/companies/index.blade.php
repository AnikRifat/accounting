<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Organisation') }}</p><h1>{{ __('Companies') }}</h1><p class="muted">{{ __('Each company keeps its own books, parties and reports.') }}</p></div>@can('companies.create')<button type="button" class="btn" x-on:click="$dispatch('open-create-company')" aria-haspopup="dialog" aria-controls="create-company">{{ __('Add company') }}</button>@endcan</div>
    <div class="panel stack">
        <x-form.input name="search" :label="__('Search by name or code')" wire:model.live.debounce.300ms="search" type="search" maxlength="100" />
        <div class="table-wrap"><table><thead><tr><th>{{ __('Company') }}</th><th>{{ __('Code') }}</th><th>{{ __('Phone') }}</th><th>{{ __('Status') }}</th><th>{{ __('Actions') }}</th></tr></thead><tbody>
            @forelse($companies as $company)<tr wire:key="company-{{ $company->id }}"><td><strong>{{ $company->name }}</strong>@if($company->address)<p class="muted">{{ $company->address }}</p>@endif</td><td>{{ $company->code }}</td><td>{{ $company->phone ?: '—' }}</td><td><span class="badge {{ $company->is_active ? '' : 'badge-neutral' }}">{{ $company->is_active ? __('Active') : __('Inactive') }}</span></td><td>@can('companies.update')<a class="text-link" href="{{ route('admin.companies.edit', $company) }}" aria-label="{{ __('Edit :name', ['name' => $company->name]) }}" wire:navigate>{{ __('Edit') }}</a>@endcan</td></tr>
            @empty<tr><td colspan="5"><p class="muted">{{ __('No companies found.') }}</p></td></tr>@endforelse
        </tbody></table></div>{{ $companies->links() }}
    </div>
</div>
