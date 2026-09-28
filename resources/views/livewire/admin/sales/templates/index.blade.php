<div class="page">
    <x-notices />
    <x-page-header :title="__('Document templates')" :description="__('How invoices, quotations and other documents look: logo, colours, texts and the order of their blocks. Each company keeps its own templates.')">
        <x-slot:actions><x-button icon="plus" :href="route('admin.sales.templates.create')">{{ __('Add template') }}</x-button></x-slot:actions>
    </x-page-header>
    <x-card flush>
        <x-table :caption="__('Document templates')">
            <x-slot:head><th>{{ __('Template') }}</th>@if($showCompany)<th>{{ __('Company') }}</th>@endif<th>{{ __('Layout') }}</th><th>{{ __('Default for') }}</th><th class="num">{{ __('Documents') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @forelse($templates as $template)
                @php($types = $defaultFor[$template->id] ?? [])
                <tr wire:key="template-{{ $template->id }}">
                    <td>
                        <span class="inline-flex items-center gap-2"><span class="inline-block size-3 rounded-full" style="background: {{ $template->accent_color }}" aria-hidden="true"></span><strong>{{ $template->name }}</strong></span>
                        @if($template->is_default) <x-badge tone="success">{{ __('Company default') }}</x-badge>@endif
                    </td>
                    @if($showCompany)<td>{{ $template->company->name }}</td>@endif
                    <td>{{ $layouts[$template->layout] ?? $template->layout }}</td>
                    <td>@if($types !== []){{ implode(', ', $types) }}@else<span class="muted">—</span>@endif</td>
                    <td class="num">{{ $template->documents_count }}</td>
                    <td><div class="row-actions">
                        <x-button variant="ghost" size="sm" icon="pencil" :href="route('admin.sales.templates.edit', $template)" :label="__('Edit :name', ['name' => $template->name])">{{ __('Edit') }}</x-button>
                        <x-button variant="ghost" size="sm" icon="trash" class="text-danger" wire:click="delete({{ $template->id }})"
                            wire:confirm="{{ $template->documents_count > 0 || $types !== [] || $template->is_default
                                ? trans_choice('Delete :name? :count document uses it; it and any document type set to this template will use the company default template instead.|Delete :name? :count documents use it; they and any document type set to this template will use the company default template instead.', $template->documents_count, ['name' => $template->name, 'count' => $template->documents_count])
                                : __('Delete :name?', ['name' => $template->name]) }}"
                            :label="__('Delete :name', ['name' => $template->name])" />
                    </div></td>
                </tr>
            @empty
                <x-table.empty :colspan="$showCompany ? 6 : 5" emoji="🎨">{{ __('No templates yet. Documents print with the standard design until you add one.') }}</x-table.empty>
            @endforelse
        </x-table>
    </x-card>
</div>
