<?php

namespace App\Livewire\Admin\Crm;

use App\Livewire\Concerns\WithFormSheet;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The CRM work queue for the header companies: who to call today, overdue follow-ups, upcoming ones and the newest
 * leads. Users without `crm.leads.all` see only their own leads.
 */
class Dashboard extends Component
{
    use WithFormSheet, WithPagination;

    public const QUEUES = ['today', 'overdue', 'upcoming'];

    public function render(): View
    {
        Gate::authorize('crm.view');
        $context = app(CompanyContext::class);
        if ($context->options()->isEmpty()) {
            return view('livewire.admin.crm.dashboard', ['hasCompanies' => false])->layout('layouts.admin');
        }
        $user = auth()->user();
        $leads = fn (): Builder => Lead::visibleTo($user)->whereIn('leads.company_id', $context->companyIds())
            ->with(['photo', 'company:id,name', 'service:id,name', 'status:id,name,tone,is_closed', 'assignee:id,name', 'latestCall']);
        $queues = collect(self::QUEUES)->mapWithKeys(fn (string $queue): array => [
            $queue => $leads()->followUp($queue)->orderBy('next_call_on')->orderBy('leads.id')->paginate(5, pageName: $queue.'Page'),
        ]);

        return view('livewire.admin.crm.dashboard', [
            'hasCompanies' => true,
            'showCompany' => $context->isAll(),
            'scopeLabel' => $context->isAll() ? __('All companies') : $context->company()->name,
            'queues' => $queues,
            'totalLeads' => $leads()->count(),
            'callsToday' => LeadCall::visibleTo($user)->whereIn('lead_calls.company_id', $context->companyIds())
                ->where('called_at', '>=', today()->toDateTimeString())->count(),
            'recent' => $leads()->orderByDesc('leads.created_at')->orderByDesc('leads.id')->limit(8)->get(),
        ])->layout('layouts.admin');
    }

    protected function sheetRoute(): string
    {
        return 'admin.crm.dashboard';
    }
}
