<?php

namespace App\Livewire\Admin\Reports;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Index extends Component
{
    public function render(): View
    {
        Gate::authorize('reports.view');
        $cards = [
            'reports.income-statement' => [null, __('Income statement'), __('Income, expenses and net profit or loss for one company or all your companies side by side.')],
            'reports.dues' => [null, __('Dues'), __('What customers owe you and what you owe suppliers, by party, with overdue bills.')],
            'reports.party-statement' => ['parties.view', __('Party statement'), __('Every bill, receipt and payment of one party with a running due.')],
            'reports.employee-cost' => ['employees.view', __('Employee cost'), __('Expenses recorded against each employee, such as salaries, with paid and outstanding amounts.')],
            'reports.account-ledger' => [null, __('Account ledger'), __('Every posted line of one account with opening, running and closing balances.')],
            'reports.trial-balance' => [null, __('Trial balance'), __('Debit and credit balances of every account on a date, with a balance check.')],
        ];

        return view('livewire.admin.reports.index', [
            'reports' => array_filter($cards, fn (array $card): bool => $card[0] === null || Gate::allows($card[0])),
            'advanced' => Gate::allows('accounts.view')
                ? ['accounts.index' => [null, __('Chart of accounts'), __('Every account of a company, including system accounts, with its current balance.')]] : [],
        ])->layout('layouts.admin');
    }
}
