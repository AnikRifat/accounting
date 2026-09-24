<?php

namespace App\Livewire\Admin\Accounts;

use App\Enums\AccountType;
use App\Enums\EntryType;
use App\Enums\PaymentType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use App\Support\Money;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class Form extends Component
{
    #[Locked]
    public ?int $accountId = null;

    #[Locked]
    public bool $hasEntries = false;

    /** The account's company: from the header context when creating (re-checked on save), from the record when editing. */
    #[Locked]
    public ?int $companyId = null;

    public string $code = '';

    public string $name = '';

    public string $type = 'expense';

    public bool $isCash = false;

    public string $paymentType = 'cash';

    public string $details = '';

    public bool $isActive = true;

    public string $openingBalance = '';

    public string $openingDate = '';

    public function mount(?Account $account = null): void
    {
        Gate::authorize('accounts.manage');
        if ($account?->exists) {
            $this->guard($account);
            $this->accountId = $account->id;
            $this->hasEntries = $account->lines()->exists();
            $this->companyId = $account->company_id;
            $this->code = $account->code;
            $this->name = $account->name;
            $this->type = $account->type->value;
            $this->isCash = $account->is_cash;
            $this->paymentType = $account->payment_type->value ?? PaymentType::Cash->value;
            $this->details = (string) $account->details;
            $this->isActive = $account->is_active;
        } else {
            $company = app(CompanyContext::class)->company();
            abort_unless($company?->is_active, 404);
            $this->companyId = $company->id;
        }
        $this->openingDate = today()->toDateString();
    }

    public function save(): Redirector|RedirectResponse|null
    {
        Gate::authorize('accounts.manage');
        $user = auth()->user();
        $existing = $this->accountId ? Account::findOrFail($this->accountId) : null;
        if ($existing) {
            $this->guard($existing);
        } elseif (app(CompanyContext::class)->selectedId() !== $this->companyId
            || ! Company::visibleTo($user)->where('is_active', true)->whereKey($this->companyId)->exists()) {
            // Checked before any unique rule runs, so those rules only ever query a company the user may use.
            $this->addError('companyId', __('The company in the header has changed since this page opened, or is no longer active. Reload the page to continue.'));

            return null;
        }
        $companyId = $existing ? $existing->company_id : $this->companyId;
        $this->code = trim($this->code);
        $this->name = trim($this->name);
        $data = $this->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('accounts', 'code')->where('company_id', $companyId)->ignore($this->accountId)],
            'name' => ['required', 'string', 'max:150', Rule::unique('accounts', 'name')->where('company_id', $companyId)->ignore($this->accountId)],
            'type' => ['required', Rule::enum(AccountType::class)],
            'isCash' => ['boolean'],
            'paymentType' => ['required', Rule::enum(PaymentType::class)],
            'details' => ['nullable', 'string', 'max:255'],
            'isActive' => ['boolean'],
            'openingBalance' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! Money::isValidInput((string) $value)) {
                    $fail(__('Enter an amount in taka, for example 1,25,000.50.'));
                }
            }],
            'openingDate' => ['nullable', 'required_with:openingBalance', 'date_format:Y-m-d'],
        ], [], [
            'code' => __('code'), 'name' => __('name'), 'type' => __('type'), 'paymentType' => __('payment type'), 'details' => __('details'),
            'openingBalance' => __('opening balance'), 'openingDate' => __('opening balance date'),
        ]);
        $type = AccountType::from($data['type']);
        $isCash = $type === AccountType::Asset && $data['isCash'];
        if ($existing && $existing->lines()->exists() && ($type !== $existing->type || $isCash !== $existing->is_cash)) {
            $this->addError('type', __('The type of an account that already has entries cannot be changed.'));

            return null;
        }
        $opening = ($data['openingBalance'] ?? '') !== '' ? Money::toPaisa($data['openingBalance']) : 0;
        if ($opening > 0 && ! $isCash) {
            $this->addError('openingBalance', __('Opening balances can only be set for cash or bank accounts.'));

            return null;
        }
        if ($opening > 0 && $existing && $this->openingEntry($existing) !== null) {
            $this->addError('openingBalance', __('This account already has an opening balance. Edit that entry instead.'));

            return null;
        }

        try {
            DB::transaction(function () use ($existing, $companyId, $data, $type, $isCash, $opening, $user): void {
                $account = $existing ?? Company::findOrFail($companyId)->accounts()->make();
                $account->fill(['code' => $data['code'], 'name' => $data['name'], 'type' => $type, 'is_cash' => $isCash,
                    'payment_type' => $isCash ? PaymentType::from($data['paymentType']) : null, 'details' => trim((string) $data['details']) ?: null, 'is_active' => $data['isActive']])->save();
                if ($opening > 0) {
                    app(LedgerService::class)->recordOpening($account, $opening, $data['openingDate'], $user);
                }
            });
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $this->addError(isset($errors['entry_date']) ? 'openingDate' : 'openingBalance', collect($errors)->flatten()->first());

            return null;
        }
        session()->flash('success', __('Account saved.'));

        return redirect()->route('admin.accounts.index');
    }

    public function render(): View
    {
        $existing = $this->accountId ? Account::findOrFail($this->accountId) : null;

        return view('livewire.admin.accounts.form', [
            'openingEntry' => $existing ? $this->openingEntry($existing) : null,
            'companyName' => Company::query()->whereKey($this->companyId)->value('name'),
            'paymentTypes' => collect(PaymentType::cases())->mapWithKeys(fn (PaymentType $type): array => [$type->value => $type->label()])->all(),
            'types' => collect(AccountType::cases())->mapWithKeys(fn (AccountType $type): array => [$type->value => $type->label()])->all(),
        ])->layout('layouts.admin');
    }

    /** The posted opening entry of a cash account, if one was recorded. */
    private function openingEntry(Account $account): ?JournalEntry
    {
        return JournalEntry::query()->posted()->where('type', EntryType::Opening)
            ->whereHas('lines', fn (Builder $lines) => $lines->where('account_id', $account->id)->where('debit', '>', 0))->latest('id')->first();
    }

    /** Accounts of invisible companies do not exist for this user; system accounts are read-only. */
    private function guard(Account $account): void
    {
        abort_unless(auth()->user()->canAccessCompany($account->company_id), 404);
        abort_if($account->is_system, 403, __('System accounts cannot be edited.'));
    }
}
