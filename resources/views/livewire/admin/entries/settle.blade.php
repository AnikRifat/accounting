<div class="page">
    <x-notices />
    <x-page-header :title="$title" :description="__(':number · :party · :company', ['number' => $bill->number, 'party' => $bill->party?->name ?? __('No party'), 'company' => $bill->company->name])" :back="route('admin.entries.index')" :back-label="__('Transactions')" />
    <div class="stats">
        <x-stat :label="__('Total')" emoji="🧾"><x-slot:value><x-money :value="$bill->amount" /></x-slot:value></x-stat>
        <x-stat :label="__('Outstanding')" emoji="⏳" tone="warning"><x-slot:value><x-money :value="$bill->outstanding" /></x-slot:value></x-stat>
        <x-stat :label="__('Due date')" emoji="📅" :tone="$bill->dueStatus() === \App\Enums\DueStatus::Overdue ? 'danger' : null" :value="$bill->due_date?->format('d M Y') ?? '—'" />
    </div>
    <form wire:submit="save" class="stack">
        @error('entry')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
        @error('companyId')<x-alert tone="danger">{{ $message }}</x-alert>@enderror
        <x-card :title="__('Payment details')">
            <div class="form-grid">
                <x-form.input name="entryDate" :label="__('Date')" type="date" wire:model="entryDate" required />
                <x-form.input name="amount" :label="__('Amount (৳)')" wire:model="amount" required inputmode="decimal" autocomplete="off" autofocus :help="__('Up to :amount.', ['amount' => \App\Support\Money::format($available)])" />
                <x-form.select name="paymentAccountId" :label="__('Payment method')" wire:model="paymentAccountId" :options="$methods" />
                <x-form.input name="reference" :label="__('Reference')" wire:model="reference" maxlength="100" :help="__('Voucher, invoice or cheque number.')" />
                <div class="span-full"><x-form.input name="description" :label="__('Description')" wire:model="description" maxlength="500" /></div>
            </div>
        </x-card>
        <x-form.actions :submit="$title" :cancel="route('admin.entries.index')" />
    </form>
</div>
