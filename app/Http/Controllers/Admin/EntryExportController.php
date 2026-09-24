<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EntryType;
use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Streams the filtered, company-scoped transaction list as CSV (UTF-8 with BOM for Excel). */
class EntryExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        Gate::authorize('entries.view');
        $query = JournalEntry::visibleTo($request->user())
            ->filter($request->only(['company', 'from', 'to', 'type', 'account', 'party', 'status', 'search']))
            ->withOutstanding()
            ->with(['company:id,name,code', 'lines.account:id,code,name,type,is_cash,is_system', 'party:id,name', 'bill:id,number', 'creator:id,name'])
            ->orderBy('entry_date')->orderBy('id');

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\u{FEFF}");
            fputcsv($output, [__('Number'), __('Date'), __('Company'), __('Type'), __('Party'), __('Category'), __('Payment method'),
                __('Total (BDT)'), __('Paid (BDT)'), __('Due (BDT)'), __('Due date'), __('Due status'), __('Bill number'),
                __('Description'), __('Reference'), __('Status'), __('Void reason'), __('Created by')], escape: '');
            foreach ($query->lazy(500) as $entry) {
                $status = $entry->dueStatus();
                $method = match (true) {
                    $entry->type === EntryType::Transfer => $entry->creditAccount()?->label().' → '.$entry->debitAccount()?->label(),
                    default => $entry->paymentAccount()?->label(),
                };
                $paid = match (true) {
                    $status !== null => Money::toInput($entry->paidAmount()),
                    $entry->type->isSettlement() && ! $entry->isVoided() => Money::toInput($entry->amount),
                    default => '',
                };
                fputcsv($output, array_map($this->cell(...), [
                    $entry->number, $entry->entry_date->toDateString(), $entry->company->name, $entry->type->label(),
                    $entry->party?->name, $entry->categoryAccount()?->label(), $method, Money::toInput($entry->amount), $paid,
                    $status !== null ? Money::toInput($entry->outstanding) : '', $entry->due_date?->toDateString(), $status?->label(),
                    $entry->bill?->number, $entry->description, $entry->reference,
                    $entry->isVoided() ? __('Voided') : __('Posted'), $entry->void_reason, $entry->creator?->name,
                ]), escape: '');
            }
            fclose($output);
        }, 'transactions-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralises spreadsheet formula injection in user-entered text. */
    private function cell(?string $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
