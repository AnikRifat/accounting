<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">{{ __('Accounting') }}</p><h1>{{ $title }}</h1><p class="muted">{{ __(':number · :party · :company', ['number' => $bill->number, 'party' => $bill->party?->name ?? __('No party'), 'company' => $bill->company->name]) }}</p></div></div>
    <div class="stats">
        <div class="panel"><p class="muted">{{ __('Total') }}</p><p class="stat-value text-2xl tabular-nums">{{ \App\Support\Money::format($bill->amount) }}</p></div>
        <div class="panel"><p class="muted">{{ __('Outstanding') }}</p><p class="stat-value text-2xl tabular-nums">{{ \App\Support\Money::format($bill->outstanding) }}</p></div>
        <div class="panel"><p class="muted">{{ __('Due date') }}</p><p class="stat-value text-2xl">{{ $bill->due_date?->format('d M Y') ?? '—' }}</p></div>
    </div>
    <form wire:submit="save" class="stack">
        @error('entry')<p class="error" role="alert">{{ $message }}</p>@enderror
        @error('companyId')<p class="error" role="alert">{{ $message }}</p>@enderror
        <div class="panel"><div class="form-grid">
            <x-form.input name="entryDate" :label="__('Date')" type="date" wire:model="entryDate" required />
            <x-form.input name="amount" :label="__('Amount (৳)')" wire:model="amount" required inputmode="decimal" autocomplete="off" autofocus :help="__('Up to :amount.', ['amount' => \App\Support\Money::format($available)])" />
            <x-form.select name="paymentAccountId" :label="__('Payment method')" wire:model="paymentAccountId" :options="$methods" />
            <x-form.input name="reference" :label="__('Reference')" wire:model="reference" maxlength="100" :help="__('Voucher, invoice or cheque number.')" />
            <x-form.input name="description" :label="__('Description')" wire:model="description" maxlength="500" />
        </div></div>
        <div class="actions">
            <button class="btn" type="submit" wire:loading.attr="disabled">{{ $title }}</button>
            <a class="btn btn-secondary" href="{{ route('admin.entries.index') }}" wire:navigate>{{ __('Cancel') }}</a><span class="muted" wire:loading>{{ __('Saving…') }}</span>
        </div>
    </form>
</div>
