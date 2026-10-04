<?php

namespace Tests\Feature;

use App\Livewire\Admin\Crm\Emails\Form;
use App\Livewire\Admin\Crm\Emails\Index;
use App\Livewire\Admin\Crm\Leads\Index as LeadIndex;
use App\Mail\LeadMail;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadEmail;
use App\Models\User;
use App\Services\RecordDeletion;
use App\Support\CompanyContext;
use App\Support\Configuration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class CrmEmailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-04 11:30:00');
        // SMTP on, at a closed port: a send that isn't faked fails instead of going anywhere.
        app(Configuration::class)->save(['mail.mailer' => 'smtp', 'mail.host' => '127.0.0.1', 'mail.port' => 1, 'mail.encryption' => 'none', 'mail.from_address' => 'crm@example.test']);
    }

    private function userFor(string $role, Company ...$companies): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->companies()->attach(array_map(fn (Company $company): int => $company->id, $companies));

        return $user;
    }

    public function test_emailing_a_lead_sends_it_logs_it_and_leaves_the_lead_alone(): void
    {
        Mail::fake();
        $company = Company::factory()->create(['name' => 'Acme Ltd']);
        $rep = $this->userFor('sales', $company);
        $lead = Lead::factory()->for($company)->create(['name' => 'Rahim', 'email' => 'rahim@example.test', 'assigned_to' => $rep->id, 'next_call_on' => '2026-10-06']);
        $before = [$lead->crm_status_id, '2026-10-06'];
        $this->actingAs($rep);

        Livewire::test(Form::class, ['lead' => $lead])->assertSet('to', 'rahim@example.test')->assertSet('message', fn (string $message): bool => str_starts_with($message, 'Dear Rahim,'))
            ->set('cc', 'boss@example.test; ')->set('subject', ' Your quotation ')->set('message', "Hello,\nPrice attached.")
            ->call('send')->assertHasNoErrors()->assertRedirect(route('admin.crm.leads.show', $lead));

        $email = LeadEmail::sole();
        $this->assertSame([$company->id, $rep->id, 'rahim@example.test', 'boss@example.test', 'Your quotation', LeadEmail::SENT, '2026-10-04 11:30:00'],
            [$email->company_id, $email->user_id, $email->to, $email->cc, $email->subject, $email->status, $email->sent_at->toDateTimeString()]);
        Mail::assertSent(LeadMail::class, fn (LeadMail $mail): bool => $mail->hasTo('rahim@example.test') && $mail->hasCc('boss@example.test')
            && $mail->hasReplyTo($rep->email) && $mail->hasSubject('Your quotation'));
        $this->assertSame($before, [$lead->fresh()->crm_status_id, $lead->fresh()->next_call_on->toDateString()]);

        $this->get(route('admin.crm.leads.show', $lead))->assertOk()->assertSee('Your quotation')->assertSee(route('admin.crm.emails.create', $lead));
    }

    public function test_a_refused_send_is_logged_as_failed_and_kept_on_the_form(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->create(['role' => 'owner']);
        $lead = Lead::factory()->for($company)->create(['email' => 'lead@example.test']);
        $this->actingAs($owner);

        Livewire::test(Form::class, ['lead' => $lead])->set('subject', 'Hello')->call('send')->assertHasErrors('send')->assertNoRedirect();

        $email = LeadEmail::sole();
        $this->assertTrue($email->failed());
        $this->assertNotEmpty($email->error);
    }

    public function test_every_lead_gets_an_email_button_and_the_form_warns_when_mail_only_goes_to_the_log(): void
    {
        Mail::fake();
        $company = Company::factory()->create();
        $rep = $this->userFor('sales', $company);
        $withEmail = Lead::factory()->for($company)->create(['assigned_to' => $rep->id, 'email' => 'a@example.test']);
        $withoutEmail = Lead::factory()->for($company)->create(['assigned_to' => $rep->id, 'email' => null]);
        $this->actingAs($rep);
        session([CompanyContext::SESSION_KEY => $company->id]);

        // Shown whatever Settings > Mail says, also for leads without an address yet.
        app(Configuration::class)->save(['mail.mailer' => '']);
        config(['mail.default' => 'array']); // as the next request boots it: setUp's SMTP no longer applies
        Livewire::test(LeadIndex::class)->assertSee(route('admin.crm.emails.create', $withEmail))->assertSee(route('admin.crm.emails.create', $withoutEmail))
            ->call('openSheet', 'email:'.$withoutEmail->id)->assertSee('Email lead');
        $this->get(route('admin.crm.leads.show', $withoutEmail))->assertOk()->assertSee(route('admin.crm.emails.create', $withoutEmail));

        // The test environment's server mailer only keeps email in memory, so the form says it won't arrive.
        Livewire::test(Form::class, ['lead' => $withEmail])->assertSee('Email is not set up');
        app(Configuration::class)->save(['mail.mailer' => 'smtp']);
        $this->app->forgetInstance('mail.manager');
        Mail::clearResolvedInstance('mail.manager');
        Mail::fake();
        Livewire::test(Form::class, ['lead' => $withEmail])->assertDontSee('Email is not set up');

        $this->actingAs($this->userFor('accountant', $company));
        Livewire::test(LeadIndex::class)->assertDontSee(route('admin.crm.emails.create', $withEmail));
    }

    public function test_sending_needs_the_permission_a_visible_lead_an_active_company_and_valid_addresses(): void
    {
        Mail::fake();
        $company = Company::factory()->create();
        $rep = $this->userFor('sales', $company);
        $mine = Lead::factory()->for($company)->create(['assigned_to' => $rep->id, 'email' => 'mine@example.test']);
        $theirs = Lead::factory()->for($company)->create();
        $this->actingAs($rep);

        $this->get(route('admin.crm.emails.create', $theirs))->assertNotFound();
        Livewire::test(Form::class, ['lead' => $mine])->set('to', 'nope')->set('cc', 'a@example.test, bad')->set('subject', '')->call('send')
            ->assertHasErrors(['to', 'cc', 'subject']);

        $company->update(['is_active' => false]);
        Livewire::test(Form::class, ['lead' => $mine])->set('subject', 'Hi')->call('send')->assertHasErrors('to');

        $this->actingAs($this->userFor('data-entry', $company))->get(route('admin.crm.emails.create', $mine))->assertForbidden();
        $this->assertSame(0, LeadEmail::query()->count());
        Mail::assertNothingSent();
    }

    public function test_the_email_log_shows_what_each_person_may_see_and_filters_by_status(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $rep = $this->userFor('sales', $company);
        $manager = $this->userFor('sales-manager', $company);
        LeadEmail::factory()->for(Lead::factory()->for($company)->state(['assigned_to' => $rep->id]))->for($manager)->create(['subject' => 'To rep lead']);
        LeadEmail::factory()->for(Lead::factory()->for($company))->for($manager)->failed()->create(['subject' => 'Manager only']);
        LeadEmail::factory()->for(Lead::factory()->for($other))->for($manager)->create(['subject' => 'Other company']);

        $this->actingAs($rep)->get(route('admin.crm.emails.index'))->assertOk()->assertSee('To rep lead')->assertDontSee('Manager only')->assertDontSee('Other company');

        $this->actingAs($manager);
        session([CompanyContext::SESSION_KEY => $company->id]);
        Livewire::test(Index::class)->assertSee('To rep lead')->assertSee('Manager only')->assertSee('Connection could not be established')
            ->assertDontSee('Other company')->assertViewHas('summary', ['sent' => 1, 'failed' => 1, 'leads' => 1])
            ->set('status', LeadEmail::FAILED)->assertDontSee('To rep lead')->assertSee('Manager only');
    }

    public function test_deleting_a_lead_removes_its_emails_and_a_sender_cannot_be_deleted(): void
    {
        $company = Company::factory()->create();
        $sender = $this->userFor('sales', $company);
        $email = LeadEmail::factory()->for(Lead::factory()->for($company))->for($sender)->create();
        $this->assertNotNull(app(RecordDeletion::class)->blockedReason($sender));

        $this->actingAs(User::factory()->create(['role' => 'owner']));
        session([CompanyContext::SESSION_KEY => $company->id]);
        Livewire::test(LeadIndex::class)->call('delete', $email->lead_id)->assertHasNoErrors();
        $this->assertSame(0, LeadEmail::query()->count());
    }
}
