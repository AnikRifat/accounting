<?php

namespace App\Livewire\Admin\Sales\Items;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Support\CompanyContext;
use App\Support\DocumentMath;
use App\Support\Money;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

/** Adds or edits a catalogue item: price in taka (stored as paisa), VAT in percent (stored as basis points). */
class Form extends Component
{
    #[Locked]
    public ?int $itemId = null;

    /** The item's own company, or the header company when adding. */
    #[Locked]
    public ?int $companyId = null;

    public string $name = '';

    public string $description = '';

    public string $unit = '';

    public string $price = '';

    public string $taxRate = '0';

    public string $accountId = '';

    public bool $isActive = true;

    public function mount(?Item $item = null): void
    {
        Gate::authorize('sales.setup');
        $this->itemId = $item?->exists ? $item->id : null;
        if ($this->itemId) {
            abort_unless(auth()->user()->canAccessCompany($item->company_id), 404);
            $this->companyId = $item->company_id;
            $this->name = $item->name;
            $this->description = (string) $item->description;
            $this->unit = (string) $item->unit;
            $this->price = Money::toInput($item->price);
            $this->taxRate = DocumentMath::formatBasisPoints($item->tax_rate);
            $this->accountId = (string) $item->account_id;
            $this->isActive = $item->is_active;
        } else {
            $this->companyId = app(CompanyContext::class)->company()?->id;
        }
    }

    public function save(): Redirector|RedirectResponse|null
    {
        Gate::authorize('sales.setup');
        $existing = $this->itemId ? Item::visibleTo(auth()->user())->findOrFail($this->itemId) : null;
        $company = $existing?->company ?? $this->contextCompany();
        if (! $company) {
            $this->addError('company', __('The company in the header has changed or is inactive. Reload the page and try again.'));

            return null;
        }
        [$this->name, $this->description, $this->unit, $this->price, $this->taxRate] = [trim($this->name), trim($this->description), trim($this->unit), trim($this->price), trim($this->taxRate)];
        $data = $this->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('items', 'name')->where('company_id', $company->id)->ignore($existing?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'unit' => ['nullable', 'string', 'max:20'],
            'price' => ['required', 'string', function (string $attribute, string $value, Closure $fail): void {
                if (! Money::isValidInput($value)) {
                    $fail(__('Enter a price in taka, such as 1500 or 1,250.50.'));
                }
            }],
            'taxRate' => ['required', 'string', function (string $attribute, string $value, Closure $fail): void {
                try {
                    DocumentMath::toBasisPoints($value);
                } catch (InvalidArgumentException) {
                    $fail(__('Enter VAT as a percentage from 0 to 100, such as 15 or 7.5.'));
                }
            }],
            'accountId' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($company): void {
                if (! Account::query()->whereKey((int) $value)->where('company_id', $company->id)->categories(AccountType::Income)->exists()) {
                    $fail(__('Choose an income category of this company.'));
                }
            }],
            'isActive' => ['boolean'],
        ], [],
            ['name' => __('name'), 'price' => __('price'), 'taxRate' => __('VAT'), 'accountId' => __('income category')]);

        $attributes = ['name' => $data['name'], 'description' => $data['description'] !== '' ? $data['description'] : null,
            'unit' => $data['unit'] !== '' ? $data['unit'] : null, 'price' => Money::toPaisa($data['price']),
            'tax_rate' => DocumentMath::toBasisPoints($data['taxRate']), 'account_id' => $data['accountId'] !== '' && $data['accountId'] !== null ? (int) $data['accountId'] : null,
            'is_active' => $data['isActive']];
        $existing ? $existing->update($attributes) : Item::create(['company_id' => $company->id, ...$attributes]);
        session()->flash('success', __('Item saved.'));

        return redirect()->route('admin.sales.items.index');
    }

    public function render(): View
    {
        return view('livewire.admin.sales.items.form', [
            'companyName' => Company::visibleTo(auth()->user())->whereKey($this->companyId)->value('name'),
            'categories' => ['' => __('No category (choose on each line)')] + Account::query()->where('company_id', $this->companyId)
                ->categories(AccountType::Income)->where(fn ($query) => $query->where('is_active', true)->orWhere('id', (int) $this->accountId))
                ->orderBy('code')->pluck('name', 'id')->all(),
        ])->layout('layouts.admin');
    }

    /** The header company, while it is still the one this page was opened for, visible and active. */
    private function contextCompany(): ?Company
    {
        $company = app(CompanyContext::class)->company();

        return $company?->is_active && $company->id === $this->companyId ? $company : null;
    }
}
