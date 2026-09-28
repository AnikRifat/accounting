<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\DueStatus;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An invoice, bill, offer, order, note or contract of one company. Written only through App\Services\DocumentService.
 * Totals are recomputed from the lines on every save. A document with `journal_entry_id` is posted: its payments
 * and outstanding balance come from the ledger. Otherwise its payments are `document_payments` rows.
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'tax_inclusive' => false, 'post_to_accounts' => false, 'discount_value' => 0,
        'subtotal' => 0, 'discount_total' => 0, 'tax_total' => 0, 'total' => 0];

    protected function casts(): array
    {
        return ['type' => DocumentType::class, 'status' => DocumentStatus::class, 'issue_date' => 'date', 'due_date' => 'date',
            'recurring_period' => 'date', 'tax_inclusive' => 'boolean', 'post_to_accounts' => 'boolean', 'custom_values' => 'array',
            'discount_value' => 'integer', 'subtotal' => 'integer', 'discount_total' => 'integer', 'tax_total' => 'integer', 'total' => 'integer',
            'share_expires_at' => 'datetime', 'issued_at' => 'datetime', 'voided_at' => 'datetime', 'balance' => 'integer'];
    }

    /** Stores a plain Y-m-d so date comparisons behave the same on SQLite and MySQL. */
    protected function issueDate(): Attribute
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

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class)->orderBy('sort')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(DocumentPayment::class)->orderBy('paid_on')->orderBy('id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(DocumentActivity::class)->latest('id');
    }

    /** The journal entry of a posted invoice, bill or note. */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /** The document this one was converted or issued from. */
    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_id');
    }

    /** Documents made from this one: the invoice of a quotation, the notes and delivery notes of an invoice… */
    public function derived(): HasMany
    {
        return $this->hasMany(self::class, 'source_id');
    }

    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }

    public function isVoid(): bool
    {
        return $this->status === DocumentStatus::Void;
    }

    public function isPosted(): bool
    {
        return $this->journal_entry_id !== null;
    }

    /** Issued, not void, and still counts: the states in which payments and notes can be recorded. */
    public function isOpen(): bool
    {
        return ! $this->isDraft() && ! $this->isVoid();
    }

    /** What is still owed, in paisa; requires withBalance(). Null for documents that are not paid. */
    public function balance(): ?int
    {
        return $this->type->isPayable() && $this->isOpen() ? (int) $this->balance : null;
    }

    /** Payment status of an open invoice or bill; requires withBalance(). */
    public function dueStatus(): ?DueStatus
    {
        $balance = $this->balance();
        if ($balance === null) {
            return null;
        }

        return match (true) {
            $balance <= 0 => DueStatus::Paid,
            $this->due_date !== null && $this->due_date->toDateString() < today()->toDateString() => DueStatus::Overdue,
            $balance < $this->total => DueStatus::PartlyPaid,
            default => DueStatus::Due,
        };
    }

    public function displayNumber(): string
    {
        return $this->number ?? __('Draft #:id', ['id' => $this->id]);
    }

    /** Documents of companies the user may access. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('company_id', $user->accessibleCompanyIds());
    }

    /** Adds `balance` (paisa): the ledger's outstanding for a posted document, else total − payments − issued notes. */
    public function scopeWithBalance(Builder $query): void
    {
        [$sql, $bindings] = self::balanceExpression();
        if ($query->getQuery()->columns === null) {
            $query->select($query->getModel()->getTable().'.*');
        }
        $query->selectRaw($sql.' as balance', $bindings);
    }

    /** Open invoices or bills in the given payment status, relative to today. */
    public function scopeDueStatus(Builder $query, DueStatus $status): void
    {
        [$sql, $bindings] = self::balanceExpression();
        $today = today()->toDateString();
        $notOverdue = fn (Builder $q) => $q->whereNull('documents.due_date')->orWhere('documents.due_date', '>=', $today);
        $query->whereIn('documents.status', [DocumentStatus::Issued, DocumentStatus::Accepted]);
        match ($status) {
            DueStatus::Paid => $query->whereRaw($sql.' <= 0', $bindings),
            DueStatus::Overdue => $query->whereRaw($sql.' > 0', $bindings)->where('documents.due_date', '<', $today),
            DueStatus::PartlyPaid => $query->whereRaw($sql.' > 0', $bindings)->whereRaw($sql.' < documents.total', $bindings)->where($notOverdue),
            DueStatus::Due => $query->whereRaw($sql.' >= documents.total', $bindings)->where($notOverdue),
        };
    }

    /**
     * SQL for what the outer `documents` row owed at the end of $date (Y-m-d): its total less the receipts, payments and
     * notes dated on or before it (ledger entries for a posted document, document payments and issued notes otherwise).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function balanceAsOfExpression(string $date): array
    {
        $settled = DB::table('journal_entries as settlements')->whereColumn('settlements.bill_id', 'documents.journal_entry_id')
            ->whereNull('settlements.voided_at')->whereNull('settlements.deleted_at')->where('settlements.entry_date', '<=', $date)
            ->selectRaw('COALESCE(SUM(settlements.amount), 0)');
        $paid = DB::table('document_payments')->whereColumn('document_payments.document_id', 'documents.id')
            ->where('document_payments.paid_on', '<=', $date)->selectRaw('COALESCE(SUM(document_payments.amount), 0)');
        $noted = DB::table('documents as notes')->whereColumn('notes.source_id', 'documents.id')
            ->whereIn('notes.type', [DocumentType::CreditNote->value, DocumentType::DebitNote->value])
            ->whereNotIn('notes.status', [DocumentStatus::Draft->value, DocumentStatus::Void->value])->where('notes.issue_date', '<=', $date)
            ->selectRaw('COALESCE(SUM(notes.total), 0)');

        return [
            '(CASE WHEN documents.journal_entry_id IS NOT NULL THEN documents.total - ('.$settled->toSql().') ELSE documents.total - ('.$paid->toSql().') - ('.$noted->toSql().') END)',
            [...$settled->getBindings(), ...$paid->getBindings(), ...$noted->getBindings()],
        ];
    }

    /**
     * SQL for what the outer `documents` row still owes.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function balanceExpression(): array
    {
        [$outstanding, $outstandingBindings] = JournalEntry::outstandingExpression();
        $posted = 'SELECT '.$outstanding.' FROM journal_entries WHERE journal_entries.id = documents.journal_entry_id';
        $paid = DB::table('document_payments')->whereColumn('document_payments.document_id', 'documents.id')
            ->selectRaw('COALESCE(SUM(document_payments.amount), 0)');
        $noted = DB::table('documents as notes')->whereColumn('notes.source_id', 'documents.id')
            ->whereIn('notes.type', [DocumentType::CreditNote->value, DocumentType::DebitNote->value])
            ->whereNotIn('notes.status', [DocumentStatus::Draft->value, DocumentStatus::Void->value])
            ->selectRaw('COALESCE(SUM(notes.total), 0)');

        return [
            '(CASE WHEN documents.journal_entry_id IS NOT NULL THEN COALESCE(('.$posted.'), 0) ELSE documents.total - ('.$paid->toSql().') - ('.$noted->toSql().') END)',
            [...$outstandingBindings, ...$paid->getBindings(), ...$noted->getBindings()],
        ];
    }
}
