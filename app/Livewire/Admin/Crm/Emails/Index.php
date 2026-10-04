<?php

namespace App\Livewire\Admin\Crm\Emails;

use App\Livewire\Concerns\WithTableTools;
use App\Models\LeadEmail;
use App\Support\CompanyContext;
use App\Support\Configuration;
use App\Support\Crm;
use App\Support\TableExport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Every email sent to the leads of the header companies the user may see, newest first, with failed sends and why. */
class Index extends Component
{
    use WithPagination, WithTableTools;

    #[Url(except: '')]
    public string $search = '';

    /** sent or failed */
    #[Url(except: '')]
    public string $status = '';

    /** Who sent the email. */
    #[Url(as: 'by', except: '')]
    public string $sender = '';

    /** Who the lead is assigned to now. */
    #[Url(except: '')]
    public string $assignee = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'sender', 'assignee', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        Gate::authorize('crm.view');
        $companyIds = app(CompanyContext::class)->companyIds();
        $query = $this->tableQuery();

        return view('livewire.admin.crm.emails.index', [
            'showCompany' => app(CompanyContext::class)->isAll(),
            'seesAll' => Gate::allows('crm.leads.all'),
            'summary' => ['sent' => (clone $query)->where('status', LeadEmail::SENT)->count(), 'failed' => (clone $query)->where('status', LeadEmail::FAILED)->count(),
                'leads' => (clone $query)->where('status', LeadEmail::SENT)->reorder()->distinct()->count('lead_emails.lead_id')],
            'emails' => $query->paginate(Configuration::get('general.rows_per_page')),
            'statuses' => [LeadEmail::SENT => __('Sent'), LeadEmail::FAILED => __('Failed')],
            'people' => Gate::allows('crm.leads.all') ? Crm::peopleOptions($companyIds) : [],
            'active' => ($this->status !== '') + ($this->sender !== '') + ($this->assignee !== '') + ($this->from !== '' || $this->to !== ''),
        ])->layout('layouts.admin');
    }

    /** @return Builder<LeadEmail> the visible emails of the header companies, filtered like the list */
    protected function tableQuery(): Builder
    {
        $search = mb_substr(trim($this->search), 0, 100);

        return LeadEmail::visibleTo(auth()->user())->whereIn('lead_emails.company_id', app(CompanyContext::class)->companyIds())
            ->with(['company:id,name', 'lead:id,name,phone,assigned_to', 'lead.assignee:id,name', 'user:id,name'])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('subject', 'like', '%'.$search.'%')
                ->orWhere('to', 'like', '%'.$search.'%')->orWhereHas('lead', fn (Builder $lead) => $lead->where('name', 'like', '%'.$search.'%'))))
            ->when(in_array($this->status, [LeadEmail::SENT, LeadEmail::FAILED], true), fn (Builder $query) => $query->where('status', $this->status))
            ->when(ctype_digit($this->sender), fn (Builder $query) => $query->where('lead_emails.user_id', (int) $this->sender))
            ->when(ctype_digit($this->assignee), fn (Builder $query) => $query->whereHas('lead', fn (Builder $q) => $q->where('assigned_to', (int) $this->assignee)))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from), fn (Builder $query) => $query->where('sent_at', '>=', $this->from.' 00:00:00'))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->to), fn (Builder $query) => $query->where('sent_at', '<=', $this->to.' 23:59:59'))
            ->orderByDesc('sent_at')->orderByDesc('lead_emails.id');
    }

    protected function tableExport(): TableExport
    {
        return new TableExport(__('Email log'), [
            'sent_at' => ['label' => __('When'), 'value' => fn (LeadEmail $email): string => $email->sent_at->format('Y-m-d H:i')],
            'lead' => ['label' => __('Lead'), 'value' => fn (LeadEmail $email): string => $email->lead->displayName()],
            'to' => ['label' => __('To'), 'value' => fn (LeadEmail $email): string => $email->to],
            'cc' => ['label' => __('Cc'), 'value' => fn (LeadEmail $email): ?string => $email->cc],
            'company' => ['label' => __('Company'), 'value' => fn (LeadEmail $email): string => $email->company->name],
            'subject' => ['label' => __('Subject'), 'value' => fn (LeadEmail $email): string => $email->subject],
            'message' => ['label' => __('Message'), 'value' => fn (LeadEmail $email): string => $email->message],
            'status' => ['label' => __('Status'), 'value' => fn (LeadEmail $email): string => $email->failed() ? __('Failed') : __('Sent')],
            'error' => ['label' => __('Error'), 'value' => fn (LeadEmail $email): ?string => $email->error],
            'by' => ['label' => __('Sent by'), 'value' => fn (LeadEmail $email): string => $email->user->name],
            'assignee' => ['label' => __('Assigned to'), 'value' => fn (LeadEmail $email): ?string => $email->lead->assignee?->name],
        ], $this->companyScopeLabel());
    }
}
