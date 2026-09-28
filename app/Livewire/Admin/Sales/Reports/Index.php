<?php

namespace App\Livewire\Admin\Sales\Reports;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** The Sales reports hub (`sales.reports`). */
class Index extends Component
{
    public function render(): View
    {
        Gate::authorize('sales.reports');

        return view('livewire.admin.sales.reports.index', [
            'reports' => [
                'register' => ['📒', __('Sales register'), __('Every issued invoice, or any other document type, in a period with VAT, paid and balance.')],
                'ageing' => ['⏳', __('Receivables ageing'), __('Unpaid invoices or bills by customer or supplier, bucketed by days overdue.')],
                'vat' => ['🧾', __('VAT report'), __('Output VAT by month and rate, net of credit notes, checked against the VAT Payable account.')],
                'breakdown' => ['📊', __('Sales by customer and item'), __('Net sales and VAT per customer and per item, or purchases per supplier.')],
            ],
        ])->layout('layouts.admin');
    }
}
