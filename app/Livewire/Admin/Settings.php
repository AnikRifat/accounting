<?php

namespace App\Livewire\Admin;

use App\Support\Configuration;
use App\Support\Modules;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Settings page: one tab per enabled module, every field defined in App\Support\Configuration. A save stores the
 * fields of every shown tab and reloads the page, so a module switched on or off updates the header at once.
 */
class Settings extends Component
{
    #[Url(except: 'general')]
    public string $tab = 'general';

    /** @var array<string, mixed> nested by setting key: values.sales.default_due_days */
    public array $values = [];

    public function mount(): void
    {
        Gate::authorize('settings.view');
        foreach ($this->fields() as $key => $field) {
            data_set($this->values, $key, $this->formValue($field, Configuration::get($key)));
        }
        if (! isset($this->sections()[$this->tab])) {
            $this->tab = 'general';
        }
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
        $configuration->save(collect($fields)->map(fn (array $field, string $key): mixed => Configuration::cast($field, data_get($this->values, $key)))->all());
        session()->flash('success', __('Settings saved.'));

        return $this->redirectRoute('admin.settings', $this->tab === 'general' ? [] : ['tab' => $this->tab], navigate: true);
    }

    /** Puts the fields of the open tab back to their defaults; nothing is stored until Save. */
    public function restoreDefaults(): void
    {
        foreach ($this->sections()[$this->tab]['groups'] ?? [] as $group) {
            foreach ($group as $key => $field) {
                data_set($this->values, $key, $this->formValue($field, $field['default']));
            }
        }
        $this->resetValidation();
    }

    public function render(): View
    {
        $sections = $this->sections();

        return view('livewire.admin.settings', ['sections' => $sections, 'section' => $sections[$this->tab] ?? $sections['general']])->layout('layouts.admin');
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
        return $field['type'] === 'bool' ? (bool) $value : (string) $value;
    }
}
