<?php

namespace App\Services;

use App\Livewire\Admin\Users\ManageableUsers;
use App\Models\Account;
use App\Models\Company;
use App\Models\CrmService;
use App\Models\CrmStatus;
use App\Models\Document;
use App\Models\DocumentField;
use App\Models\DocumentLine;
use App\Models\DocumentPayment;
use App\Models\DocumentSequence;
use App\Models\DocumentTemplate;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\LeadEmail;
use App\Models\Media;
use App\Models\Party;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Deletes master data: categories, payment methods and other accounts, parties, whole companies and
 * employees.
 *
 * A record used by transactions is either transferred (its transactions move to another record of
 * the same company and kind) or hard deleted (every related transaction is purged through
 * LedgerService::purge). Trashed transactions count as used, so nothing is left pointing at a
 * deleted record. System accounts, employee parties and a company's last active payment method
 * are never deleted here. An employee is deleted only while no transaction names them (as author,
 * editor, voider, payer or through their parties) and they logged no CRM call, so the audit trail
 * stays whole; otherwise they are deactivated instead. Their assigned leads become unassigned.
 */
class RecordDeletion
{
    public function __construct(private readonly LedgerService $ledger) {}

    /** @return array{count: int, total: int} transactions (trashed included) and their summed amount in paisa */
    public function usage(Account|Party|Company|User $record): array
    {
        $entries = $this->relatedEntries($record);

        return ['count' => (clone $entries)->count(), 'total' => (int) (clone $entries)->sum('amount')];
    }

    /** Why the record can never be deleted here, or null. */
    public function blockedReason(Account|Party|Company|User $record): ?string
    {
        return match (true) {
            $record instanceof Account && $record->is_system => __('System accounts are maintained by the application and cannot be deleted.'),
            $record instanceof Party && Document::query()->where('party_id', $record->id)->exists() => __('This party appears on sales documents. Deactivate it instead, so the documents keep their customer or supplier.'),
            $record instanceof User && $this->hasSalesRecords($record) => __('This employee appears on sales documents, as their party or as the one who wrote them. Deactivate the employee instead, so the document history keeps their name.'),
            $record instanceof Party && $record->isEmployee() => __('This party is an employee. Remove the employee from the company on the Users page instead.'),
            $record instanceof Account && $this->isLastActivePaymentMethod($record) => __('A company needs at least one active payment method. Add or activate another one first.'),
            $record instanceof User && $record->isRoot() => __('The super admin cannot be deleted.'),
            $record instanceof User && $this->relatedEntries($record)->exists() => __('This employee appears in transactions, as the one who entered, edited, voided or paid them, or as their party. Deactivate the employee instead, so the history keeps their name.'),
            $record instanceof User && LeadCall::query()->where('user_id', $record->id)->exists() => __('This employee has logged CRM calls. Deactivate the employee instead, so the call history keeps their name.'),
            $record instanceof User && LeadEmail::query()->where('user_id', $record->id)->exists() => __('This employee has emailed CRM leads. Deactivate the employee instead, so the email log keeps their name.'),
            default => null,
        };
    }

    /**
     * Records the transactions of `$from` may move to: active, same company and same kind.
     *
     * @return Collection<int, Account>|Collection<int, Party>
     */
    public function transferTargets(Account|Party $from): Collection
    {
        if ($from instanceof Party) {
            return Party::query()->where('company_id', $from->company_id)->where('is_active', true)->whereKeyNot($from->id)->orderBy('name')->get();
        }

        return Account::query()->where('company_id', $from->company_id)->where('is_active', true)->where('is_system', false)
            ->where('type', $from->type)->where('is_cash', $from->is_cash)->whereKeyNot($from->id)->orderBy('code')->get();
    }

