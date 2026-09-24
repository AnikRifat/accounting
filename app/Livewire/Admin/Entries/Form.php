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
use App\Support\CompanyContext;
use App\Support\Money;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    /** The entry's company: from the header context when creating (re-checked on every write), from the record when editing. */
    #[Locked]
    public ?int $companyId = null;

    public string $entryDate = '';

    public string $categoryAccountId = '';

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

    public bool $addingCategory = false;

    public string $newCategoryName = '';

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
        $company = app(CompanyContext::class)->company();
        abort_unless($company?->is_active, 404);
        $this->companyId = $company->id;
        $this->entryDate = today()->toDateString();
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
        $company = $this->writableCompany('newPartyName');
        if ($company === null) {
            return;
        }
        $this->validate([
            'newPartyName' => ['required', 'string', 'max:150'],
            'newPartyPhone' => ['nullable', 'string', 'max:40'],
        ], [], ['newPartyName' => __('party name'), 'newPartyPhone' => __('phone')]);
        $party = (new Party)->forceFill(['company_id' => $company->id, 'name' => trim($this->newPartyName),
            'phone' => trim($this->newPartyPhone) ?: null, 'is_active' => true]);
        $party->save();
        $this->partyId = (string) $party->id;
        $this->reset('addingParty', 'newPartyName', 'newPartyPhone');
    }

    /** Quick "add category" of the entry's side (income or expense) for the selected company. */
    public function addCategory(): void
    {
        Gate::authorize('accounts.manage');
        $type = EntryType::from($this->type);
        abort_unless($type->isBill(), 404);
        // Resolve the company first, so the unique rule below never runs against a company the user cannot use.
        $company = $this->writableCompany('newCategoryName');
        if ($company === null) {
            return;
        }
        $companyId = $company->id;
        $this->newCategoryName = trim($this->newCategoryName);
        $this->validate([
            'newCategoryName' => ['required', 'string', 'max:150', Rule::unique('accounts', 'name')->where('company_id', $companyId)],
        ], [], ['newCategoryName' => __('category name')]);
        $accountType = $type === EntryType::Income ? AccountType::Income : AccountType::Expense;
        try {
            $account = DB::transaction(function () use ($companyId, $accountType): Account {
                $company = Company::findOrFail($companyId);
                $account = $company->accounts()->make(['name' => $this->newCategoryName, 'type' => $accountType, 'is_cash' => false, 'is_active' => true]);
                $account->code = app(LedgerService::class)->nextCode($company, $accountType);
                $account->save();

                return $account;
            });
        } catch (ValidationException $exception) {
            // The only posting error here is a full code range.
            $this->addError('newCategoryName', collect($exception->errors())->flatten()->first());

            return;
        }
        $this->categoryAccountId = (string) $account->id;
        $this->reset('addingCategory', 'newCategoryName');
    }

    public function save(bool $addAnother = false): Redirector|RedirectResponse|null
    {
        Gate::authorize($this->entryId ? 'entries.update' : 'entries.create');
        $user = auth()->user();
        $entry = $this->entryId ? JournalEntry::visibleTo($user)->findOrFail($this->entryId) : null;
        $company = $entry ? null : $this->writableCompany('entry');
        if (! $entry && $company === null) {
            return null;
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
                : $ledger->record($company, $type, $data, $user);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError(Str::camel($key), $messages[0]);
            }

            return null;
        }
        $message = __('Entry :number saved.', ['number' => $saved->number]);
        if ($addAnother && ! $entry) {
            $this->reset('amount', 'paidAmount', 'partyId', 'dueDate', 'reference', 'description', 'debitAccountId', 'creditAccountId');
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
        $companyId = $this->companyId !== null && auth()->user()->canAccessCompany($this->companyId) ? $this->companyId : null;
        $accounts = $this->accountsOf($companyId, [$this->categoryAccountId, $this->paymentAccountId, $this->debitAccountId, $this->creditAccountId]);
        $options = fn (Collection $items, string $placeholder): array => ['' => $placeholder] + $items
            ->mapWithKeys(fn (Account $account): array => [$account->id => $account->name.($account->is_active ? '' : ' ('.__('inactive').')')])->all();
        $methods = $options($accounts->filter(fn (Account $account): bool => $account->isPaymentMethod()), __('Select a payment method'));
        $settled = $this->entryId && $type->isBill() ? (int) JournalEntry::query()->where('bill_id', $this->entryId)->posted()->sum('amount') : 0;
        $total = Money::isValidInput($this->amount) ? Money::toPaisa($this->amount) : 0;
        $paid = Money::isValidInput($this->paidAmount) ? Money::toPaisa($this->paidAmount) : $total;
        $company = $companyId ? Company::query()->find($companyId, ['id', 'name', 'is_active']) : null;

        return view('livewire.admin.entries.form', [
            'isBill' => $type->isBill(),
            'categories' => $options($accounts->filter(fn (Account $account): bool => ! $account->is_system
                && $account->type === ($type === EntryType::Income ? AccountType::Income : AccountType::Expense)), __('Select a category')),
            'methods' => $methods,
            'parties' => $type->isBill() ? $this->partyOptions($companyId) : [],
            'showDue' => $type->isBill() && $paid < $total,
            'settled' => $settled,
            'companyName' => $company?->name,
            'canAdd' => (bool) $company?->is_active,
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
        $this->companyId = $entry->company_id;
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

    /**
     * The active, visible company that new data from this form goes to: the edited entry's company, or
     * the header's company when creating. Adds an error under $errorKey and returns null when the header
     * changed in another tab, or the company is no longer visible or active.
     */
    private function writableCompany(string $errorKey): ?Company
    {
        $company = Company::visibleTo(auth()->user())->find($this->companyId);
        if (! $this->entryId && app(CompanyContext::class)->selectedId() !== $this->companyId) {
            $this->addError($errorKey, __('The company in the header has changed since this page opened. Reload the page to continue.'));

            return null;
        }
        if (! $company?->is_active) {
            $this->addError($errorKey, __('This company is inactive or no longer available and does not accept new entries.'));

            return null;
        }

        return $company;
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

    /** @return array<int|string, string> active parties, plus the chosen one */
    private function partyOptions(?int $companyId): array
    {
        if ($companyId === null) {
            return ['' => __('No party')];
        }
        $parties = Party::query()->where('company_id', $companyId)->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'phone', 'user_id']);
        if ($this->partyId !== '' && ! $parties->contains('id', (int) $this->partyId)) {
            $parties->prepend(Party::query()->where('company_id', $companyId)->find((int) $this->partyId, ['id', 'name', 'phone', 'user_id']));
        }

        return ['' => __('No party')] + $parties->filter()->mapWithKeys(fn (Party $party): array => [
            $party->id => $party->name.($party->phone ? ' · '.$party->phone : '').($party->user_id ? ' · '.__('employee') : ''),
        ])->all();
    }

    /** The company's first active Cash payment method, else its first active payment method. */
    private function defaultPaymentMethod(): string
    {
        if ($this->companyId === null || ! auth()->user()->canAccessCompany($this->companyId)) {
            return '';
        }

        return (string) Account::query()->where('company_id', $this->companyId)->paymentMethods()->where('is_active', true)
            ->orderByRaw('CASE WHEN payment_type = ? THEN 0 ELSE 1 END', [PaymentType::Cash->value])->orderBy('code')->value('id');
    }

    /** @return array<string, string> */
    private function attributeLabels(): array
    {
        $transfer = $this->type === EntryType::Transfer->value;

        return ['entryDate' => __('date'), 'amount' => $this->type === 'income' || $this->type === 'expense' ? __('total amount') : __('amount'),
            'categoryAccountId' => __('category'), 'partyId' => __('party'), 'paidAmount' => __('paid now'), 'paymentAccountId' => __('payment method'),
            'dueDate' => __('due date'), 'debitAccountId' => $transfer ? __('to') : __('payment method'), 'creditAccountId' => __('from'),
            'reference' => __('reference'), 'description' => __('description')];
    }
}
