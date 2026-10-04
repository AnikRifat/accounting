<?php

namespace App\Livewire\Admin;

use App\Mail\TestMail;
use App\Support\Configuration;
use App\Support\Modules;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Settings page: one tab per enabled module, every field defined in App\Support\Configuration. A save stores the
 * fields of every shown tab and reloads the page, so a module switched on or off updates the header at once.
 * Secret fields (the SMTP password) never reach the browser: the form starts empty and an empty value keeps the stored one.
 */
class Settings extends Component
{
    #[Url(except: 'general')]
    public string $tab = 'general';

    /** @var array<string, mixed> nested by setting key: values.sales.default_due_days */
    public array $values = [];

    /** @var list<string> secret keys that Restore defaults emptied; saved as empty instead of kept */
    #[Locked]
    public array $clearedSecrets = [];

    public string $testEmail = '';

    /** @var array{tone: string, message: string}|null outcome of the last test email */
    public ?array $testResult = null;

    public function mount(): void
    {
        Gate::authorize('settings.view');
        foreach ($this->fields() as $key => $field) {
            data_set($this->values, $key, $this->formValue($field, Configuration::get($key)));
        }
        if (! isset($this->sections()[$this->tab])) {
            $this->tab = 'general';
        }
        $this->testEmail = (string) auth()->user()->email;
    }

    public function save(Configuration $configuration): ?Redirector
    {
        Gate::authorize('settings.update');
        $fields = $this->fields();
        $this->validate(
            collect($fields)->mapWithKeys(fn (array $field, string $key): array => ['values.'.$key => Configuration::rules($field)])->all(),
            [],
            collect($fields)->mapWithKeys(fn (array $field, string $key): array => ['values.'.$key => mb_strtolower($field['label'])])->all(),
        );
        $configuration->save(collect($fields)
            ->reject(fn (array $field, string $key): bool => $field['type'] === 'secret' && data_get($this->values, $key) === '' && ! in_array($key, $this->clearedSecrets, true))
            ->map(fn (array $field, string $key): mixed => Configuration::cast($field, data_get($this->values, $key)))->all());
        session()->flash('success', __('Settings saved.'));

        return $this->redirectRoute('admin.settings', $this->tab === 'general' ? [] : ['tab' => $this->tab], navigate: true);
    }

    /** Puts the fields of the open tab back to their defaults; nothing is stored until Save. */
    public function restoreDefaults(): void
    {
        foreach ($this->sections()[$this->tab]['groups'] ?? [] as $group) {
            foreach ($group as $key => $field) {
                data_set($this->values, $key, $this->formValue($field, $field['default']));
                if ($field['type'] === 'secret') {
                    $this->clearedSecrets[] = $key;
                }
            }
        }
        $this->resetValidation();
    }

    /**
     * Sends a test email with the saved mail settings, the same way documents go out, and shows the server's answer.
     * Limited to five a minute per person.
     */
    public function sendTestEmail(): void
    {
        Gate::authorize('settings.update');
        $this->testResult = null;
        $this->validate(['testEmail' => ['required', 'email', 'max:150']], [], ['testEmail' => __('send to')]);
        $limiter = 'mail-test:'.auth()->id();
        if (RateLimiter::tooManyAttempts($limiter, 5)) {
            $this->addError('testEmail', __('Too many test emails. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($limiter)]));

            return;
        }
        RateLimiter::hit($limiter);

        try {
            Mail::to($this->testEmail)->send(new TestMail(auth()->user(), (string) Configuration::get('app_name')));
        } catch (TransportExceptionInterface $exception) {
            $this->testResult = ['tone' => 'danger', 'message' => __('The test email was not sent. The mail server said: :error', ['error' => $exception->getMessage()])];

            return;
        }
        $mailer = config('mail.default');
        $this->testResult = in_array(config("mail.mailers.{$mailer}.transport"), ['log', 'array'], true)
            ? ['tone' => 'warning', 'message' => __('The server configuration writes email to the log instead of sending it. Choose SMTP server to deliver email.')]
            : ['tone' => 'success', 'message' => __('Test email sent to :email. Check the inbox and the spam folder.', ['email' => $this->testEmail])];
    }

    public function render(): View
    {
        $sections = $this->sections();

        $savedSecrets = collect($this->fields())
            ->filter(fn (array $field, string $key): bool => $field['type'] === 'secret' && ! in_array($key, $this->clearedSecrets, true) && Configuration::get($key) !== '')
            ->keys()->all();

        return view('livewire.admin.settings', ['sections' => $sections, 'section' => $sections[$this->tab] ?? $sections['general'], 'savedSecrets' => $savedSecrets])
            ->layout('layouts.admin');
    }

    /**
     * Tabs of enabled modules, holding only the fields whose module is available in this install.
     *
     * @return array<string, array{label: string, emoji: string, module: string, groups: array<string, array<string, array<string, mixed>>>}>
     */
    private function sections(): array
    {
        return collect(Configuration::sections())
            ->filter(fn (array $section): bool => Modules::enabled($section['module']))
            ->map(fn (array $section): array => [...$section, 'groups' => array_filter(array_map(
                fn (array $fields): array => array_filter($fields, fn (array $field): bool => $field['available'] ?? true), $section['groups']))])
            ->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function fields(): array
    {
        return collect($this->sections())->flatMap(fn (array $section): array => array_merge(...array_values($section['groups'])))->all();
    }

    /** @param array<string, mixed> $field */
    private function formValue(array $field, mixed $value): bool|string
    {
        return match ($field['type']) {
            'bool' => (bool) $value,
            'secret' => '',
            default => (string) $value,
        };
    }
}