    /** Deletes a record that no transaction uses. */
    public function deleteUnused(Account|Party|User $record, User $actor): void
    {
        if ($record instanceof User) {
            $this->deleteUser($record, $actor);

            return;
        }
        $this->authorize($actor, $this->ability($record), $record->company_id);
        $this->refuseBlocked($record);
        DB::transaction(function () use ($record): void {
            if ($this->relatedEntries($record)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['record' => __('This record is used by transactions. Transfer them or delete them permanently.')]);
            }
            if ($record instanceof Account && $this->usedOnDocuments($record)) {
                throw ValidationException::withMessages(['record' => __('This account is used on sales documents or items. Deactivate it instead.')]);
            }
            $record->delete();
        });
    }

    /** Moves every transaction of `$from` to `$to`, then deletes `$from`. */
    public function transfer(Account|Party $from, Account|Party $to, User $actor): void
    {
        $this->authorize($actor, $this->ability($from), $from->company_id);
        $this->refuseBlocked($from);
        if (! $this->transferTargets($from)->contains(fn ($target): bool => $target::class === $to::class && $target->id === $to->id)) {
            throw ValidationException::withMessages(['target' => __('Choose an active record of the same company and kind.')]);
        }
        DB::transaction(function () use ($from, $to): void {
            $entryIds = $this->relatedEntries($from)->lockForUpdate()->pluck('id')->all();
            if ($from instanceof Party) {
                JournalEntry::withTrashed()->whereKey($entryIds)->update(['party_id' => $to->id]);
            } else {
                // Lines on both accounts would turn into lines of one account, e.g. a transfer into a transfer to itself.
                if ($this->relatedEntries($to)->whereKey($entryIds)->exists()) {
                    throw ValidationException::withMessages(['target' => $from->isPaymentMethod()
                        ? __('Transfers exist between these two payment methods. Delete those transfers first, or choose another payment method.')
                        : __('Some transactions use both accounts. Choose another account.')]);
                }
                DB::table('journal_lines')->whereIn('journal_entry_id', $entryIds)->where('account_id', $from->id)->update(['account_id' => $to->id]);
                // Sales documents, items and document payments follow, so documents keep posting to a real account.
                foreach ([DocumentLine::class, Item::class, DocumentPayment::class] as $model) {
                    $model::query()->where('account_id', $from->id)->update(['account_id' => $to->id]);
                }
            }
            $this->assertBalanced($entryIds);
            $from->delete();
        });
    }

    /**
     * Permanently deletes the record with every related transaction (and a bill's receipts or payments).
     * A company also loses its parties, accounts, CRM data and user assignments; `$confirmation` must be its code.
     */
    public function hardDelete(Account|Party|Company $record, User $actor, ?string $confirmation = null): void
    {
        if ($record instanceof Company) {
            $this->deleteCompany($record, (string) $confirmation, $actor);

            return;
        }
        $this->authorize($actor, $this->ability($record), $record->company_id);
        Gate::forUser($actor)->authorize('entries.purge');
        $this->refuseBlocked($record);
        DB::transaction(function () use ($record, $actor): void {
            if ($record instanceof Account && ($this->usedOnDocuments($record) || $this->relatedEntries($record)->whereHas('document')->exists())) {
                throw ValidationException::withMessages(['record' => __('This account is used by sales documents. Transfer its transactions to another account instead, or deactivate it.')]);
            }
            $this->purgeEntries($this->relatedEntries($record)->lockForUpdate()->orderBy('id')->pluck('id')->all(), $actor);
            $record->delete();
        });
    }

    private function deleteCompany(Company $company, string $confirmation, User $actor): void
    {
        $this->authorize($actor, 'companies.delete', $company->id);
        if (strtoupper(trim($confirmation)) !== $company->code) {
            throw ValidationException::withMessages(['confirmCode' => __('Type the company code :code to confirm.', ['code' => $company->code])]);
        }
        DB::transaction(function () use ($company, $actor): void {
            $entryIds = $this->relatedEntries($company)->lockForUpdate()->orderBy('id')->pluck('id')->all();
            if ($entryIds !== []) {
                Gate::forUser($actor)->authorize('entries.purge');
            }
            $this->purgeEntries($entryIds, $actor, true);
            // Bulk deletes skip model events, so the photos of the company's parties and leads go explicitly.
            $photos = Media::query()->where(fn (Builder $media) => $media
                ->where(fn (Builder $q) => $q->where('mediable_type', (new Party)->getMorphClass())->whereIn('mediable_id', Party::query()->where('company_id', $company->id)->select('id')))
                ->orWhere(fn (Builder $q) => $q->where('mediable_type', (new Lead)->getMorphClass())->whereIn('mediable_id', Lead::query()->where('company_id', $company->id)->select('id'))))->get();
            DB::afterCommit(fn () => $photos->each(fn (Media $media) => app(MediaService::class)->detach($media)));
            $this->deleteSalesData($company);
            LeadCall::query()->where('company_id', $company->id)->delete();
            LeadEmail::query()->where('company_id', $company->id)->delete();
            Lead::query()->where('company_id', $company->id)->delete();
            CrmStatus::query()->where('company_id', $company->id)->delete();
            CrmService::query()->where('company_id', $company->id)->delete();
            Party::query()->where('company_id', $company->id)->delete();
            Account::query()->where('company_id', $company->id)->delete();
            $company->users()->detach();
            $company->delete();
        });
        if ((int) session(CompanyContext::SESSION_KEY) === $company->id) {
            app(CompanyContext::class)->select(null);
        }
    }

    /**
     * Deletes an employee the actor manages, with their login sessions, API tokens, files, company
     * assignments and employee parties. Refused while any transaction names them.
     */
    private function deleteUser(User $user, User $actor): void
    {
        Gate::forUser($actor)->authorize('users.delete');
        if ($user->is($actor) || ! ManageableUsers::for($actor)->whereKey($user->id)->exists()) {
            throw new AuthorizationException(__('You cannot delete this employee.'));
        }
        DB::transaction(function () use ($user): void {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($reason = $this->blockedReason($user)) {
                throw ValidationException::withMessages(['record' => $reason]);
            }
            $user->parties()->delete();
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->delete();
        });
        foreach ($user->media()->get() as $media) {
            app(MediaService::class)->detach($media);
        }
    }

    /**
     * Removes a company's Sales module data (documents with their lines, payments and activity, recurring schedules,
     * templates with their logo and signature files, items, numbering and custom fields). Its journal entries are
     * purged before this, so no document still points at one.
     */
    private function deleteSalesData(Company $company): void
    {
        $templates = DocumentTemplate::query()->where('company_id', $company->id)->pluck('id');
        $files = Media::query()->where('mediable_type', (new DocumentTemplate)->getMorphClass())->whereIn('mediable_id', $templates)->get();
        RecurringInvoice::query()->where('company_id', $company->id)->delete();
        // source_id restricts deleting a document that others came from, so the links go first.
        Document::query()->where('company_id', $company->id)->update(['source_id' => null]);
        Document::query()->where('company_id', $company->id)->delete();
        DocumentSequence::query()->where('company_id', $company->id)->delete();
        DocumentTemplate::query()->whereKey($templates)->delete();
        DocumentField::query()->where('company_id', $company->id)->delete();
        Item::query()->where('company_id', $company->id)->delete();
        DB::afterCommit(fn () => $files->each(fn (Media $media) => app(MediaService::class)->detach($media)));
    }

    /** Whether document lines, items or document payments point at the account (their foreign keys would null it). */
    private function usedOnDocuments(Account $account): bool
    {
        return DocumentLine::query()->where('account_id', $account->id)->exists() || Item::query()->where('account_id', $account->id)->exists()
            || DocumentPayment::query()->where('account_id', $account->id)->exists();
    }

    private function hasSalesRecords(User $user): bool
    {
        return Document::query()->where(fn (Builder $documents) => $documents->where('created_by', $user->id)
            ->orWhere('updated_by', $user->id)->orWhere('voided_by', $user->id)
            ->orWhereIn('party_id', $user->parties()->select('id')))->exists()
            || DocumentPayment::query()->where('created_by', $user->id)->exists()
            || RecurringInvoice::query()->where('created_by', $user->id)->exists();
    }

    /** @return Builder<JournalEntry> every transaction, trashed included, that points at the record */
    private function relatedEntries(Account|Party|Company|User $record): Builder
    {
        $query = JournalEntry::withTrashed();

        return match (true) {
            $record instanceof Company => $query->where('company_id', $record->id),
            $record instanceof User => $query->where(fn (Builder $entries) => $entries->whereIn('party_id', $record->parties()->select('id'))
                ->orWhere('created_by', $record->id)->orWhere('updated_by', $record->id)
                ->orWhere('voided_by', $record->id)->orWhere('paid_by', $record->id)),
            $record instanceof Party => $query->where('company_id', $record->company_id)->where('party_id', $record->id),
            default => $query->where('company_id', $record->company_id)->whereHas('lines', fn (Builder $lines) => $lines->where('account_id', $record->id)),
        };
    }

    /** @param list<int> $entryIds in ascending id order */
    private function purgeEntries(array $entryIds, User $actor, bool $withCompany = false): void
    {
        // Settlements first; purging a bill also removes settlements that may be later in the list.
        foreach (array_reverse($entryIds) as $id) {
            $entry = JournalEntry::withTrashed()->find($id);
            if ($entry) {
                $this->ledger->purge($entry, $actor, $withCompany);
            }
        }
    }

    /**
     * Re-asserts the double-entry invariant for entries whose lines or party were reassigned.
     *
     * @param  list<int>  $entryIds
     */
    private function assertBalanced(array $entryIds): void
    {
        if ($entryIds === []) {
            return;
        }
        $unbalanced = DB::table('journal_lines')->whereIn('journal_entry_id', $entryIds)->groupBy('journal_entry_id')
            ->havingRaw('SUM(debit) <> SUM(credit) OR COUNT(*) < 2 OR COUNT(*) > ? OR COUNT(DISTINCT account_id) <> COUNT(*)', [LedgerService::MAX_PAYMENTS + 2])
            ->pluck('journal_entry_id');
        if ($unbalanced->isNotEmpty()) {
            throw new LogicException('Transfer left unbalanced entries: '.$unbalanced->join(', '));
        }
    }

    private function isLastActivePaymentMethod(Account $account): bool
    {
        return $account->isPaymentMethod() && $account->is_active
            && ! Account::query()->where('company_id', $account->company_id)->paymentMethods()->where('is_active', true)->whereKeyNot($account->id)->exists();
    }

    private function refuseBlocked(Account|Party $record): void
    {
        if ($reason = $this->blockedReason($record)) {
            throw ValidationException::withMessages(['record' => $reason]);
        }
    }

    private function ability(Account|Party $record): string
    {
        return $record instanceof Party ? 'parties.delete' : 'accounts.delete';
    }

    private function authorize(User $actor, string $ability, int $companyId): void
    {
        Gate::forUser($actor)->authorize($ability);
        if (! $actor->canAccessCompany($companyId)) {
            throw new AuthorizationException(__('You do not have access to this company.'));
        }
    }
}
