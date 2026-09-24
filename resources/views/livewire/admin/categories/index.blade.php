<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Accounting') }}</p><h1>{{ __('Categories') }}</h1><p class="muted">{{ __('What money is earned from or spent on. Each company keeps its own list.') }}</p></div>@can('accounts.manage')<a class="btn" href="{{ route('admin.categories.create') }}" wire:navigate>{{ __('Add category') }}</a>@endcan</div>
    @if($companies === [])
        <div class="panel"><p class="muted">{{ __('You are not assigned to any company yet.') }}</p></div>
    @else
        <div class="panel stack">
            <x-form.select name="companyId" :label="__('Company')" wire:model.live="companyId" :options="$companies" />
            @foreach($groups as $heading => $categories)
                <div class="table-wrap" wire:key="group-{{ $loop->index }}"><table><caption class="sr-only">{{ $heading }}</caption><thead><tr><th>{{ $heading }}</th><th>{{ __('Status') }}</th><th>{{ __('Actions') }}</th></tr></thead><tbody>
                    @forelse($categories as $category)<tr wire:key="category-{{ $category->id }}"><td><strong>{{ $category->name }}</strong><p class="muted">{{ $category->code }}</p></td><td><span class="badge {{ $category->is_active ? '' : 'badge-neutral' }}">{{ $category->is_active ? __('Active') : __('Inactive') }}</span></td><td>@can('accounts.manage')<a class="text-link" href="{{ route('admin.categories.edit', $category) }}" aria-label="{{ __('Edit :name', ['name' => $category->name]) }}" wire:navigate>{{ __('Edit') }}</a>@endcan</td></tr>
                    @empty<tr><td colspan="3"><p class="muted">{{ __('No categories yet.') }}</p></td></tr>@endforelse
                </tbody></table></div>
            @endforeach
        </div>
    @endif
</div>
