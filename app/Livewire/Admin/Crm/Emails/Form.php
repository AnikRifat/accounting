<?php

namespace App\Livewire\Admin\Crm\Emails;

use App\Models\Lead;
use App\Services\LeadMailer;
use App\Support\MailConfiguration;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Writes an email to a lead and sends it now through the mail settings. Every attempt lands in the email log; a
 * refused send stays on the form with the mail server's answer so it can be fixed and sent again.
 */
class Form extends Component
{
    #[Locked]
    public int $leadId;

    /** Where to go after sending: the page the sheet was opened from. */
    #[Locked]
    public string $returnTo = '';

    public string $to = '';

    public string $cc = '';

    public string $subject = '';

    public string $message = '';

    public function mount(Lead $lead, ?string $returnTo = null): void
    {
        Gate::authorize('crm.emails.send');
        $lead = Lead::visibleTo(auth()->user())->with('company:id,name')->findOrFail($lead->id);
        $this->leadId = $lead->id;
        $this->to = (string) $lead->email;
        $this->message = __("Dear :name,\n\n\n\nRegards,\n:sender\n:company", [
            'name' => $lead->name ?: __('Sir or Madam'), 'sender' => auth()->user()->name, 'company' => $lead->company->name]);
        $this->returnTo = $returnTo !== null && str_starts_with($returnTo, url('/admin')) ? $returnTo : route('admin.crm.leads.show', $this->leadId);
    }

    public function send(LeadMailer $mailer): Redirector|RedirectResponse|null
    {
        Gate::authorize('crm.emails.send');
        $lead = Lead::visibleTo(auth()->user())->findOrFail($this->leadId);
        try {
            $email = $mailer->send($lead, $this->to, $this->cc, $this->subject, $this->message, auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError(str_starts_with($key, 'cc') ? 'cc' : $key, $messages[0]);
            }

            return null;
        }
        if ($email->failed()) {
            $this->addError('send', __('The email was not sent. The mail server said: :error', ['error' => $email->error]));

            return null;
        }
        session()->flash('success', __('Email sent to :email.', ['email' => $email->to]));

        return redirect()->to($this->returnTo);
    }

    public function render(): View
    {
        return view('livewire.admin.crm.emails.form', [
            'lead' => Lead::query()->with('company:id,name')->findOrFail($this->leadId),
            'delivers' => MailConfiguration::delivers(),
        ])->layout('layouts.admin');
    }
}
