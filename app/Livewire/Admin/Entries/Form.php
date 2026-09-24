<?php

namespace App\Livewire\Admin\Entries;

use App\Enums\AccountType;
use App\Enums\EntryType;
use App\Enums\PaymentType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Media;
use App\Models\Party;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\MediaService;
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
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportRedirects\Redirector;
use Livewire\WithFileUploads;

/** Records or edits income and expense (with partial payment), transfer and opening entries. */
class Form extends Component
{
    use WithFileUploads;

    /** File types accepted as a voucher, invoice or receipt. */
    private const REFERENCE_FILE_TYPES = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    #[Locked]
    public ?int $entryId = null;

    #[Locked]
    public string $type = 'income';

    /** Whether the only payment row's amount still mirrors the total, until the user changes it or adds a row. */
    #[Locked]
    public bool $paidFollowsTotal = true;

    /** The entry's company: from the header context when creating (re-checked on every write), from the record when editing. */
    #[Locked]
    public ?int $companyId = null;

    public string $entryDate = '';

    public string $categoryAccountId = '';

    public string $partyId = '';

    public string $amount = '';

    /**
     * The paid-now part of an income or expense, one row per payment method. Rows left blank or at zero are skipped on save.
     *
     * @var list<array{account: string, amount: string}>
     */
    public array $payments = [];

    public string $dueDate = '';

    public string $debitAccountId = '';

    public string $creditAccountId = '';

    public string $reference = '';

    public ?TemporaryUploadedFile $referenceFile = null;

    public bool $removeReferenceFile = false;

