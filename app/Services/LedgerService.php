<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\EntryType;
use App\Enums\PaymentType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Media;
use App\Models\Party;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * The only writer of journal data. Every entry posts 2 or more one-sided, balanced lines (paisa). Entries are
 * voided or moved to the Trash (soft delete); purge() is the only hard delete.
 *
 * Posting rules (T = total, P = paid now across up to MAX_PAYMENTS methods, D = T − P, X = settled):
 * - Income: Cr category T; Dr each payment method its share of P; Dr Accounts Receivable D (if D > 0).
 * - Expense: Dr category T; Cr each payment method its share of P; Cr Accounts Payable D (if D > 0).
 * - Receipt (settles an income bill): Dr payment method X; Cr Accounts Receivable X.
 * - Payment (settles an expense bill): Dr Accounts Payable X; Cr payment method X.
 * - Transfer: Dr receiving payment method; Cr paying payment method.
 * - Opening: Dr payment method; Cr Opening Balance Equity.
 */
class LedgerService
{
    /** The `nextCode()` kind for payment methods. */
    public const PAYMENT_METHOD = 'payment';

    /** @var list<array{0: string, 1: string, 2: AccountType, 3: bool, 4: bool, 5: ?PaymentType}> code, name, type, is_cash, is_system, payment_type */
    public const DEFAULT_CHART = [
        ['1000', 'Cash in Hand', AccountType::Asset, true, false, PaymentType::Cash],
        ['1010', 'Bank Account', AccountType::Asset, true, false, PaymentType::Bank],
        ['1020', 'bKash', AccountType::Asset, true, false, PaymentType::MobileBanking],
        ['1200', 'Accounts Receivable', AccountType::Asset, false, true, null],
        ['2000', 'Accounts Payable', AccountType::Liability, false, true, null],
        ['3000', 'Opening Balance Equity', AccountType::Equity, false, true, null],
        ['4000', 'Sales & Service Income', AccountType::Income, false, false, null],
        ['4900', 'Other Income', AccountType::Income, false, false, null],
        ['5000', 'Salaries & Wages', AccountType::Expense, false, false, null],
        ['5100', 'Office Rent', AccountType::Expense, false, false, null],
        ['5200', 'Utilities', AccountType::Expense, false, false, null],
        ['5300', 'Transport & Conveyance', AccountType::Expense, false, false, null],
        ['5400', 'Office Supplies', AccountType::Expense, false, false, null],
        ['5900', 'Other Expenses', AccountType::Expense, false, false, null],
    ];

    /** Upper bound of one entry: the largest amount App\Support\Money accepts as input. */
    public const MAX_AMOUNT = 9_999_999_999_999;

    /** Most payment methods one income or expense can be paid through. */
    public const MAX_PAYMENTS = 10;

    /** Concurrent writers can deadlock on InnoDB gap locks; Laravel retries the whole transaction this many times. */
    private const DEADLOCK_ATTEMPTS = 3;

    public function createDefaultAccounts(Company $company): void
    {
        foreach (self::DEFAULT_CHART as [$code, $name, $type, $isCash, $isSystem, $paymentType]) {
            $company->accounts()->forceCreate(['code' => $code, 'name' => $name, 'type' => $type, 'is_cash' => $isCash,
                'payment_type' => $paymentType, 'is_system' => $isSystem, 'is_active' => true]);
        }
    }

    /**
     * The next free account code for a new category (4000–4999 income, 5000–5999 expense) or payment
     * method (1000–1199). Call it inside the transaction that inserts the account: it locks the
     * company's accounts so two concurrent inserts can't take the same code.
     *
     * @param  AccountType|string  $kind  AccountType::Income, AccountType::Expense or self::PAYMENT_METHOD
     *
     * @throws ValidationException when the range is full
     */
    public function nextCode(Company $company, AccountType|string $kind): string
    {
        [$first, $last] = match ($kind) {
            AccountType::Income => [4000, 4999],
            AccountType::Expense => [5000, 5999],
            self::PAYMENT_METHOD => [1000, 1199],
            default => throw new InvalidArgumentException('Account codes are generated only for categories and payment methods.'),
        };
        $used = Account::query()->where('company_id', $company->id)->lockForUpdate()->pluck('code')
            ->filter(fn (string $code): bool => ctype_digit($code))->map(fn (mixed $value): int => (int) $value)
            ->filter(fn (int $code): bool => $code >= $first && $code <= $last)->values()->all();
        $next = $used === [] ? $first : max($used) + 1;
        if ($next > $last) {
            $free = array_values(array_diff(range($first, $last), $used));
            if ($free === []) {
                throw ValidationException::withMessages(['code' => __('No free account code is left in this range.')]);
            }
            $next = $free[0];
        }

        return (string) $next;
    }

