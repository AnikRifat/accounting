<?php

namespace App\Livewire\Admin\PaymentMethods;

use App\Enums\AccountType;
use App\Enums\EntryType;
use App\Enums\PaymentType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\LedgerService;
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

/** A payment method is an `is_cash` asset account with a payment type and an automatic code. */
class Form extends Component
{
    #[Locked]
    public ?int $paymentMethodId = null;

    public string $companyId = '';

    public string $name = '';

    public string $paymentType = 'cash';

    public string $details = '';

    public bool $isActive = true;

    public string $openingBalance = '';

    public string $openingDate = '';

    public function mount(?Account $paymentMethod = null): void
    {
        Gate::authorize('accounts.manage');
        if ($paymentMethod?->exists) {
            $this->guard($paymentMethod);
            $this->paymentMethodId = $paymentMethod->id;
            $this->companyId = (string) $paymentMethod->company_id;
            $this->name = $paymentMethod->name;
            $this->paymentType = $paymentMethod->payment_type?->value ?? PaymentType::Other->value;
            $this->details = $paymentMethod->details ?? '';
            $this->isActive = $paymentMethod->is_active;
        } else {
            $companyIds = $this->activeCompanies()->pluck('id')->all();
            $remembered = (int) session('ledger.company_id');
            $this->companyId = (string) (in_array($remembered, $companyIds, true) ? $remembered : ($companyIds[0] ?? ''));
        }
        $this->openingDate = today()->toDateString();
    }

    public function save(): Redirector|RedirectResponse|null
    {
        Gate::authorize('accounts.manage');
        $actor = auth()->user();
        $existing = $this->paymentMethodId ? Account::findOrFail($this->paymentMethodId) : null;
        if ($existing) {
            $this->guard($existing);
            $this->companyId = (string) $existing->company_id;
        }
        // Validate the company alone first, so the unique rule below never runs against a company the user cannot use.
        $this->validate(['companyId' => ['required', Rule::in($existing ? [$existing->company_id] : $this->activeCompanies()->pluck('id')->all())]], [], ['companyId' => __('company')]);
        $companyId = (int) $this->companyId;
        foreach (['name', 'details', 'openingBalance', 'openingDate'] as $field) {
            $this->{$field} = trim($this->{$field});
        }
        $data = $this->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('accounts', 'name')->where('company_id', $companyId)->ignore($this->paymentMethodId)],
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
            'name' => __('name'), 'paymentType' => __('payment type'), 'details' => __('details'),
            'openingBalance' => __('opening balance'), 'openingDate' => __('opening balance date'),
        ]);
        $opening = ($data['openingBalance'] ?? '') !== '' ? Money::toPaisa($data['openingBalance']) : 0;
        if ($opening > 0 && $existing && $this->openingEntry($existing) !== null) {
            $this->addError('openingBalance', __('This payment method already has an opening balance. Edit that entry instead.'));

            return null;
        }

        try {
            DB::transaction(function () use ($existing, $companyId, $data, $opening, $actor): void {
                $company = Company::findOrFail($companyId);
                $account = $existing ?? $company->accounts()->make(['code' => app(LedgerService::class)->nextCode($company, LedgerService::PAYMENT_METHOD)]);
                // Type and is_cash never change here, so a payment method that is already used stays one.
                $account->fill(['name' => $data['name'], 'type' => AccountType::Asset, 'is_cash' => true, 'payment_type' => PaymentType::from($data['paymentType']),
                    'details' => $data['details'] ?: null, 'is_active' => $data['isActive']])->save();
                if ($opening > 0) {
                    app(LedgerService::class)->recordOpening($account, $opening, $data['openingDate'], $actor);
                }
            });
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            // A full code range is reported on the name; posting errors on the opening balance fields.
            $field = isset($errors['code']) ? 'name' : (isset($errors['entry_date']) ? 'openingDate' : 'openingBalance');
            $this->addError($field, collect($errors)->flatten()->first());

            return null;
        }
        session(['ledger.company_id' => $companyId]);
        session()->flash('success', __('Payment method saved.'));

        return redirect()->route('admin.payment-methods.index');
    }

    public function render(): View
    {
        $existing = $this->paymentMethodId ? Account::findOrFail($this->paymentMethodId) : null;
        $companies = $existing ? Company::visibleTo(auth()->user()) : $this->activeCompanies();

        return view('livewire.admin.payment-methods.form', [
            'openingEntry' => $existing ? $this->openingEntry($existing) : null,
            'companies' => ['' => __('Select a company')] + $companies->orderBy('name')->get(['id', 'name', 'code'])
                ->mapWithKeys(fn (Company $company): array => [$company->id => $company->name.' ('.$company->code.')'])->all(),
            'paymentTypes' => collect(PaymentType::cases())->mapWithKeys(fn (PaymentType $type): array => [$type->value => $type->label()])->all(),
        ])->layout('layouts.admin');
    }

    /** New payment methods can only be added to active companies the user can access. */
    private function activeCompanies(): Builder
    {
        return Company::visibleTo(auth()->user())->where('is_active', true);
    }

    /** The posted opening entry of the payment method, if one was recorded. */
    private function openingEntry(Account $account): ?JournalEntry
    {
        return JournalEntry::query()->posted()->where('type', EntryType::Opening)
            ->whereHas('lines', fn (Builder $lines) => $lines->where('account_id', $account->id)->where('debit', '>', 0))->latest('id')->first();
    }

    /** Only payment methods of accessible companies exist on this screen. */
    private function guard(Account $account): void
    {
        abort_unless(auth()->user()->canAccessCompany($account->company_id) && $account->isPaymentMethod() && ! $account->is_system, 404);
    }
}
