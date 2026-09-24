<?php

namespace App\Livewire\Admin\Entries;

use App\Enums\AccountType;
use App\Enums\EntryType;
use App\Enums\PaymentType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Services\LedgerService;
use App\Support\Money;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

/** Records or edits income and expense (with partial payment), transfer and opening entries. */
class Form extends Component
{
    #[Locked]
    public ?int $entryId = null;

    #[Locked]
    public string $type = 'income';

    /** Whether "paid now" still mirrors the total, until the user changes it. */
    #[Locked]
    public bool $paidFollowsTotal = true;

    public string $companyId = '';

    public string $entryDate = '';

    public string $categoryAccountId = '';

    public string $partySearch = '';

    public string $partyId = '';

    public string $amount = '';

    public string $paidAmount = '';

    public string $paymentAccountId = '';

    public string $dueDate = '';

    public string $debitAccountId = '';

    public string $creditAccountId = '';

    public string $reference = '';

    public string $description = '';

    public bool $addingParty = false;

    public string $newPartyName = '';

    public string $newPartyPhone = '';

    public function mount(?JournalEntry $entry = null, string $type = 'income'): void
    {
        if ($entry?->exists) {
            Gate::authorize('entries.update');
            abort_unless(auth()->user()->canAccessCompany($entry->company_id), 404);
            abort_if($entry->isVoided(), 403, __('Voided entries cannot be edited.'));
            if ($entry->type->isSettlement()) {
                $this->redirectRoute('admin.entries.settlement.edit', ['entry' => $entry], navigate: true);

                return;
            }
            $this->fillFrom($entry);

            return;
        }
        Gate::authorize('entries.create');
        abort_unless(in_array(EntryType::tryFrom($type), EntryType::recordable(), true), 404);
        $this->type = $type;
        $companyIds = $this->selectableCompanies()->pluck('id')->all();
        $remembered = (int) session('ledger.company_id');
        $this->companyId = (string) (in_array($remembered, $companyIds, true) ? $remembered : ($companyIds[0] ?? ''));
        $this->entryDate = today()->toDateString();
        $this->paymentAccountId = $this->defaultPaymentMethod();
    }

    public function updatedCompanyId(): void
    {
        if ($this->entryId) {
            $this->companyId = (string) JournalEntry::findOrFail($this->entryId)->company_id;

            return;
        }
        $this->reset('categoryAccountId', 'partySearch', 'partyId', 'debitAccountId', 'creditAccountId', 'addingParty', 'newPartyName', 'newPartyPhone');
        $this->paymentAccountId = $this->defaultPaymentMethod();
    }

    public function updatedAmount(): void
    {
        if ($this->paidFollowsTotal) {
            $this->paidAmount = $this->amount;
        }
    }

    public function updatedPaidAmount(): void
    {
        $this->paidFollowsTotal = $this->paidAmount === $this->amount;
    }

    /** Quick "add party" for the selected company; creates a custom (non-employee) party. */
    public function addParty(): void
    {
        Gate::authorize('parties.create');
        $this->validate([
            'companyId' => ['required', Rule::in(Company::visibleTo(auth()->user())->where('is_active', true)->pluck('id')->all())],
            'newPartyName' => ['required', 'string', 'max:150'],
            'newPartyPhone' => ['nullable', 'string', 'max:40'],
        ], [], ['companyId' => __('company'), 'newPartyName' => __('party name'), 'newPartyPhone' => __('phone')]);
        $party = (new Party)->forceFill(['company_id' => (int) $this->companyId, 'name' => trim($this->newPartyName),
            'phone' => trim($this->newPartyPhone) ?: null, 'is_active' => true]);
        $party->save();
        $this->partyId = (string) $party->id;
        $this->reset('addingParty', 'newPartyName', 'newPartyPhone', 'partySearch');
    }

