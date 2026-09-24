<?php

namespace App\Livewire\Admin\Entries;

use App\Enums\DueStatus;
use App\Enums\EntryType;
use App\Livewire\Concerns\WithFormSheet;
use App\Livewire\Concerns\WithTableTools;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use App\Support\TableExport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithFormSheet, WithPagination, WithTableTools;

    private const FILTERS = ['from', 'to', 'type', 'account', 'party', 'payer', 'status', 'search'];

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $account = '';

    #[Url(except: '')]
    public string $party = '';

    /** User who paid or received the money (journal_entries.paid_by). */
    #[Url(except: '')]
    public string $payer = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $search = '';

    public ?int $voidingId = null;

    public string $voidReason = '';

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->resetPage();
        }
    }

    /** @return array<string, string> the active filters, also used as export query parameters */
    public function filters(): array
    {
        return array_filter(['from' => $this->from, 'to' => $this->to, 'type' => $this->type,
            'account' => $this->account, 'party' => $this->party, 'payer' => $this->payer, 'status' => $this->status, 'search' => mb_substr($this->search, 0, 100)], fn (string $value): bool => $value !== '');
    }

    public function clearFilters(): void
    {
        $this->reset(self::FILTERS);
        $this->resetPage();
    }

    public function confirmVoid(int $entryId): void
    {
        Gate::authorize('entries.void');
        $this->voidingId = JournalEntry::visibleTo(auth()->user())->posted()->findOrFail($entryId)->id;
        $this->voidReason = '';
        $this->resetErrorBag();
    }

    public function cancelVoid(): void
    {
        $this->reset('voidingId', 'voidReason');
        $this->resetErrorBag();
    }

    public function void(): void
    {
        Gate::authorize('entries.void');
        $this->validate(['voidReason' => ['required', 'string', 'max:500']], [], ['voidReason' => __('reason')]);
        $entry = JournalEntry::visibleTo(auth()->user())->findOrFail($this->voidingId);
        try {
            app(LedgerService::class)->void($entry, $this->voidReason, auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('voidReason', collect($exception->errors())->flatten()->first());

            return;
        }
        $this->reset('voidingId', 'voidReason');
        session()->now('success', __('Entry :number voided.', ['number' => $entry->number]));
    }

    /** Moves an entry to the Trash; a refusal (e.g. a bill with receipts or payments) lands in the `delete` error. */
    public function delete(int $entryId): void
    {
        Gate::authorize('entries.delete');
        $this->resetErrorBag('delete');
        $entry = JournalEntry::visibleTo(auth()->user())->whereIn('company_id', app(CompanyContext::class)->companyIds())->findOrFail($entryId);
        try {
            app(LedgerService::class)->delete($entry, auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('delete', collect($exception->errors())->flatten()->first());

            return;
        }
        session()->now('success', __('Entry :number moved to trash.', ['number' => $entry->number]));
    }

    public function render(): View
    {
        Gate::authorize('entries.view');
        $user = auth()->user();
        $context = app(CompanyContext::class);
        $companyIds = $context->companyIds();
        $codes = $context->isAll() ? Company::query()->whereKey($companyIds)->pluck('code', 'id') : collect();
        $suffix = fn (int $companyId): string => $codes->has($companyId) ? ' ('.$codes[$companyId].')' : '';
        $query = $this->filteredEntries();
        $totals = (clone $query)->posted()->whereIn('type', [EntryType::Income, EntryType::Expense])
            ->toBase()->groupBy('type')->selectRaw('type, SUM(amount) as total')->pluck('total', 'type');

        return view('livewire.admin.entries.index', [
            'entries' => $this->tableQuery()->paginate(25),
            'income' => (int) ($totals[EntryType::Income->value] ?? 0),
            'expense' => (int) ($totals[EntryType::Expense->value] ?? 0),
            'types' => ['' => __('All types')] + collect(EntryType::cases())->mapWithKeys(fn (EntryType $type): array => [$type->value => $type->label()])->all(),
            'accounts' => ['' => __('All accounts')] + Account::query()->whereIn('company_id', $companyIds)->orderBy('code')->orderBy('company_id')
                ->get(['id', 'code', 'name', 'company_id'])->mapWithKeys(fn (Account $account): array => [$account->id => $account->label().$suffix($account->company_id)])->all(),
            'parties' => ['' => __('All parties')] + Party::query()->whereIn('company_id', $companyIds)->orderBy('name')
                ->get(['id', 'name', 'company_id'])->mapWithKeys(fn (Party $party): array => [$party->id => $party->name.$suffix($party->company_id)])->all(),
            // Only people who actually handled money in the visible scope, so the list never exposes other users.
            'payers' => ['' => __('Anyone')] + User::query()->whereIn('id', JournalEntry::visibleTo($user)->whereIn('company_id', $companyIds)
                ->whereNotNull('paid_by')->select('paid_by'))->orderBy('name')->pluck('name', 'id')->all(),
            'statuses' => ['' => __('Any status')] + collect(DueStatus::cases())->mapWithKeys(fn (DueStatus $status): array => [$status->value => $status->label()])->all(),
            'showCompany' => $context->isAll(),
        ])->layout('layouts.admin');
    }

    protected function sheetRoute(): string
    {
        return 'admin.entries.index';
    }

    /** @return list<string> */
    protected function sheetsNeedingCompany(): array
    {
        return ['create'];
    }

    /** @return Builder<JournalEntry> the visible entries of the header companies, with the list filters */
    private function filteredEntries(): Builder
    {
        return JournalEntry::visibleTo(auth()->user())->whereIn('company_id', app(CompanyContext::class)->companyIds())->filter($this->filters());
    }

    /** @return Builder<JournalEntry> the list's rows, in the list's order */
    protected function tableQuery(): Builder
    {
        return $this->filteredEntries()->withOutstanding()->with(['company:id,name,code', 'lines.account:id,code,name,type,is_cash,is_system', 'party:id,name', 'bill:id,number', 'payer:id,name'])
            ->orderByDesc('entry_date')->orderByDesc('id');
    }

    protected function tableExport(): TableExport
    {
        $details = fn (JournalEntry $entry): ?string => match (true) {
            $entry->type->isBill() => $entry->categoryAccount()?->name,
            $entry->type->isSettlement() => __('For :number', ['number' => $entry->bill?->number]).' · '.$entry->paymentAccount()?->name,
            default => $entry->creditAccount()?->name.' → '.$entry->debitAccount()?->name,
        };
        $due = fn (JournalEntry $entry): bool => ! $entry->isVoided() && $entry->dueStatus() !== null;

        return new TableExport(__('Transactions'), [
            'number' => ['label' => __('Number'), 'value' => fn (JournalEntry $entry): string => $entry->number],
            'date' => ['label' => __('Date'), 'value' => fn (JournalEntry $entry) => $entry->entry_date, 'type' => 'date'],
            'company' => ['label' => __('Company'), 'value' => fn (JournalEntry $entry): string => $entry->company->name],
            'type' => ['label' => __('Type'), 'value' => fn (JournalEntry $entry): string => $entry->type->label()],
            'party' => ['label' => __('Party'), 'value' => fn (JournalEntry $entry): ?string => $entry->party?->name],
            'details' => ['label' => __('Details'), 'value' => $details],
            'description' => ['label' => __('Description'), 'value' => fn (JournalEntry $entry): ?string => $entry->description],
            'reference' => ['label' => __('Reference'), 'value' => fn (JournalEntry $entry): ?string => $entry->reference],
            'total' => ['label' => __('Total'), 'value' => fn (JournalEntry $entry): int => $entry->amount, 'type' => 'money'],
            'paid' => ['label' => __('Paid'), 'value' => fn (JournalEntry $entry): ?int => $due($entry) ? $entry->paidAmount() : null, 'type' => 'money'],
            'due' => ['label' => __('Due'), 'value' => fn (JournalEntry $entry): ?int => $due($entry) && $entry->outstanding > 0 ? (int) $entry->outstanding : null, 'type' => 'money'],
            'dueDate' => ['label' => __('Due date'), 'value' => fn (JournalEntry $entry) => $due($entry) && $entry->outstanding > 0 ? $entry->due_date : null, 'type' => 'date'],
            'payer' => ['label' => __('Paid / received by'), 'value' => fn (JournalEntry $entry): ?string => $entry->payer?->name],
            'status' => ['label' => __('Status'), 'value' => fn (JournalEntry $entry): ?string => $entry->isVoided() ? __('Voided') : $entry->dueStatus()?->label()],
        ], $this->companyScopeLabel());
    }
}
