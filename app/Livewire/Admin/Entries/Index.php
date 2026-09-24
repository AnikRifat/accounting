<?php

namespace App\Livewire\Admin\Entries;

use App\Enums\DueStatus;
use App\Enums\EntryType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    private const FILTERS = ['from', 'to', 'type', 'account', 'party', 'status', 'search'];

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
            'account' => $this->account, 'party' => $this->party, 'status' => $this->status, 'search' => mb_substr($this->search, 0, 100)], fn (string $value): bool => $value !== '');
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

    public function render(): View
    {
        Gate::authorize('entries.view');
        $user = auth()->user();
        $context = app(CompanyContext::class);
        $companyIds = $context->companyIds();
        $codes = $context->isAll() ? Company::query()->whereKey($companyIds)->pluck('code', 'id') : collect();
        $suffix = fn (int $companyId): string => $codes->has($companyId) ? ' ('.$codes[$companyId].')' : '';
        $query = JournalEntry::visibleTo($user)->whereIn('company_id', $companyIds)->filter($this->filters());
        $totals = (clone $query)->posted()->whereIn('type', [EntryType::Income, EntryType::Expense])
            ->toBase()->groupBy('type')->selectRaw('type, SUM(amount) as total')->pluck('total', 'type');

        return view('livewire.admin.entries.index', [
            'entries' => $query->withOutstanding()->with(['company:id,name,code', 'lines.account:id,code,name,type,is_cash,is_system', 'party:id,name', 'bill:id,number'])
                ->orderByDesc('entry_date')->orderByDesc('id')->paginate(25),
            'income' => (int) ($totals[EntryType::Income->value] ?? 0),
            'expense' => (int) ($totals[EntryType::Expense->value] ?? 0),
            'types' => ['' => __('All types')] + collect(EntryType::cases())->mapWithKeys(fn (EntryType $type): array => [$type->value => $type->label()])->all(),
            'accounts' => ['' => __('All accounts')] + Account::query()->whereIn('company_id', $companyIds)->orderBy('code')->orderBy('company_id')
                ->get(['id', 'code', 'name', 'company_id'])->mapWithKeys(fn (Account $account): array => [$account->id => $account->label().$suffix($account->company_id)])->all(),
            'parties' => ['' => __('All parties')] + Party::query()->whereIn('company_id', $companyIds)->orderBy('name')
                ->get(['id', 'name', 'company_id'])->mapWithKeys(fn (Party $party): array => [$party->id => $party->name.$suffix($party->company_id)])->all(),
            'statuses' => ['' => __('Any status')] + collect(DueStatus::cases())->mapWithKeys(fn (DueStatus $status): array => [$status->value => $status->label()])->all(),
            'showCompany' => $context->isAll(),
        ])->layout('layouts.admin');
    }
}