    public function save(bool $addAnother = false): Redirector|RedirectResponse|null
    {
        Gate::authorize($this->entryId ? 'entries.update' : 'entries.create');
        $user = auth()->user();
        $entry = $this->entryId ? JournalEntry::visibleTo($user)->findOrFail($this->entryId) : null;
        if ($entry) {
            $this->companyId = (string) $entry->company_id;
        }
        $type = EntryType::from($this->type);
        if ($type->isBill() && $this->paidFollowsTotal) {
            $this->paidAmount = $this->amount;
        }
        $money = fn (bool $allowZero): Closure => function (string $attribute, mixed $value, Closure $fail) use ($allowZero): void {
            if (! Money::isValidInput((string) $value) || (! $allowZero && Money::toPaisa((string) $value) === 0)) {
                $fail($allowZero ? __('Enter an amount in taka, for example 1,25,000.50.') : __('Enter an amount in taka greater than zero, for example 1,25,000.50.'));
            }
        };
        $rules = [
            'companyId' => ['required', Rule::in($this->selectableCompanies()->pluck('id')->all())],
            'entryDate' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'string', $money(false)],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ] + ($type->isBill() ? [
            'categoryAccountId' => ['required', 'integer'],
            'partyId' => ['nullable', 'integer'],
            'paidAmount' => ['required', 'string', $money(true)],
            'paymentAccountId' => ['nullable', 'integer'],
            'dueDate' => ['nullable', 'date_format:Y-m-d'],
        ] : [
            'debitAccountId' => ['required', 'integer'],
            'creditAccountId' => ['required', 'integer'],
        ]);
        $this->validate($rules, [], $this->attributeLabels());
        $data = ['entry_date' => $this->entryDate, 'amount' => Money::toPaisa($this->amount),
            'reference' => trim($this->reference), 'description' => trim($this->description)];
        if ($type->isBill()) {
            $paid = Money::toPaisa($this->paidAmount);
            $data += ['paid_amount' => $paid, 'category_account_id' => (int) $this->categoryAccountId,
                'payment_account_id' => $paid > 0 && $this->paymentAccountId !== '' ? (int) $this->paymentAccountId : null,
                'party_id' => $this->partyId !== '' ? (int) $this->partyId : null, 'due_date' => $this->dueDate !== '' ? $this->dueDate : null];
        } else {
            $data += ['debit_account_id' => (int) $this->debitAccountId, 'credit_account_id' => (int) $this->creditAccountId];
        }
        $ledger = app(LedgerService::class);
        try {
            $saved = $entry
                ? $ledger->update($entry, $data, $user)
                : $ledger->record(Company::findOrFail((int) $this->companyId), $type, $data, $user);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError(Str::camel($key), $messages[0]);
            }

