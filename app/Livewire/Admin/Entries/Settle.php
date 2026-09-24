<?php

namespace App\Livewire\Admin\Entries;

use App\Enums\EntryType;
use App\Enums\PaymentType;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\LedgerService;
use App\Support\Money;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

/** "Receive payment" / "Make payment" against a bill's outstanding balance, or edit such a settlement. */
class Settle extends Component
{
    #[Locked]
    public int $billId;

    #[Locked]
    public ?int $settlementId = null;

    public string $entryDate = '';

    public string $amount = '';

    public string $paymentAccountId = '';

    public string $reference = '';

    public string $description = '';

    public function mount(JournalEntry $entry): void
    {
        abort_unless(auth()->user()->canAccessCompany($entry->company_id), 404);
        abort_if($entry->isVoided(), 403, __('Voided entries cannot be changed.'));
        if ($entry->type->isSettlement()) {
            Gate::authorize('entries.update');
            $entry->load('lines.account');
            $this->settlementId = $entry->id;
            $this->billId = $entry->bill_id;
            $this->entryDate = $entry->entry_date->toDateString();
            $this->amount = Money::toInput($entry->amount);
            $this->paymentAccountId = (string) $entry->paymentAccount()?->id;
            $this->reference = (string) $entry->reference;
            $this->description = (string) $entry->description;

            return;
        }
        Gate::authorize('entries.create');
        abort_unless($entry->type->isBill(), 404);
        $outstanding = app(LedgerService::class)->outstanding($entry);
        abort_if($outstanding <= 0, 403, __('Nothing is outstanding on this entry.'));
        $this->billId = $entry->id;
        $this->entryDate = max(today()->toDateString(), $entry->entry_date->toDateString());
        $this->amount = Money::toInput($outstanding);
        $this->paymentAccountId = (string) Account::query()->where('company_id', $entry->company_id)->paymentMethods()->where('is_active', true)
            ->orderByRaw('CASE WHEN payment_type = ? THEN 0 ELSE 1 END', [PaymentType::Cash->value])->orderBy('code')->value('id');
    }

    public function save(): Redirector|RedirectResponse|null
    {
        Gate::authorize($this->settlementId ? 'entries.update' : 'entries.create');
        $user = auth()->user();
        $bill = JournalEntry::visibleTo($user)->findOrFail($this->billId);
        $settlement = $this->settlementId ? JournalEntry::visibleTo($user)->findOrFail($this->settlementId) : null;
        $this->validate([
            'entryDate' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! Money::isValidInput((string) $value) || Money::toPaisa((string) $value) === 0) {
                    $fail(__('Enter an amount in taka greater than zero, for example 1,25,000.50.'));
                }
            }],
            'paymentAccountId' => ['required', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ], [], ['entryDate' => __('date'), 'amount' => __('amount'), 'paymentAccountId' => __('payment method'),
            'reference' => __('reference'), 'description' => __('description')]);
        $data = ['entry_date' => $this->entryDate, 'amount' => Money::toPaisa($this->amount), 'payment_account_id' => (int) $this->paymentAccountId,
            'reference' => trim($this->reference), 'description' => trim($this->description)];
        $ledger = app(LedgerService::class);
        try {
            $saved = $settlement ? $ledger->update($settlement, $data, $user) : $ledger->settle($bill, $data, $user);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError(Str::camel($key), $messages[0]);
            }

            return null;
        }
        session()->flash('success', __('Entry :number saved.', ['number' => $saved->number]));

        return redirect()->route('admin.entries.index');
    }

    public function render(): View
    {
        $bill = JournalEntry::visibleTo(auth()->user())->withOutstanding()->with(['party:id,name', 'company:id,name,code'])->findOrFail($this->billId);
        $current = $this->settlementId ? (int) JournalEntry::query()->whereKey($this->settlementId)->value('amount') : 0;
        $methods = Account::query()->where('company_id', $bill->company_id)->paymentMethods()
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhere('id', (int) $this->paymentAccountId))->orderBy('code')->get();
        $receiving = $bill->type === EntryType::Income;

        return view('livewire.admin.entries.settle', [
            'bill' => $bill,
            'available' => $bill->outstanding + $current,
            'methods' => ['' => __('Select a payment method')] + $methods->mapWithKeys(fn (Account $account): array => [$account->id => $account->name])->all(),
            'title' => match (true) {
                $this->settlementId !== null => $receiving ? __('Edit receipt') : __('Edit payment'),
                default => $receiving ? __('Receive payment') : __('Make payment'),
            },
        ])->layout('layouts.admin');
    }
}
