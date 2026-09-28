<div class="page">
    <x-notices />
    <x-page-header :title="__('Sales')" :description="$scopeLabel.' · '.__('Issued documents only; drafts and void documents never count.')" />

    @if($canCreate)
        <div class="quick-actions">
            @foreach([
                [\App\Enums\DocumentType::Invoice, '🧾', __('New invoice'), __('Bill a customer for work or goods'), 'success'],
                [\App\Enums\DocumentType::Quotation, '💬', __('New quotation'), __('Offer a price before the work starts'), null],
            ] as [$newType, $emoji, $label, $hint, $tone])
                <a @class(['card quick-action', 'quick-action-'.$tone => $tone]) href="{{ route('admin.sales.'.$newType->slug().'.create') }}" wire:navigate wire:key="quick-{{ $newType->value }}"><span class="quick-action-emoji" aria-hidden="true">{{ $emoji }}</span><span><strong>{{ $label }}</strong><span class="muted">{{ $hint }}</span></span></a>
            @endforeach
        </div>
    @endif

    <div class="stats">
        <x-stat :label="__('Invoiced this month')" emoji="🧾" tone="info" :hint="__('Invoices less credit notes')" :href="route('admin.sales.invoices.index', ['from' => today()->startOfMonth()->toDateString(), 'to' => today()->endOfMonth()->toDateString()])"><x-slot:value><x-money :value="$invoiced" /></x-slot:value></x-stat>
        <x-stat :label="__('Received this month')" emoji="💰" tone="success"><x-slot:value><x-money :value="$received" /></x-slot:value></x-stat>
        <x-stat :label="__('Still to receive')" emoji="⏳" tone="warning" :hint="trans_choice(':count overdue invoice|:count overdue invoices', $overdueCount, ['count' => $overdueCount])" :href="route('admin.sales.invoices.index', ['status' => 'due:overdue'])"><x-slot:value><x-money :value="$receivable" /></x-slot:value></x-stat>
        <x-stat :label="__('Still to pay')" emoji="📥" tone="danger" :hint="__('Open bills')" :href="route('admin.sales.bills.index')"><x-slot:value><x-money :value="$payable" /></x-slot:value></x-stat>
    </div>

    <div class="grid-2">
        <x-card :title="__('Overdue invoices')" flush>
            <x-table :caption="__('Overdue invoices')">
                <x-slot:head><th>{{ __('Invoice') }}</th><th>{{ __('Customer') }}</th><th class="num">{{ __('Balance') }}</th></x-slot:head>
                @forelse($overdue as $document)
                    <tr wire:key="overdue-{{ $document->id }}">
                        <td class="nowrap"><a class="text-link" href="{{ route('admin.sales.documents.show', $document) }}" wire:navigate>{{ $document->number }}</a><p class="muted">{{ __('Due :date', ['date' => $document->due_date->format('d M Y')]) }}</p></td>
                        <td>{{ $document->party?->name ?? '—' }}</td>
                        <td class="num"><x-money :value="$document->balance()" /></td>
                    </tr>
                @empty
                    <x-table.empty colspan="3" emoji="🎉">{{ __('Nothing overdue.') }}</x-table.empty>
                @endforelse
            </x-table>
        </x-card>

        <x-card :title="__('Quotations awaiting a response')" flush>
            <x-table :caption="__('Quotations awaiting a response')">
                <x-slot:head><th>{{ __('Number') }}</th><th>{{ __('Customer') }}</th><th class="num">{{ __('Total') }}</th></x-slot:head>
                @forelse($awaiting as $document)
                    <tr wire:key="awaiting-{{ $document->id }}">
                        <td class="nowrap"><a class="text-link" href="{{ route('admin.sales.documents.show', $document) }}" wire:navigate>{{ $document->number }}</a><p class="muted">{{ $document->type->label() }} · {{ $document->issue_date->format('d M Y') }}</p></td>
                        <td>{{ $document->party?->name ?? '—' }}@if($document->due_date)<p class="muted">{{ __('Valid until :date', ['date' => $document->due_date->format('d M Y')]) }}</p>@endif</td>
                        <td class="num"><x-money :value="$document->total" /></td>
                    </tr>
                @empty
                    <x-table.empty colspan="3" emoji="💬">{{ __('No open quotations.') }}</x-table.empty>
                @endforelse
            </x-table>
        </x-card>

        <x-card :title="__('Recent documents')" flush class="span-full">
            <x-table :caption="__('Recent documents')">
                <x-slot:head><th>{{ __('Document') }}</th><th>{{ __('Party') }}</th>@if($showCompany)<th>{{ __('Company') }}</th>@endif<th>{{ __('Status') }}</th><th class="num">{{ __('Total') }}</th></x-slot:head>
                @forelse($recent as $document)
                    <tr wire:key="recent-{{ $document->id }}" @class(['is-voided' => $document->isVoid()])>
                        <td class="nowrap"><a class="text-link" href="{{ route('admin.sales.documents.show', $document) }}" wire:navigate>{{ $document->type->label() }} {{ $document->number ?? __('Draft') }}</a><p class="muted">{{ $document->issue_date->format('d M Y') }}</p></td>
                        <td>{{ $document->party?->name ?? '—' }}</td>
                        @if($showCompany)<td>{{ $document->company->name }}</td>@endif
                        <td><x-badge :tone="$document->status->tone()">{{ $document->status->label() }}</x-badge></td>
                        <td class="num"><x-money :value="$document->total" /></td>
                    </tr>
                @empty
                    <x-table.empty :colspan="$showCompany ? 5 : 4" emoji="🧾">{{ __('No documents yet.') }}</x-table.empty>
                @endforelse
            </x-table>
        </x-card>
    </div>
</div>
