<?php

namespace App\Livewire\Admin\PaymentMethods;

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

/** A payment method is an `is_cash` asset account with a payment type and an automatic code. */
class Form extends Component
{
    #[Locked]
    public ?int $paymentMethodId = null;

    /** The payment method's company, or the header company when creating. */
    #[Locked]
    public ?int $companyId = null;

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
            $this->companyId = $paymentMethod->company_id;
            $this->name = $paymentMethod->name;
            $this->paymentType = $paymentMethod->payment_type?->value ?? PaymentType::Other->value;
            $this->details = $paymentMethod->details ?? '';
            $this->isActive = $paymentMethod->is_active;
        } else {
            $this->companyId = app(CompanyContext::class)->company()?->id;
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
        }
        // The company is settled before any rule runs, so the unique rule never probes a company the user cannot use.
        $company = $existing?->company ?? $this->contextCompany();
        if (! $company) {
            $this->addError('company', __('The company in the header has changed or is inactive. Reload the page and try again.'));

            return null;
        }
        $companyId = $company->id;
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
            DB::transaction(function () use ($existing, $company, $data, $opening, $actor): void {
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
        session()->flash('success', __('Payment method saved.'));

        return redirect()->route('admin.payment-methods.index');
    }

    public function render(): View
    {
        $existing = $this->paymentMethodId ? Account::findOrFail($this->paymentMethodId) : null;

        return view('livewire.admin.payment-methods.form', [
            'openingEntry' => $existing ? $this->openingEntry($existing) : null,
            'companyName' => Company::visibleTo(auth()->user())->whereKey($this->companyId)->value('name'),
            'paymentTypes' => collect(PaymentType::cases())->mapWithKeys(fn (PaymentType $type): array => [$type->value => $type->label()])->all(),
        ])->layout('layouts.admin');
    }

    /** The header company, while it is still the one this page was opened for, visible and active. */
    private function contextCompany(): ?Company
    {
        $company = app(CompanyContext::class)->company();

        return $company?->is_active && $company->id === $this->companyId ? $company : null;
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