            return null;
        }
        session(['ledger.company_id' => $saved->company_id]);
        $message = __('Entry :number saved.', ['number' => $saved->number]);
        if ($addAnother && ! $entry) {
            $this->reset('amount', 'paidAmount', 'partySearch', 'partyId', 'dueDate', 'reference', 'description', 'debitAccountId', 'creditAccountId');
            $this->paidFollowsTotal = true;
            session()->now('success', $message);
            $this->js('document.getElementById('.json_encode($type->isBill() ? 'categoryAccountId' : 'creditAccountId').')?.focus()');

            return null;
        }
        session()->flash('success', $message);

        return redirect()->route('admin.entries.index');
    }

    public function render(): View
    {
        $type = EntryType::from($this->type);
        $companyId = in_array((int) $this->companyId, auth()->user()->accessibleCompanyIds(), true) ? (int) $this->companyId : null;
        $accounts = $this->accountsOf($companyId, [$this->categoryAccountId, $this->paymentAccountId, $this->debitAccountId, $this->creditAccountId]);
        $options = fn (Collection $items, string $placeholder): array => ['' => $placeholder] + $items
            ->mapWithKeys(fn (Account $account): array => [$account->id => $account->name.($account->is_active ? '' : ' ('.__('inactive').')')])->all();
        $methods = $options($accounts->filter(fn (Account $account): bool => $account->isPaymentMethod()), __('Select a payment method'));
        $settled = $this->entryId && $type->isBill() ? (int) JournalEntry::query()->where('bill_id', $this->entryId)->posted()->sum('amount') : 0;
        $total = Money::isValidInput($this->amount) ? Money::toPaisa($this->amount) : 0;
        $paid = Money::isValidInput($this->paidAmount) ? Money::toPaisa($this->paidAmount) : $total;

        return view('livewire.admin.entries.form', [
            'isBill' => $type->isBill(),
            'companies' => $this->selectableCompanies()->orderBy('name')->get(['id', 'name', 'code'])
                ->mapWithKeys(fn (Company $company): array => [$company->id => $company->name.' ('.$company->code.')'])->all(),
            'categories' => $options($accounts->filter(fn (Account $account): bool => ! $account->is_system
                && $account->type === ($type === EntryType::Income ? AccountType::Income : AccountType::Expense)), __('Select a category')),
            'methods' => $methods,
            'parties' => $type->isBill() ? $this->partyOptions($companyId) : [],
            'showDue' => $type->isBill() && $paid < $total,
            'settled' => $settled,
            'title' => $this->entryId ? __('Edit :type entry', ['type' => Str::lower($type->label())]) : match ($type) {
                EntryType::Income => __('Record income'),
                EntryType::Expense => __('Record expense'),
                default => __('Record transfer'),
            },
        ])->layout('layouts.admin');
    }

    private function fillFrom(JournalEntry $entry): void
    {
        $entry->load('lines.account');
        $this->entryId = $entry->id;
        $this->type = $entry->type->value;
        $this->companyId = (string) $entry->company_id;
        $this->entryDate = $entry->entry_date->toDateString();
        $this->amount = Money::toInput($entry->amount);
        $this->reference = (string) $entry->reference;
        $this->description = (string) $entry->description;
        if ($entry->type->isBill()) {
            $paidLine = $entry->lines->first(fn ($line): bool => $line->account->isPaymentMethod());
            $this->categoryAccountId = (string) $entry->categoryAccount()?->id;
            $this->paymentAccountId = (string) ($paidLine?->account_id ?? $this->defaultPaymentMethod());
            $this->paidAmount = Money::toInput($paidLine ? $paidLine->debit + $paidLine->credit : 0);
            $this->paidFollowsTotal = $this->paidAmount === $this->amount;
            $this->partyId = (string) $entry->party_id;
            $this->dueDate = (string) $entry->due_date?->toDateString();
        } else {
            $this->debitAccountId = (string) $entry->debitAccount()?->id;
            $this->creditAccountId = (string) $entry->creditAccount()?->id;
        }
    }

    /** New entries go to active visible companies; an edited entry keeps its own company. */
    private function selectableCompanies(): Builder
    {
        return Company::visibleTo(auth()->user())->when($this->entryId === null, fn (Builder $query) => $query->where('is_active', true));
    }

    /**
     * Active accounts of the company plus any already chosen (so an edit still shows a since-deactivated account).
     *
     * @param  list<string>  $chosen
     * @return Collection<int, Account>
     */
    private function accountsOf(?int $companyId, array $chosen): Collection
    {
        if ($companyId === null) {
            return collect();
        }
        $keep = array_values(array_filter(array_map('intval', $chosen)));

        return Account::query()->where('company_id', $companyId)
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhereIn('id', $keep))->orderBy('code')->get();
    }

    /** @return array<int|string, string> active parties matching the search (at most 50), plus the chosen one */
    private function partyOptions(?int $companyId): array
    {
        if ($companyId === null) {
            return ['' => __('No party')];
        }
        $search = mb_substr(trim($this->partySearch), 0, 100);
        $parties = Party::query()->where('company_id', $companyId)->where('is_active', true)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')))
            ->orderBy('name')->limit(50)->get(['id', 'name', 'phone', 'employee_id']);
        if ($this->partyId !== '' && ! $parties->contains('id', (int) $this->partyId)) {
            $parties->prepend(Party::query()->where('company_id', $companyId)->find((int) $this->partyId, ['id', 'name', 'phone', 'employee_id']));
        }

        return ['' => __('No party')] + $parties->filter()->mapWithKeys(fn (Party $party): array => [
            $party->id => $party->name.($party->phone ? ' · '.$party->phone : '').($party->employee_id ? ' · '.__('employee') : ''),
        ])->all();
    }

    /** The company's first active Cash payment method, else its first active payment method. */
    private function defaultPaymentMethod(): string
    {
        if (! auth()->user()->canAccessCompany((int) $this->companyId)) {
            return '';
        }

        return (string) Account::query()->where('company_id', (int) $this->companyId)->paymentMethods()->where('is_active', true)
            ->orderByRaw('CASE WHEN payment_type = ? THEN 0 ELSE 1 END', [PaymentType::Cash->value])->orderBy('code')->value('id');
    }

    /** @return array<string, string> */
    private function attributeLabels(): array
    {
        $transfer = $this->type === EntryType::Transfer->value;

        return ['companyId' => __('company'), 'entryDate' => __('date'), 'amount' => $this->type === 'income' || $this->type === 'expense' ? __('total amount') : __('amount'),
            'categoryAccountId' => __('category'), 'partyId' => __('party'), 'paidAmount' => __('paid now'), 'paymentAccountId' => __('payment method'),
            'dueDate' => __('due date'), 'debitAccountId' => $transfer ? __('to') : __('payment method'), 'creditAccountId' => __('from'),
            'reference' => __('reference'), 'description' => __('description')];
    }
}
