<?php

namespace App\Livewire\Admin\Sales;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\DueStatus;
use App\Enums\EntryType;
use App\Models\Document;
use App\Models\JournalEntry;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** Sales at a glance for the header company scope: this month's invoicing and receipts, what is owed, and what needs attention. */
class Dashboard extends Component
{
    public function render(): View
    {
        Gate::authorize('sales.view');
        $context = app(CompanyContext::class);
        $companyIds = $context->companyIds();
        $documents = fn (): Builder => Document::query()->visibleTo(auth()->user())->whereIn('documents.company_id', $companyIds);
        $issued = fn (DocumentType $type): Builder => $documents()->where('documents.type', $type)
            ->whereNotIn('documents.status', [DocumentStatus::Draft, DocumentStatus::Void]);
        [$monthStart, $monthEnd] = [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()];
        $inMonth = fn (Builder $query): Builder => $query->whereBetween('documents.issue_date', [$monthStart, $monthEnd]);

        $invoiced = (int) $inMonth($issued(DocumentType::Invoice))->sum('documents.total') - (int) $inMonth($issued(DocumentType::CreditNote))->sum('documents.total');
        $invoiceIds = $issued(DocumentType::Invoice)->select('documents.id');
        $receivedOnDocuments = (int) DB::table('document_payments')->whereIn('document_id', $invoiceIds)
            ->whereBetween('paid_on', [$monthStart, $monthEnd])->sum('amount');
        $receivedInLedger = (int) JournalEntry::query()->posted()->where('type', EntryType::Receipt)
            ->whereIn('bill_id', $issued(DocumentType::Invoice)->whereNotNull('documents.journal_entry_id')->select('documents.journal_entry_id'))
            ->whereBetween('entry_date', [$monthStart, $monthEnd])->sum('amount');
        $outstanding = fn (DocumentType $type): int => (int) DB::query()->fromSub($issued($type)->withBalance(), 'open_documents')->where('balance', '>', 0)->sum('balance');

        return view('livewire.admin.sales.dashboard', [
            'scopeLabel' => $context->isAll() ? __('All companies') : (string) $context->company()?->name,
            'canCreate' => ! $context->isAll() && (bool) $context->company()?->is_active && auth()->user()->can('sales.create'),
            'invoiced' => $invoiced,
            'received' => $receivedOnDocuments + $receivedInLedger,
            'receivable' => $outstanding(DocumentType::Invoice),
            'payable' => $outstanding(DocumentType::Bill),
            'overdueCount' => $documents()->where('documents.type', DocumentType::Invoice)->dueStatus(DueStatus::Overdue)->count(),
            'recent' => $documents()->with(['party:id,name', 'company:id,name'])->orderByDesc('documents.id')->limit(10)->get(),
            'overdue' => $documents()->where('documents.type', DocumentType::Invoice)->dueStatus(DueStatus::Overdue)->withBalance()
                ->with('party:id,name')->orderBy('documents.due_date')->orderBy('documents.id')->limit(5)->get(),
            'awaiting' => $documents()->whereIn('documents.type', [DocumentType::Quotation, DocumentType::Estimate, DocumentType::Proforma])
                ->where('documents.status', DocumentStatus::Issued)->with('party:id,name')->orderBy('documents.issue_date')->orderBy('documents.id')->limit(10)->get(),
            'showCompany' => $context->isAll(),
        ])->layout('layouts.admin');
    }
}
