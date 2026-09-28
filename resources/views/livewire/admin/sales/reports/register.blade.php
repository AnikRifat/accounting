<div class="page">
    <x-page-header :title="__(':type register', ['type' => $documentType->label()])" :description="$scopeLabel.($periodLabel ? ' · '.$periodLabel : '')" :back="route('admin.sales.reports.index')" :back-label="__('Sales reports')">
        <x-slot:actions><x-button variant="secondary" icon="printer" class="no-print" onclick="window.print()">{{ __('Print') }}</x-button></x-slot:actions>
    </x-page-header>
    <x-card flush>
        <x-slot:toolbar>
            <x-toolbar>
                <x-form.select name="type" :label="__('Documents')" wire:model.live="type" :options="$typeOptions" />
                <x-form.select name="period" :label="__('Period')" wire:model.live="period" :options="$periodOptions" />
                <x-form.date-range :label="__('Dates')" />
                <x-slot:actions><x-table.export :columns="$this->tableColumns()" /></x-slot:actions>
            </x-toolbar>
        </x-slot:toolbar>
        @if($documents->isEmpty())
            <x-empty-state emoji="📒" :title="__('Nothing to show')" :description="__('No :type were issued in this period.', ['type' => mb_strtolower($documentType->pluralLabel())])" />
        @else
            <x-table :caption="__(':type register', ['type' => $documentType->label()])">
                <x-slot:head><th scope="col">{{ __('Number') }}</th><th scope="col">{{ __('Date') }}</th><th scope="col">{{ $documentType->isPurchase() ? __('Supplier') : __('Customer') }}</th>@if($showCompany)<th scope="col">{{ __('Company') }}</th>@endif
                    <th scope="col" class="num">{{ __('Subtotal') }}</th><th scope="col" class="num">{{ __('Discount') }}</th><th scope="col" class="num">{{ __('VAT') }}</th><th scope="col" class="num">{{ __('Total') }}</th>
                    <th scope="col" class="num">{{ __('Paid') }}</th><th scope="col" class="num">{{ __('Balance') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Posted') }}</th></x-slot:head>
                @foreach($documents as $document)
                    <tr wire:key="register-{{ $document->id }}" @class(['is-voided' => $document->isVoid()])>
                        <td class="nowrap"><a class="text-link" href="{{ route('admin.sales.documents.show', $document) }}" wire:navigate>{{ $document->displayNumber() }}</a></td>
                        <td class="nowrap">{{ $document->issue_date->format('d M Y') }}</td>
                        <td>{{ $document->party?->name ?? '—' }}</td>
                        @if($showCompany)<td>{{ $document->company->name }}</td>@endif
                        <td class="num"><x-money :value="$document->subtotal" /></td>
                        <td class="num"><x-money :value="$document->discount_total" /></td>
                        <td class="num"><x-money :value="$document->tax_total" /></td>
                        <td class="num"><x-money :value="$document->total" /></td>
                        <td class="num">@if(($paid = $this->paid($document)) !== null)<x-money :value="$paid" />@else—@endif</td>
                        <td class="num">@if($document->balance() !== null)<x-money :value="$document->balance()" />@else—@endif</td>
                        <td>@if($document->isVoid())<x-badge tone="danger" :title="$document->void_reason">{{ __('Void') }}</x-badge>@elseif($document->dueStatus())<x-badge.due-status :status="$document->dueStatus()" />@else<x-badge :tone="$document->status->tone()">{{ $document->status->label() }}</x-badge>@endif</td>
                        <td>{{ $document->isPosted() ? __('Yes') : __('No') }}</td>
                    </tr>
                @endforeach
                <x-slot:foot><tr class="grand-total-row"><th scope="row" colspan="{{ $showCompany ? 4 : 3 }}">{{ __('Total') }} <span class="muted normal-case tracking-normal">{{ __('(void documents excluded)') }}</span></th>
                    <td class="num"><x-money :value="$totals['subtotal']" /></td><td class="num"><x-money :value="$totals['discount']" /></td><td class="num"><x-money :value="$totals['tax']" /></td>
                    <td class="num"><x-money :value="$totals['total']" /></td><td class="num"><x-money :value="$totals['paid']" /></td><td class="num"><x-money :value="$totals['balance']" /></td><td colspan="2"></td></tr></x-slot:foot>
            </x-table>
        @endif
    </x-card>
</div>
