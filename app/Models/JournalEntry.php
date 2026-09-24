<?php

namespace App\Models;

use App\Concerns\HasMedia;
use App\Enums\AccountType;
use App\Enums\DueStatus;
use App\Enums\EntryType;
use Database\Factories\JournalEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A balanced double entry of 2–3 one-sided lines. Written only through App\Services\LedgerService;
 * never deleted, only voided. Income and expense entries are bills: `amount` is their total and any
 * unpaid part sits on Accounts Receivable/Payable until receipts or payments (`bill_id`) settle it.
 */
#[Fillable(['entry_date', 'amount', 'description', 'reference', 'party_id', 'due_date', 'paid_by'])]
class JournalEntry extends Model
{
    /** Media collection of the optional voucher, invoice or receipt file. */
    public const REFERENCE_FILE = 'reference';

    /** @use HasFactory<JournalEntryFactory> */
    use HasFactory, HasMedia;

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'due_date' => 'date', 'type' => EntryType::class, 'amount' => 'integer',
            'voided_at' => 'datetime', 'outstanding' => 'integer'];
    }

    /** Stores a plain Y-m-d so date comparisons behave the same on SQLite and MySQL. */
    protected function entryDate(): Attribute
    {
        return Attribute::set(fn (mixed $value): string => Carbon::parse($value)->toDateString());
    }

    protected function dueDate(): Attribute
    {
        return Attribute::set(fn (mixed $value): ?string => $value === null || $value === '' ? null : Carbon::parse($value)->toDateString());
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** The income or expense entry a receipt or payment settles. */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(self::class, 'bill_id');
    }

    /** Receipts or payments recorded against this bill, including voided ones. */
    public function settlements(): HasMany
    {
        return $this->hasMany(self::class, 'bill_id');
    }

    /** Who paid (expense) or received (income) the money paid now on a bill. */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /** The account on the debit side (the first one when several). Requires the lines to be loaded or loadable. */
    public function debitAccount(): ?Account
    {
        return $this->lines->firstWhere('debit', '>', 0)?->account;
    }

    public function creditAccount(): ?Account
    {
        return $this->lines->firstWhere('credit', '>', 0)?->account;
    }

    /** The income or expense category of a bill. */
    public function categoryAccount(): ?Account
    {
        return $this->lines->first(fn (JournalLine $line): bool => ! $line->account->is_system
            && in_array($line->account->type, [AccountType::Income, AccountType::Expense], true))?->account;
    }

    /** The payment method money moved through (for a transfer, the receiving one). */
    public function paymentAccount(): ?Account
    {
        return $this->lines->sortByDesc('debit')->first(fn (JournalLine $line): bool => $line->account->isPaymentMethod())?->account;
    }

    /** Paid so far on a bill; requires withOutstanding(). */
    public function paidAmount(): int
    {
        return $this->amount - (int) $this->outstanding;
    }

    /** Status of a posted bill; requires withOutstanding(). Null for other entries and voided bills. */
    public function dueStatus(): ?DueStatus
    {
        if (! $this->type->isBill() || $this->isVoided()) {
            return null;
        }

        return match (true) {
            $this->outstanding <= 0 => DueStatus::Paid,
            $this->due_date !== null && $this->due_date->toDateString() < today()->toDateString() => DueStatus::Overdue,
            $this->outstanding < $this->amount => DueStatus::PartlyPaid,
            default => DueStatus::Due,
        };
    }

    /** Entries of companies whose books the user may read. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('company_id', Company::visibleTo($user)->select('id'));
    }

    /** Entries that count towards balances and totals. */
    public function scopePosted(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    /** Posted income and expense entries that still have an outstanding balance. */
    public function scopeOpen(Builder $query): void
    {
        [$sql, $bindings] = self::outstandingExpression();
        $query->posted()->whereIn('type', [EntryType::Income, EntryType::Expense])->whereRaw($sql.' > 0', $bindings);
    }

    /** Adds `outstanding` (paisa): a bill's receivable/payable line minus its posted settlements; 0 for other entries. */
    public function scopeWithOutstanding(Builder $query): void
    {
        [$sql, $bindings] = self::outstandingExpression();
        if ($query->getQuery()->columns === null) {
            $query->select($query->getModel()->getTable().'.*');
        }
        $query->selectRaw($sql.' as outstanding', $bindings);
    }

    /** Posted bills in the given status, relative to today in the application timezone. */
    public function scopeDueStatus(Builder $query, DueStatus $status): void
    {
        [$sql, $bindings] = self::outstandingExpression();
        $today = today()->toDateString();
        $notOverdue = fn (Builder $q) => $q->whereNull('due_date')->orWhere('due_date', '>=', $today);
        $query->posted()->whereIn('type', [EntryType::Income, EntryType::Expense]);
        match ($status) {
            DueStatus::Paid => $query->whereRaw($sql.' <= 0', $bindings),
            DueStatus::Overdue => $query->whereRaw($sql.' > 0', $bindings)->where('due_date', '<', $today),
            DueStatus::PartlyPaid => $query->whereRaw($sql.' > 0', $bindings)->whereRaw($sql.' < journal_entries.amount', $bindings)->where($notOverdue),
            DueStatus::Due => $query->whereRaw($sql.' >= journal_entries.amount', $bindings)->where($notOverdue),
        };
    }

    /**
     * Applies list filters; malformed values are ignored. Company scope comes from visibleTo() and CompanyContext, never from here.
     *
     * @param  array{from?: mixed, to?: mixed, type?: mixed, account?: mixed, party?: mixed, payer?: mixed, status?: mixed, search?: mixed}  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $value = fn (string $key): string => is_scalar($filters[$key] ?? null) ? trim((string) $filters[$key]) : '';
        $id = fn (string $key): ?int => ctype_digit($value($key)) ? (int) $value($key) : null;
        $date = fn (string $key): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value($key)) === 1 ? $value($key) : null;
        $search = mb_substr($value('search'), 0, 100);

        $query->when($date('from'), fn (Builder $q, string $from) => $q->where('entry_date', '>=', $from))
            ->when($date('to'), fn (Builder $q, string $to) => $q->where('entry_date', '<=', $to))
            ->when(EntryType::tryFrom($value('type')), fn (Builder $q, EntryType $type) => $q->where('type', $type))
            ->when($id('account'), fn (Builder $q, int $accountId) => $q->whereHas('lines', fn (Builder $lines) => $lines->where('account_id', $accountId)))
            ->when($id('party'), fn (Builder $q, int $partyId) => $q->where('party_id', $partyId))
            ->when($id('payer'), fn (Builder $q, int $payerId) => $q->where('paid_by', $payerId))
            ->when(DueStatus::tryFrom($value('status')), fn (Builder $q, DueStatus $status) => $q->dueStatus($status))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('number', 'like', '%'.$search.'%')
                ->orWhere('description', 'like', '%'.$search.'%')->orWhere('reference', 'like', '%'.$search.'%')));
    }

    /**
     * SQL for the outstanding amount of the outer `journal_entries` row, correlated by table name.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function outstandingExpression(): array
    {
        $due = DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereColumn('journal_lines.journal_entry_id', 'journal_entries.id')
            ->where('accounts.is_system', true)
            ->whereIn('accounts.type', [AccountType::Asset->value, AccountType::Liability->value])
            ->selectRaw('COALESCE(SUM(journal_lines.debit + journal_lines.credit), 0)');
        $settled = DB::table('journal_entries as settlements')
            ->whereColumn('settlements.bill_id', 'journal_entries.id')
            ->whereNull('settlements.voided_at')
            ->selectRaw('COALESCE(SUM(settlements.amount), 0)');

        return [
            '(CASE WHEN journal_entries.type IN (?, ?) THEN ('.$due->toSql().') - ('.$settled->toSql().') ELSE 0 END)',
            [EntryType::Income->value, EntryType::Expense->value, ...$due->getBindings(), ...$settled->getBindings()],
        ];
    }
}