    /**
     * Records an income, expense, transfer or opening entry. Receipts and payments go through settle().
     *
     * Income/expense data: entry_date (Y-m-d), amount (total, paisa), payments (list of {account_id, amount}:
     * the paid-now part, one row per distinct payment method, amounts > 0, summing to at most amount; [] when
     * nothing is paid now), category_account_id, party_id and due_date (both required when the payments sum
     * to less than amount), paid_by? (user id; see payerId(); always recorded, even when nothing
     * is paid now), description?, reference?.
     * Transfer/opening data: entry_date, amount, debit_account_id, credit_account_id, description?, reference?.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException|AuthorizationException
     */
    public function record(Company $company, EntryType $type, array $data, User $actor): JournalEntry
    {
        if ($type->isSettlement()) {
            throw new LogicException('Receipts and payments are recorded with settle().');
        }
        $this->authorize($actor, $type === EntryType::Opening ? 'accounts.manage' : 'entries.create', $company->id);

        return DB::transaction(function () use ($company, $type, $data, $actor): JournalEntry {
            $locked = $this->lockOpenCompany($company->id);
            $entry = (new JournalEntry)->forceFill([
                'company_id' => $locked->id, 'type' => $type, 'number' => $this->nextNumber($locked), 'created_by' => $actor->id,
            ]);
            $this->post($entry, $data, $actor);

            return $entry;
        }, self::DEADLOCK_ATTEMPTS);
    }

    /** Posts an opening balance for a payment method against Opening Balance Equity. */
    public function recordOpening(Account $cashAccount, int $amount, string $entryDate, User $actor): JournalEntry
    {
        return $this->record($cashAccount->company, EntryType::Opening, [
            'entry_date' => $entryDate, 'amount' => $amount, 'debit_account_id' => $cashAccount->id,
            'credit_account_id' => $this->systemAccount($cashAccount->company_id, AccountType::Equity)->id, 'description' => __('Opening balance'),
        ], $actor);
    }

