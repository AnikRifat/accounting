<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Accounting') }}</p><h1>{{ __('Categories') }}</h1><p class="muted">{{ $isAll ? __('What money is earned from or spent on, combined across companies. Each company keeps its own categories.') : __('What money is earned from or spent on.') }}</p></div>@can('accounts.manage')<a class="btn" href="{{ route('admin.categories.create') }}" wire:navigate>{{ $isAll ? __('Add category to all companies') : __('Add category') }}</a>@endcan</div>
    @if(! $hasCompanies)
        <div class="panel"><p class="muted">{{ __('You are not assigned to any company yet.') }}</p></div>
    @else
        <div class="panel stack">
            @foreach($groups as $heading => $rows)
                <div class="table-wrap" wire:key="group-{{ $loop->index }}"><table><caption class="sr-only">{{ $heading }}</caption><thead><tr><th>{{ $heading }}</th>@if($isAll)<th>{{ __('Companies') }}</th>@else<th>{{ __('Status') }}</th><th>{{ __('Actions') }}</th>@endif</tr></thead><tbody>
                    @forelse($rows as $key => $categories)
                        @if($isAll)
                            <tr wire:key="category-{{ $loop->parent->index }}-{{ $loop->index }}"><td><strong>{{ $categories->first()->name }}</strong></td><td><ul class="stack gap-1">
                                @foreach($categories as $category)<li wire:key="in-{{ $category->id }}">{{ $category->company->name }} <span class="badge {{ $category->is_active ? '' : 'badge-neutral' }}">{{ $category->is_active ? __('Active') : __('Inactive') }}</span> @can('accounts.manage')<button type="button" class="text-link" wire:click="editIn({{ $category->id }})" aria-label="{{ __('Edit :name in :company', ['name' => $category->name, 'company' => $category->company->name]) }}">{{ __('Edit') }}</button>@endcan</li>@endforeach
                            </ul></td></tr>
                        @else
                            @php($category = $categories->first())
                            <tr wire:key="category-{{ $category->id }}"><td><strong>{{ $category->name }}</strong><p class="muted">{{ $category->code }}</p></td><td><span class="badge {{ $category->is_active ? '' : 'badge-neutral' }}">{{ $category->is_active ? __('Active') : __('Inactive') }}</span></td><td>@can('accounts.manage')<a class="text-link" href="{{ route('admin.categories.edit', $category) }}" aria-label="{{ __('Edit :name', ['name' => $category->name]) }}" wire:navigate>{{ __('Edit') }}</a>@endcan</td></tr>
                        @endif
                    @empty<tr><td colspan="{{ $isAll ? 2 : 3 }}"><p class="muted">{{ __('No categories yet.') }}</p></td></tr>@endforelse
                </tbody></table></div>
            @endforeach
        </div>
    @endif
</div>
