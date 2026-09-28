<?php

namespace App\Livewire\Admin\Crm\Reports;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Index extends Component
{
    public function render(): View
    {
        Gate::authorize('crm.reports.view');

        return view('livewire.admin.crm.reports.index', [
            'reports' => [
                'crm.reports.pipeline' => ['🧭', __('Lead pipeline'), __('Leads by service and current status: where every service stands.')],
                'crm.reports.sources' => ['📣', __('Lead sources'), __('Leads per source, how many were contacted and how they closed.')],
                'crm.reports.calls' => ['📞', __('Call outcomes'), __('Calls and visits per person, split by call result.')],
                'crm.reports.performance' => ['🏆', __('Team performance'), __('Leads assigned to each person by status, overdue follow-ups, calls and visits.')],
            ],
            'lists' => [
                'crm.leads.index' => ['🧲', __('Lead list'), __('Every lead with filters by status, service, follow-up, person and date. Export to Excel or print.')],
                'crm.calls.index' => ['🗂️', __('Call log'), __('Every call and visit with filters by result, status, person and date. Export to Excel or print.')],
            ],
        ])->layout('layouts.admin');
    }
}