    /**
     * Records a receipt (for an income bill) or payment (for an expense bill) against a bill's
     * outstanding balance. The settlement takes its party from the bill.
     *
     * @param  array{entry_date: string, amount: int, payment_account_id: int, paid_by?: ?int, description?: ?string, reference?: ?string}  $data  paid_by: who paid or received the money (see payerId())
     *
     * @throws ValidationException|AuthorizationException
     */
    public function settle(JournalEntry $bill, array $data, User $actor): JournalEntry
    {
        $this->authorize($actor, 'entries.create', $bill->company_id);

        return DB::transaction(function () use ($bill, $data, $actor): JournalEntry {
            $company = $this->lockOpenCompany($bill->company_id);
            $lockedBill = JournalEntry::query()->lockForUpdate()->findOrFail($bill->id);
            if (! $lockedBill->type->isBill() || $lockedBill->isVoided()) {
                throw ValidationException::withMessages(['entry' => __('Only posted income or expense entries can be settled.')]);
            }
            $entry = (new JournalEntry)->forceFill([
                'company_id' => $company->id, 'bill_id' => $lockedBill->id, 'number' => $this->nextNumber($company), 'created_by' => $actor->id,
                'type' => $lockedBill->type === EntryType::Income ? EntryType::Receipt : EntryType::Payment,
            ]);
            $this->post($entry, $data, $actor);

            return $entry;
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Re-posts an entry's lines with the data record() or settle() accepts for its type. The company,
     * type, number and (for settlements) bill never change.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException|AuthorizationException
     */
    public function update(JournalEntry $entry, array $data, User $actor): JournalEntry
    {
        $this->authorize($actor, 'entries.update', $entry->company_id);
        if ($entry->type === EntryType::Opening) {
            // Opening balances move payment-method balances and equity, so editing needs the same ability as recording.
            $this->authorize($actor, 'accounts.manage', $entry->company_id);
        }

        return DB::transaction(function () use ($entry, $data, $actor): JournalEntry {
            // bill_id never changes, so no pre-lock read is needed (it would fix a stale InnoDB snapshot).
            $billId = $entry->bill_id;
            if ($billId !== null) {
                // Lock order bill → settlement, the same as settle(), so concurrent writers can't deadlock.
                JournalEntry::query()->lockForUpdate()->findOrFail($billId);
            }
            $locked = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($locked->isVoided()) {
                throw ValidationException::withMessages(['entry' => __('Voided entries cannot be edited.')]);
            }
            $locked->updated_by = $actor->id;
            $this->post($locked, $data, $actor);

            return $locked;
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Voids an entry with a reason. A bill with posted settlements can't be voided; voiding a
     * settlement reopens that amount on its bill.
     *
     * @throws ValidationException|AuthorizationException
     */
    public function void(JournalEntry $entry, string $reason, User $actor): JournalEntry
    {
        $this->authorize($actor, 'entries.void', $entry->company_id);
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:500']], [], ['reason' => __('reason')])->validate();

        return DB::transaction(function () use ($entry, $reason, $actor): JournalEntry {
            $locked = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($locked->isVoided()) {
                throw ValidationException::withMessages(['reason' => __('This entry is already voided.')]);
            }
            if ($locked->type->isBill() && $this->settledAmount($locked) > 0) {
                throw ValidationException::withMessages(['reason' => __('Void the receipts or payments recorded against this entry first.')]);
            }
            $locked->forceFill(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason])->save();

            return $locked;
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Moves an entry (posted or voided) to the Trash, where it counts in no figure. A bill whose receipts
     * or payments (posted or voided) are not in the Trash is refused; trashing a settlement reopens its amount.
     *
     * @throws ValidationException|AuthorizationException
     */
    public function delete(JournalEntry $entry, User $actor): JournalEntry
    {
        $this->authorize($actor, 'entries.delete', $entry->company_id);

        return DB::transaction(function () use ($entry, $actor): JournalEntry {
            $this->lockBillOf($entry);
            $locked = JournalEntry::withTrashed()->lockForUpdate()->findOrFail($entry->id);
            if ($locked->trashed()) {
                throw ValidationException::withMessages(['entry' => __('This entry is already in the Trash.')]);
            }
            if ($locked->type->isBill() && JournalEntry::query()->where('bill_id', $locked->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['entry' => __('Delete the receipts or payments first.')]);
            }
            $locked->forceFill(['deleted_at' => now(), 'deleted_by' => $actor->id])->save();

            return $locked;
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Restores an entry from the Trash. Refused for an inactive company, for a settlement whose bill is in
     * the Trash (or voided), and when a restored settlement would pay more than its bill's outstanding amount.
     *
     * @throws ValidationException|AuthorizationException
     */
    public function restore(JournalEntry $entry, User $actor): JournalEntry
    {
        $this->authorize($actor, 'entries.delete', $entry->company_id);

        return DB::transaction(function () use ($entry, $actor): JournalEntry {
            $this->lockOpenCompany($entry->company_id);
            $bill = $this->lockBillOf($entry);
            $locked = JournalEntry::withTrashed()->lockForUpdate()->findOrFail($entry->id);
            if (! $locked->trashed()) {
                throw ValidationException::withMessages(['entry' => __('This entry is not in the Trash.')]);
            }
            if ($bill?->trashed()) {
                throw ValidationException::withMessages(['entry' => __('Restore :number first; this receipt or payment belongs to it.', ['number' => $bill->number])]);
            }
            if ($bill !== null && ! $locked->isVoided()) {
                if ($bill->isVoided()) {
                    throw ValidationException::withMessages(['entry' => __(':number is voided, so this receipt or payment can only be restored once it is voided too.', ['number' => $bill->number])]);
                }
                $outstanding = $this->outstanding($bill);
                if ($locked->amount > $outstanding) {
                    throw ValidationException::withMessages(['entry' => __('Restoring it would settle more than the :amount outstanding on :number.', [
                        'amount' => Money::format($outstanding), 'number' => $bill->number,
                    ])]);
                }
            }
            $locked->forceFill(['deleted_at' => null, 'deleted_by' => null, 'updated_by' => $actor->id])->save();

            return $locked;
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Permanently deletes an entry (in the Trash or not), its lines and attached files and, for a bill, all
     * of its receipts or payments in any state. The only hard delete of journal data.
     *
     * @throws AuthorizationException
     */
    public function purge(JournalEntry $entry, User $actor): void
    {
        $this->authorize($actor, 'entries.purge', $entry->company_id);

        $media = DB::transaction(function () use ($entry): EloquentCollection {
            $this->lockBillOf($entry);
            $locked = JournalEntry::withTrashed()->lockForUpdate()->findOrFail($entry->id);
            $settlementIds = $locked->type->isBill()
                ? JournalEntry::withTrashed()->where('bill_id', $locked->id)->lockForUpdate()->pluck('id')->all()
                : [];
            $ids = [...$settlementIds, $locked->id];
            $media = Media::query()->where('mediable_type', $locked->getMorphClass())->whereIn('mediable_id', $ids)->get();
            JournalLine::query()->whereIn('journal_entry_id', $ids)->delete();
            // Settlements first: bill_id restricts deleting a bill that still has them.
            JournalEntry::withTrashed()->whereKey($settlementIds)->forceDelete();
            JournalEntry::withTrashed()->whereKey($locked->id)->forceDelete();

            return $media;
        }, self::DEADLOCK_ATTEMPTS);
        // Files go only after the rows are gone for good, so a rolled-back purge never loses a file. afterCommit also
        // waits for an outer transaction (e.g. RecordDeletion's hard delete) and runs at once when there is none.
        DB::afterCommit(fn () => $media->each(fn (Media $item) => app(MediaService::class)->detach($item)));
    }

    /** Locks a settlement's bill (even in the Trash) before the settlement itself, the same order as settle(). */
    private function lockBillOf(JournalEntry $entry): ?JournalEntry
    {
        return $entry->bill_id === null ? null : JournalEntry::withTrashed()->lockForUpdate()->find($entry->bill_id);
    }

    /** Outstanding paisa of a bill: its receivable/payable line minus posted settlements (0 for other entries). */
    public function outstanding(JournalEntry $bill): int
    {
        if (! $bill->type->isBill()) {
            return 0;
        }
        $due = (int) $bill->lines()->whereIn('account_id', Account::query()->where('company_id', $bill->company_id)
            ->where('is_system', true)->whereIn('type', [AccountType::Asset, AccountType::Liability])->select('id'))
            ->lockForUpdate()->sum(DB::raw('debit + credit'));

        return $due - $this->settledAmount($bill);
    }

    /**
     * Posted income (receivable) and expense (payable) entries that still have an outstanding
     * balance, earliest due date first. The caller must pass only company ids the viewer may access.
     *
     * @param  list<int>  $companyIds
     * @return EloquentCollection<int, JournalEntry> each with `outstanding` selected and company and party loaded
     */
    public function dues(array $companyIds, ?EntryType $type = null, ?int $partyId = null): EloquentCollection
    {
        return JournalEntry::query()->whereIn('company_id', $companyIds)->open()
            ->when($type, fn ($query) => $query->where('type', $type))
            ->when($partyId, fn ($query) => $query->where('party_id', $partyId))
            ->withOutstanding()->with(['company:id,name,code', 'party:id,name,phone'])
            ->orderBy('due_date')->orderBy('id')->get();
    }

    /**
     * Account balance in paisa from posted (not voided) entries dated on or before $upTo.
     * Asset and expense accounts return debit − credit; the others credit − debit.
     */
    public function balance(Account $account, ?CarbonInterface $upTo = null): int
    {
        return $this->balances([$account], $upTo)[$account->id];
    }

    /**
     * @param  iterable<Account>  $accounts
     * @return array<int, int> balance in paisa keyed by account id (0 when the account has no posted lines)
     */
    public function balances(iterable $accounts, ?CarbonInterface $upTo = null): array
    {
        $accounts = collect($accounts)->keyBy('id');
        if ($accounts->isEmpty()) {
            return [];
        }
        $totals = JournalLine::query()
            ->whereIn('account_id', $accounts->keys())
            ->whereIn('journal_entry_id', JournalEntry::query()->posted()
                ->when($upTo, fn ($query) => $query->where('entry_date', '<=', $upTo->toDateString()))->select('id'))
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(debit) as debit_total, SUM(credit) as credit_total')
            ->get()->keyBy('account_id');

        return $accounts->map(function (Account $account) use ($totals): int {
            $net = (int) ($totals[$account->id]->debit_total ?? 0) - (int) ($totals[$account->id]->credit_total ?? 0);

            return $account->type->isDebitNormal() ? $net : -$net;
        })->all();
    }

    /**
     * Posted debit and credit totals per account for entries dated from $from to $to (inclusive), in one
     * grouped query. The caller must pass only company ids the viewer may access.
     *
     * @param  list<int>  $companyIds
     * @param  list<AccountType>  $types
     * @return Collection<int, object{account_id: int, company_id: int, code: string, name: string, type: string, debit_total: int|string, credit_total: int|string}>
     */
    public function periodActivity(array $companyIds, string $from, string $to, array $types): Collection
    {
        return JournalLine::query()->toBase()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereIn('journal_entries.company_id', $companyIds)
            ->whereNull('journal_entries.voided_at')
            ->whereNull('journal_entries.deleted_at')
            ->whereBetween('journal_entries.entry_date', [$from, $to])
            ->whereIn('accounts.type', array_map(fn (AccountType $type): string => $type->value, $types))
            ->groupBy('accounts.id', 'accounts.company_id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->select('accounts.id as account_id', 'accounts.company_id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->selectRaw('SUM(journal_lines.debit) as debit_total, SUM(journal_lines.credit) as credit_total')
            ->get();
    }

    private function authorize(User $actor, string $ability, int $companyId): void
    {
        Gate::forUser($actor)->authorize($ability);
        if (! $actor->canAccessCompany($companyId)) {
            throw new AuthorizationException(__('You do not have access to this company.'));
        }
    }

    /** Locks the company row, which serialises number generation, and refuses inactive companies. */
    private function lockOpenCompany(int $companyId): Company
    {
        $company = Company::query()->lockForUpdate()->findOrFail($companyId);
        if (! $company->is_active) {
            throw ValidationException::withMessages(['company_id' => __('This company is inactive and does not accept new entries.')]);
        }

        return $company;
    }

    private function nextNumber(Company $company): string
    {
        // A locking read sees the latest committed row even when an outer transaction's snapshot is older;
        // trashed entries keep their numbers, so they are included.
        $last = JournalEntry::withTrashed()->where('company_id', $company->id)->latest('id')->lockForUpdate()->value('number');
        $sequence = $last === null ? 1 : (int) Str::afterLast($last, '-') + 1;

        return $company->code.'-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    /** Accounts Receivable (asset), Accounts Payable (liability) or Opening Balance Equity (equity). */
    private function systemAccount(int $companyId, AccountType $type): Account
    {
        return Account::query()->where('company_id', $companyId)->where('is_system', true)->where('type', $type)->first()
            ?? throw new LogicException("Company {$companyId} has no system {$type->value} account.");
    }

    /** Sum of a bill's posted settlements, read with a lock so concurrent settlements see each other. */
    private function settledAmount(JournalEntry $bill): int
    {
        return (int) JournalEntry::query()->where('bill_id', $bill->id)->posted()->lockForUpdate()->sum('amount');
    }

    /** Validates the data for the entry's type, then writes the entry and its lines and checks the balance. */
    private function post(JournalEntry $entry, array $data, User $actor): void
    {
        $keep = $entry->exists ? $entry->lines()->pluck('account_id')->map(fn (mixed $value): int => (int) $value)->all() : [];
        [$attributes, $lines] = match (true) {
            $entry->type->isBill() => $this->billPosting($entry, $data, $keep, $actor),
            $entry->type->isSettlement() => $this->settlementPosting($entry, $data, $keep, $actor),
            default => $this->simplePosting($entry, $data, $keep),
        };
        $entry->fill($attributes + [
            'description' => ($data['description'] ?? '') !== '' ? $data['description'] : null,
            'reference' => ($data['reference'] ?? '') !== '' ? $data['reference'] : null,
        ])->save();
        $entry->lines()->delete();
        $entry->lines()->createMany($lines);
        $this->assertBalanced($entry);
        $entry->unsetRelation('lines');
    }

    /**
     * @param  list<int>  $keep  accounts already on the entry, which stay valid even if since deactivated
     * @return array{0: array<string, mixed>, 1: list<array{account_id: int, debit: int, credit: int}>}
     */
    private function billPosting(JournalEntry $entry, array $data, array $keep, User $actor): array
    {
        $data = $this->validate($data, [
            'amount' => ['required', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            'payments' => ['present', 'array', 'max:'.self::MAX_PAYMENTS],
            'payments.*' => ['array:account_id,amount'],
            'payments.*.account_id' => ['required', 'integer', 'distinct'],
            'payments.*.amount' => ['required', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            'category_account_id' => ['required', 'integer'],
            'party_id' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'paid_by' => ['nullable', 'integer'],
        ]);
        $income = $entry->type === EntryType::Income;
        $total = (int) $data['amount'];
        $paid = array_sum(array_map(fn (array $payment): int => (int) $payment['amount'], $data['payments']));
        $unpaid = $total - $paid;
        $errors = $paid > $total ? ['payments' => __('The amount paid now can\'t be more than the total.')] : [];
        $category = $this->account($entry, $data['category_account_id'], $keep, 'category_account_id', $errors,
            fn (Account $account): bool => ! $account->is_system && $account->type === ($income ? AccountType::Income : AccountType::Expense),
            $income ? __('Choose an income category.') : __('Choose an expense category.'));
        $methods = [];
        foreach ($data['payments'] as $index => $payment) {
            $methods[$index] = $this->account($entry, $payment['account_id'], $keep, "payments.{$index}.account_id", $errors,
                fn (Account $account): bool => $account->isPaymentMethod(), __('Choose a payment method.'));
        }
        $partyId = $this->partyId($entry, $data['party_id'] ?? null, $errors);
        $payerId = $this->payerId($entry, $data['paid_by'] ?? null, $actor, $errors);
        if ($unpaid > 0) {
            if ($partyId === null && ! isset($errors['party_id'])) {
                $errors['party_id'] = __('Choose who owes or is owed the unpaid amount.');
            }
            if (($data['due_date'] ?? null) === null) {
                $errors['due_date'] = __('Enter the due date for the unpaid amount.');
            } elseif ($data['due_date'] < $data['entry_date']) {
                $errors['due_date'] = __('The due date must be on or after the entry date.');
            }
        }
        if ($entry->exists && ($settled = $this->settledAmount($entry)) > 0) {
            if ($unpaid < $settled) {
                $errors['amount'] = __('The unpaid part can\'t be less than the :amount already settled.', ['amount' => Money::format($settled)]);
            }
            if ($partyId === null || $partyId !== (int) $entry->getOriginal('party_id')) {
                $errors['party_id'] = __('The party can\'t change once receipts or payments are recorded.');
            }
            $firstSettlement = JournalEntry::query()->where('bill_id', $entry->id)->posted()->lockForUpdate()->min('entry_date');
            if ($data['entry_date'] > $firstSettlement) {
                $errors['entry_date'] = __('The date can\'t be after the first receipt or payment.');
            }
        }
        $this->throwIf($errors);

        $side = fn (int $accountId, int $amount, bool $debit): array => ['account_id' => $accountId, 'debit' => $debit ? $amount : 0, 'credit' => $debit ? 0 : $amount];
        $lines = [$side($category->id, $total, ! $income)];
        foreach ($data['payments'] as $index => $payment) {
            $lines[] = $side($methods[$index]->id, (int) $payment['amount'], $income);
        }
        if ($unpaid > 0) {
            $lines[] = $side($this->systemAccount($entry->company_id, $income ? AccountType::Asset : AccountType::Liability)->id, $unpaid, $income);
        }

        return [['entry_date' => $data['entry_date'], 'amount' => $total, 'party_id' => $partyId,
            'due_date' => $unpaid > 0 ? $data['due_date'] : null, 'paid_by' => $payerId], $lines];
    }

    /**
     * @param  list<int>  $keep
     * @return array{0: array<string, mixed>, 1: list<array{account_id: int, debit: int, credit: int}>}
     */
    private function settlementPosting(JournalEntry $entry, array $data, array $keep, User $actor): array
    {
        $data = $this->validate($data, [
            'amount' => ['required', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            'payment_account_id' => ['required', 'integer'],
            'paid_by' => ['nullable', 'integer'],
        ]);
        $bill = JournalEntry::query()->lockForUpdate()->findOrFail($entry->bill_id);
        $errors = [];
        $payerId = $this->payerId($entry, $data['paid_by'] ?? null, $actor, $errors);
        $method = $this->account($entry, $data['payment_account_id'], $keep, 'payment_account_id', $errors,
            fn (Account $account): bool => $account->isPaymentMethod(), __('Choose a payment method.'));
        $available = $this->outstanding($bill) + ($entry->exists ? (int) $entry->getOriginal('amount') : 0);
        if ((int) $data['amount'] > $available) {
            $errors['amount'] = __('The amount can\'t be more than the outstanding :amount.', ['amount' => Money::format($available)]);
        }
        if ($data['entry_date'] < $bill->entry_date->toDateString()) {
            $errors['entry_date'] = __('The date must be on or after the date of :number.', ['number' => $bill->number]);
        }
        $this->throwIf($errors);
        $counterpart = $this->systemAccount($entry->company_id, $bill->type === EntryType::Income ? AccountType::Asset : AccountType::Liability);
        [$debit, $credit] = $entry->type === EntryType::Receipt ? [$method, $counterpart] : [$counterpart, $method];
        $amount = (int) $data['amount'];

        return [['entry_date' => $data['entry_date'], 'amount' => $amount, 'party_id' => $bill->party_id, 'due_date' => null, 'paid_by' => $payerId], [
            ['account_id' => $debit->id, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $credit->id, 'debit' => 0, 'credit' => $amount],
        ]];
    }

    /**
     * Transfer and opening entries: one debit and one credit account.
     *
     * @param  list<int>  $keep
     * @return array{0: array<string, mixed>, 1: list<array{account_id: int, debit: int, credit: int}>}
     */
    private function simplePosting(JournalEntry $entry, array $data, array $keep): array
    {
        $data = $this->validate($data, [
            'amount' => ['required', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            'debit_account_id' => ['required', 'integer'],
            'credit_account_id' => ['required', 'integer', 'different:debit_account_id'],
        ]);
        $isMethod = fn (Account $account): bool => $account->isPaymentMethod();
        [$creditFits, $debitMessage, $creditMessage] = $entry->type === EntryType::Transfer
            ? [$isMethod, __('Choose the payment method receiving the money.'), __('Choose the payment method the money leaves.')]
            : [fn (Account $account): bool => $account->is_system && $account->type === AccountType::Equity,
                __('Opening balances are recorded for payment methods.'), __('Opening balances are posted against Opening Balance Equity.')];
        $errors = [];
        $debit = $this->account($entry, $data['debit_account_id'], $keep, 'debit_account_id', $errors, $isMethod, $debitMessage);
        $credit = $this->account($entry, $data['credit_account_id'], $keep, 'credit_account_id', $errors, $creditFits, $creditMessage);
        $this->throwIf($errors);
        $amount = (int) $data['amount'];

        return [['entry_date' => $data['entry_date'], 'amount' => $amount, 'party_id' => null, 'due_date' => null], [
            ['account_id' => $debit->id, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $credit->id, 'debit' => 0, 'credit' => $amount],
        ]];
    }

    /**
     * Resolves an account of the entry's company that is active (or already on the entry) and fits the
     * entry type; records an error under $key otherwise.
     *
     * @param  list<int>  $keep
     * @param  array<string, string>  $errors
     * @param  callable(Account): bool  $fits
     */
    private function account(JournalEntry $entry, mixed $id, array $keep, string $key, array &$errors, callable $fits, string $message): ?Account
    {
        $account = Account::query()->find($id);
        $error = match (true) {
            $account === null || (int) $account->company_id !== (int) $entry->company_id => __('The selected account does not belong to this company.'),
            ! $account->is_active && ! in_array($account->id, $keep, true) => __('The selected account is inactive.'),
            ! $fits($account) => $message,
            default => null,
        };
        if ($error !== null) {
            $errors[$key] = $error;

            return null;
        }

        return $account;
    }

    /**
     * A party of the entry's company that is active, or already on the entry.
     *
     * @param  array<string, string>  $errors
     */
    private function partyId(JournalEntry $entry, mixed $partyId, array &$errors): ?int
    {
        if ($partyId === null) {
            return null;
        }
        $party = Party::query()->find($partyId);
        if ($party === null || (int) $party->company_id !== (int) $entry->company_id) {
            $errors['party_id'] = __('The selected party does not belong to this company.');

            return null;
        }
        if (! $party->is_active && $party->id !== (int) $entry->getOriginal('party_id')) {
            $errors['party_id'] = __('The selected party is inactive.');

            return null;
        }

        return $party->id;
    }

    /**
     * Who paid or received the money. Only the super admin chooses, defaulting to themselves; anyone
     * else records it as themselves, and their edits keep the payer already on the entry.
     *
     * @param  array<string, string>  $errors
     */
    private function payerId(JournalEntry $entry, mixed $payerId, User $actor, array &$errors): ?int
    {
        $current = $entry->getOriginal('paid_by') ?? $actor->id;
        if (! $actor->isRoot() || $payerId === null || (int) $payerId === (int) $current) {
            return (int) $current;
        }
        $payer = User::query()->find($payerId);
        if ($payer === null || ! $payer->is_active || ! $payer->hasPermission('admin.access') || ! $payer->canAccessCompany($entry->company_id)) {
            $errors['paid_by'] = __('Choose an active user with access to this company.');

            return null;
        }

        return $payer->id;
    }

    /**
     * Validates the fields every entry shares plus the given type-specific rules.
     *
     * @param  array<string, list<mixed>>  $rules
     * @return array<string, mixed>
     */
    private function validate(array $data, array $rules): array
    {
        return Validator::make($data, $rules + [
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
        ], [
            'amount.min' => __('The amount must be greater than zero.'),
            'payments.*.amount.min' => __('The amount must be greater than zero.'),
            'payments.*.account_id.distinct' => __('Choose each payment method once.'),
            'credit_account_id.different' => __('Choose two different accounts.'),
        ], [
            'entry_date' => __('date'), 'amount' => __('amount'), 'payments' => __('paid now'), 'category_account_id' => __('category'),
            'payments.*.account_id' => __('payment method'), 'payments.*.amount' => __('amount'), 'payment_account_id' => __('payment method'), 'party_id' => __('party'), 'due_date' => __('due date'), 'paid_by' => __('paid by'),
            'debit_account_id' => __('account'), 'credit_account_id' => __('account'), 'description' => __('description'), 'reference' => __('reference'),
        ])->validate();
    }

    /** @param array<string, string> $errors */
    private function throwIf(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Re-reads the written lines: 2 to MAX_PAYMENTS + 2 lines, each one-sided, debits = credits = the entry's amount. */
    private function assertBalanced(JournalEntry $entry): void
    {
        $lines = $entry->lines()->get(['debit', 'credit']);
        $oneSided = $lines->every(fn (JournalLine $line): bool => ($line->debit > 0) !== ($line->credit > 0));
        if ($lines->count() < 2 || $lines->count() > self::MAX_PAYMENTS + 2 || ! $oneSided
            || $lines->sum('debit') !== $lines->sum('credit') || $lines->sum('debit') !== $entry->amount) {
            throw new LogicException("Journal entry {$entry->number} is not balanced.");
        }
    }
}