    /** Who paid or received the money. Only the super admin can change it; LedgerService enforces that on save. */
    public string $paidBy = '';

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
        $this->payments = [['account' => $this->defaultPaymentMethod(), 'amount' => '']];
        $this->paidBy = (string) auth()->id();
    }

    public function updatedAmount(): void
    {
        if ($this->paidFollowsTotal && count($this->payments) === 1) {
            $this->payments[0]['amount'] = $this->amount;
        }
    }

    public function updatedPayments(): void
    {
        $this->paidFollowsTotal = $this->followsTotal();
    }

    /** Adds a payment row with an unused active method, prefilled with what is still unpaid. */
    public function addPayment(): void
    {
        if (count($this->payments) >= LedgerService::MAX_PAYMENTS) {
            return;
        }
        $used = array_column($this->payments, 'account');
        $method = $this->accountsOf($this->visibleCompanyId(), [])->first(fn (Account $account): bool => $account->isPaymentMethod()
            && ! in_array((string) $account->id, $used, true));
        $unpaid = $this->paisa($this->amount) - $this->paidNow();
        $this->payments[] = ['account' => (string) $method?->id, 'amount' => $unpaid > 0 ? Money::toInput($unpaid) : ''];
        $this->paidFollowsTotal = false;
    }

    public function removePayment(int $index): void
    {
        if (count($this->payments) > 1 && isset($this->payments[$index])) {
            unset($this->payments[$index]);
            $this->payments = array_values($this->payments);
            $this->paidFollowsTotal = $this->followsTotal();
        }
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
        if ($type->isBill()) {
            $this->updatedAmount();
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
            'referenceFile' => ['nullable', File::types(self::REFERENCE_FILE_TYPES)->max(config('media.max_size_kb'))],
            'description' => ['nullable', 'string', 'max:500'],
        ] + ($type->isBill() ? [
            'categoryAccountId' => ['required', 'integer'],
            'partyId' => ['nullable', 'integer'],
            'payments' => ['array', 'max:'.LedgerService::MAX_PAYMENTS],
            'payments.*' => ['array:account,amount'],
            'payments.*.account' => ['nullable', 'integer'],
            'payments.*.amount' => ['nullable', 'string', $money(true)],
            'dueDate' => ['nullable', 'date_format:Y-m-d'],
            'paidBy' => ['required', 'integer'],
        ] : [
            'debitAccountId' => ['required', 'integer'],
            'creditAccountId' => ['required', 'integer'],
        ]);
        $this->validate($rules, [], $this->attributeLabels());
        $data = ['entry_date' => $this->entryDate, 'amount' => Money::toPaisa($this->amount),
            'reference' => trim($this->reference), 'description' => trim($this->description)];
        /** @var array<int, int> $rowOf posted payment index → form row */
        $rowOf = [];
        if ($type->isBill()) {
            $payments = [];
            foreach ($this->payments as $row => $payment) {
                $paid = $this->paisa($payment['amount'] ?? '');
                if ($paid === 0) {
                    continue;
                }
                if (($payment['account'] ?? '') === '') {
                    $this->addError("payments.{$row}.account", __('Choose a payment method.'));

                    return null;
                }
                $rowOf[count($payments)] = $row;
                $payments[] = ['account_id' => (int) $payment['account'], 'amount' => $paid];
            }
            $data += ['payments' => $payments, 'category_account_id' => (int) $this->categoryAccountId,
                'party_id' => $this->partyId !== '' ? (int) $this->partyId : null, 'due_date' => $this->dueDate !== '' ? $this->dueDate : null,
                'paid_by' => $this->paidBy !== '' ? (int) $this->paidBy : null];
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
                $field = preg_match('/^payments\.(\d+)\.(account_id|amount)$/', $key, $match)
                    ? 'payments.'.($rowOf[(int) $match[1]] ?? $match[1]).'.'.($match[2] === 'amount' ? 'amount' : 'account')
                    : Str::camel($key);
                $this->addError($field, $messages[0]);
            }

            return null;
        }
        $this->syncReferenceFile($saved, $user);
        $message = __('Entry :number saved.', ['number' => $saved->number]);
        if ($addAnother && ! $entry) {
            $this->payments = [['account' => $this->payments[0]['account'] ?? $this->defaultPaymentMethod(), 'amount' => '']];
            $this->reset('amount', 'partyId', 'dueDate', 'reference', 'referenceFile', 'description', 'debitAccountId', 'creditAccountId');
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
        $companyId = $this->visibleCompanyId();
        $accounts = $this->accountsOf($companyId, [$this->categoryAccountId, ...array_column($this->payments, 'account'), $this->debitAccountId, $this->creditAccountId]);
        $options = fn (Collection $items, string $placeholder): array => ['' => $placeholder] + $items
            ->mapWithKeys(fn (Account $account): array => [$account->id => $account->name.($account->is_active ? '' : ' ('.__('inactive').')')])->all();
        $methods = $options($accounts->filter(fn (Account $account): bool => $account->isPaymentMethod()), __('Select a payment method'));
        $settled = $this->entryId && $type->isBill() ? (int) JournalEntry::query()->where('bill_id', $this->entryId)->posted()->sum('amount') : 0;
        $total = $this->paisa($this->amount);
        $paid = $this->paidNow();
        $company = $companyId ? Company::query()->find($companyId, ['id', 'name', 'is_active']) : null;
        $stored = $this->entryId ? JournalEntry::query()->with('creator:id,name')->find($this->entryId) : null;
        $currentFile = $stored?->getMedia(JournalEntry::REFERENCE_FILE)->first();

        return view('livewire.admin.entries.form', [
            'isBill' => $type->isBill(),
            'categories' => $options($accounts->filter(fn (Account $account): bool => ! $account->is_system
                && $account->type === ($type === EntryType::Income ? AccountType::Income : AccountType::Expense)), __('Select a category')),
            'methods' => $methods,
            'parties' => $type->isBill() ? $this->partyOptions($companyId) : [],
            'showDue' => $type->isBill() && $paid < $total,
            'paidNow' => $paid,
            'unpaid' => max(0, $total - $paid),
            'canAddPayment' => count($this->payments) < min(LedgerService::MAX_PAYMENTS, count($methods) - 1),
            'canChoosePayer' => auth()->user()->isRoot(),
            'payers' => $type->isBill() ? $this->payerOptions($companyId) : [],
            'payerName' => User::query()->whereKey((int) $this->paidBy)->value('name'),
            'recorderName' => $stored ? $stored->creator?->name : auth()->user()->name,
            'currentFile' => $currentFile,
            'currentFileUrl' => $currentFile ? app(MediaService::class)->url($currentFile) : null,
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
            $this->categoryAccountId = (string) $entry->categoryAccount()?->id;
            $this->payments = $entry->lines->filter(fn ($line): bool => $line->account->isPaymentMethod())
                ->map(fn ($line): array => ['account' => (string) $line->account_id, 'amount' => Money::toInput($line->debit + $line->credit)])
                ->values()->all() ?: [['account' => $this->defaultPaymentMethod(), 'amount' => Money::toInput(0)]];
            $this->paidFollowsTotal = $this->followsTotal();
            $this->partyId = (string) $entry->party_id;
            $this->dueDate = (string) $entry->due_date?->toDateString();
            $this->paidBy = (string) ($entry->paid_by ?? auth()->id());
        } else {
            $this->debitAccountId = (string) $entry->debitAccount()?->id;
            $this->creditAccountId = (string) $entry->creditAccount()?->id;
        }
    }

    /** Whether there is one payment row and it pays the whole total. */
    private function followsTotal(): bool
    {
        return count($this->payments) === 1 && $this->payments[0]['amount'] === $this->amount;
    }

    /** Sum of the payment rows, counting blank or invalid amounts as zero. */
    private function paidNow(): int
    {
        return array_sum(array_map(fn (mixed $payment): int => $this->paisa(is_array($payment) ? $payment['amount'] ?? '' : ''), $this->payments));
    }

    /** Paisa of a taka input, or 0 when it is blank or invalid. */
    private function paisa(mixed $value): int
    {
        return is_string($value) && Money::isValidInput($value) ? Money::toPaisa($value) : 0;
    }

    private function visibleCompanyId(): ?int
    {
        return $this->companyId !== null && auth()->user()->canAccessCompany($this->companyId) ? $this->companyId : null;
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

    /**
     * Stores a newly chosen reference file and removes the one it replaces, or the current one when asked.
     * The entry write is already authorized; the file is an attachment, not ledger data.
     */
    private function syncReferenceFile(JournalEntry $entry, User $user): void
    {
        if ($this->referenceFile === null && ! $this->removeReferenceFile) {
            return;
        }
        $media = app(MediaService::class);
        $previous = $entry->getMedia(JournalEntry::REFERENCE_FILE);
        if ($this->referenceFile !== null) {
            $media->attach($this->referenceFile, $user, JournalEntry::REFERENCE_FILE, $entry);
        }
        $previous->each(fn (Media $file) => $media->detach($file));
        $this->reset('referenceFile', 'removeReferenceFile');
    }

    /**
     * For the super admin: active users who can record entries in the company, plus the current payer.
     *
     * @return array<int|string, string>
     */
    private function payerOptions(?int $companyId): array
    {
        $viewer = auth()->user();
        if ($companyId === null || ! $viewer->isRoot()) {
            return [];
        }
        $users = User::query()->where(fn (Builder $query) => $query->where('is_active', true)->orWhereKey((int) $this->paidBy))
            ->with('companies:id')->orderBy('name')->get()
            ->filter(fn (User $user): bool => $user->id === (int) $this->paidBy || ($user->hasPermission('admin.access')
                && ($user->hasPermission('companies.all') || $user->companies->contains('id', $companyId))));

        return $users->mapWithKeys(fn (User $user): array => [
            $user->id => $user->name.($user->is($viewer) ? ' ('.__('you').')' : '').($user->is_active ? '' : ' ('.__('inactive').')'),
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
            'categoryAccountId' => __('category'), 'partyId' => __('party'), 'payments' => __('paid now'), 'payments.*.account' => __('payment method'),
            'payments.*.amount' => $this->type === 'income' ? __('amount received') : __('amount paid'),
            'dueDate' => __('due date'), 'debitAccountId' => $transfer ? __('to') : __('payment method'), 'creditAccountId' => __('from'),
            'reference' => __('reference'), 'referenceFile' => __('reference file'), 'paidBy' => $this->type === 'income' ? __('received by') : __('paid by'),
            'description' => __('description')];
    }
}
