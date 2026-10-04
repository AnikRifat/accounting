<?php

namespace Tests\Feature;

use App\Livewire\Admin\Settings;
use App\Mail\TestMail;
use App\Models\ApplicationSetting;
use App\Models\User;
use App\Support\Configuration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'owner', 'email' => 'owner@example.test']));
    }

    /** @param array<string, string> $values */
    private function saveSmtp(array $values = []): void
    {
        $component = Livewire::test(Settings::class)->set('tab', 'mail')->set('values.mail.mailer', 'smtp');
        foreach ([...['host' => 'mail.example.test', 'port' => '465', 'encryption' => 'ssl', 'username' => 'billing@example.test',
            'password' => 'S3cret pass ', 'from_address' => 'billing@example.test', 'from_name' => 'Frish Billing'], ...$values] as $key => $value) {
            $component->set('values.mail.'.$key, $value);
        }
        $component->call('save')->assertHasNoErrors();
    }

    /** Builds the mail manager again, as the next request would. */
    private function freshMailer(): void
    {
        $this->app->forgetInstance('mail.manager');
        Mail::clearResolvedInstance('mail.manager');
        $this->app->forgetScopedInstances();
        app('mail.manager');
    }

    public function test_smtp_needs_its_server_and_the_password_is_stored_encrypted_and_never_sent_back(): void
    {
        Livewire::test(Settings::class)->set('tab', 'mail')->assertSee('Send email through')->set('values.mail.mailer', 'smtp')
            ->set('values.mail.host', '')->set('values.mail.port', '')->set('values.mail.from_address', 'not-an-email')->call('save')
            ->assertHasErrors(['values.mail.host', 'values.mail.port', 'values.mail.from_address']);

        $this->saveSmtp();
        $this->assertSame('S3cret pass ', Configuration::get('mail.password'));
        $stored = ApplicationSetting::query()->where('key', 'mail.password')->value('value');
        $this->assertNotSame('S3cret pass ', $stored);
        $this->assertStringNotContainsString('S3cret', $stored);

        // The form starts empty and an empty password keeps the saved one.
        Livewire::test(Settings::class)->set('tab', 'mail')->assertSet('values.mail.password', '')->assertDontSee('S3cret')
            ->assertSee('A password is saved. Leave empty to keep it.')->set('values.mail.host', 'smtp.example.test')->call('save')->assertHasNoErrors();
        $this->assertSame('S3cret pass ', Configuration::get('mail.password'));
        $this->assertSame('smtp.example.test', Configuration::get('mail.host'));

        // Restore defaults on the Mail tab removes it, and server configuration needs no SMTP fields.
        Livewire::test(Settings::class)->set('tab', 'mail')->call('restoreDefaults')->assertDontSee('A password is saved.')->call('save')->assertHasNoErrors();
        $this->assertSame('', Configuration::get('mail.password'));
        $this->assertSame(0, ApplicationSetting::query()->where('key', 'like', 'mail.%')->count());
    }

    public function test_saved_smtp_settings_drive_every_mailer_and_server_configuration_leaves_the_environment_alone(): void
    {
        $this->freshMailer();
        $this->assertSame('array', config('mail.default'));

        $this->saveSmtp();
        $this->freshMailer();
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame(['address' => 'billing@example.test', 'name' => 'Frish Billing'], config('mail.from'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame(['mail.example.test', 465, 'billing@example.test', 'S3cret pass '],
            [config('mail.mailers.smtp.host'), config('mail.mailers.smtp.port'), config('mail.mailers.smtp.username'), config('mail.mailers.smtp.password')]);

        $this->saveSmtp(['encryption' => 'tls', 'port' => '587', 'from_name' => '']);
        $this->freshMailer();
        $this->assertSame(['smtp', true, true], [config('mail.mailers.smtp.scheme'), config('mail.mailers.smtp.require_tls'), config('mail.mailers.smtp.auto_tls')]);
        $this->assertSame(Configuration::get('app_name'), config('mail.from.name'));
    }

    public function test_a_test_email_goes_out_with_the_saved_settings_and_a_failure_shows_the_server_answer(): void
    {
        Mail::fake();
        Livewire::test(Settings::class)->set('tab', 'mail')->assertSet('testEmail', 'owner@example.test')->assertSee('Send a test email')
            ->set('testEmail', 'me@example.test')->call('sendTestEmail')->assertHasNoErrors()
            ->assertSet('testResult.tone', 'warning')->assertSee('writes email to the log instead of sending it');
        Mail::assertSent(TestMail::class, fn (TestMail $mail): bool => $mail->hasTo('me@example.test'));

        // A real SMTP attempt at a closed port fails, and the page says why instead of breaking.
        $this->saveSmtp(['host' => '127.0.0.1', 'port' => '1', 'encryption' => 'none']);
        $this->freshMailer();
        Livewire::test(Settings::class)->set('tab', 'mail')->call('sendTestEmail')
            ->assertSet('testResult.tone', 'danger')->assertSee('The test email was not sent.');
    }

    public function test_only_people_who_can_update_settings_send_test_emails_and_only_five_a_minute(): void
    {
        Mail::fake();
        $component = Livewire::test(Settings::class)->set('tab', 'mail');
        foreach (range(1, 5) as $attempt) {
            $component->call('sendTestEmail')->assertHasNoErrors();
        }
        $component->call('sendTestEmail')->assertHasErrors('testEmail');
        Mail::assertSentCount(5);

        $this->actingAs(User::factory()->create(['role' => 'administrator', 'denied_permissions' => ['settings.update']]));
        Livewire::test(Settings::class)->set('tab', 'mail')->assertDontSee('Send a test email')->call('sendTestEmail')->assertForbidden();
    }
}
