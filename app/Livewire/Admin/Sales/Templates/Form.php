<?php

namespace App\Livewire\Admin\Sales\Templates;

use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\Media;
use App\Models\User;
use App\Services\MediaService;
use App\Support\CompanyContext;
use App\Support\Modules;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportRedirects\Redirector;
use Livewire\WithFileUploads;

/**
 * The template builder: branding, fixed texts and the ordered list of blocks (drag to reorder, show or hide, free
 * text blocks). The preview beside it renders a sample document with the saved template, so it shows the last saved
 * state; a new template is previewed once it is first saved (the page then continues on its edit URL).
 */
class Form extends Component
{
    use WithFileUploads;

    /** Most free text blocks one template can have. */
    public const MAX_TEXT_BLOCKS = 10;

    #[Locked]
    public ?int $templateId = null;

    /** The template's own company, or the header company when creating. */
    #[Locked]
    public ?int $companyId = null;

    public string $name = '';

    public string $layout = 'classic';

    public string $accentColor = '#166534';

    public string $font = 'sans';

    public string $vatNumber = '';

    public string $headerText = '';

    public string $footerText = '';

    public string $bankDetails = '';

    public bool $isDefault = false;

    /** @var list<array{id: string, key: string, visible: bool, text: string}> */
    public array $sections = [];

    public ?TemporaryUploadedFile $logo = null;

    public bool $removeLogo = false;

    public ?TemporaryUploadedFile $signature = null;

    public bool $removeSignature = false;

    /** Document type the preview shows. */
    public string $previewType = 'invoice';

    /** Bumped on every save so the preview frame reloads. */
    public int $previewVersion = 0;

    public function mount(?DocumentTemplate $template = null): void
    {
        Gate::authorize('sales.setup');
        if ($template?->exists) {
            abort_unless(auth()->user()->canAccessCompany($template->company_id, Modules::SALES), 404);
            $this->templateId = $template->id;
            $this->companyId = $template->company_id;
            $this->fill([
                'name' => $template->name, 'layout' => $template->layout, 'accentColor' => $template->accent_color, 'font' => $template->font,
                'vatNumber' => (string) $template->vat_number, 'headerText' => (string) $template->header_text, 'footerText' => (string) $template->footer_text,
                'bankDetails' => (string) $template->bank_details, 'isDefault' => $template->is_default,
            ]);
            $this->sections = $this->withIds($template->orderedSections());
        } else {
            $this->companyId = app(CompanyContext::class)->company()?->id;
            $this->isDefault = $this->companyId !== null && ! DocumentTemplate::query()->where('company_id', $this->companyId)->exists();
            $this->sections = $this->withIds(DocumentTemplate::defaultSections());
        }
    }

    /** wire:sort handler, also used by the move up/down buttons: puts a block at a zero-based position. */
    public function sortSection(string $id, int $position): void
    {
        $index = collect($this->sections)->search(fn (array $section): bool => $section['id'] === $id);
        if ($index === false) {
            return;
        }
        $section = array_splice($this->sections, $index, 1)[0];
        array_splice($this->sections, max(0, min($position, count($this->sections))), 0, [$section]);
    }

    public function addTextBlock(): void
    {
        if (collect($this->sections)->where('key', DocumentTemplate::TEXT_BLOCK)->count() >= self::MAX_TEXT_BLOCKS) {
            $this->addError('sections', __('A template can have at most :count text blocks.', ['count' => self::MAX_TEXT_BLOCKS]));

            return;
        }
        $footer = collect($this->sections)->search(fn (array $section): bool => $section['key'] === 'footer');
        array_splice($this->sections, $footer === false ? count($this->sections) : $footer, 0, [$this->newSection(DocumentTemplate::TEXT_BLOCK, true, '')]);
    }

    /** Removes a free text block; the fixed blocks are hidden instead. */
    public function removeSection(string $id): void
    {
        $this->sections = array_values(array_filter($this->sections, fn (array $section): bool => $section['id'] !== $id || $section['key'] !== DocumentTemplate::TEXT_BLOCK));
    }

    public function save(): Redirector|RedirectResponse|null
    {
        Gate::authorize('sales.setup');
        $user = auth()->user();
        $existing = $this->templateId ? DocumentTemplate::visibleTo($user)->findOrFail($this->templateId) : null;
        $company = $existing?->company ?? $this->contextCompany();
        if (! $company) {
            $this->addError('company', __('The company in the header has changed or is inactive. Reload the page and try again.'));

            return null;
        }
        foreach (['name', 'vatNumber', 'headerText', 'footerText', 'bankDetails'] as $field) {
            $this->{$field} = trim($this->{$field});
        }
        $this->accentColor = strtolower(trim($this->accentColor));
        $image = ['nullable', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(2048)];
        $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('document_templates', 'name')->where('company_id', $company->id)->ignore($existing?->id)],
            'layout' => ['required', Rule::in(DocumentTemplate::LAYOUTS)],
            'accentColor' => ['required', 'regex:/^#[0-9a-f]{6}$/'],
            'font' => ['required', Rule::in(array_keys(DocumentTemplate::FONTS))],
            'vatNumber' => ['nullable', 'string', 'max:50'],
            'headerText' => ['nullable', 'string', 'max:1000'],
            'footerText' => ['nullable', 'string', 'max:1000'],
            'bankDetails' => ['nullable', 'string', 'max:1000'],
            'isDefault' => ['boolean'],
            'logo' => $image, 'signature' => $image, 'removeLogo' => ['boolean'], 'removeSignature' => ['boolean'],
            'sections' => ['required', 'array', 'max:'.(count(DocumentTemplate::BLOCKS) + self::MAX_TEXT_BLOCKS)],
            'sections.*.key' => ['required', 'string', Rule::in([...DocumentTemplate::BLOCKS, DocumentTemplate::TEXT_BLOCK])],
            'sections.*.visible' => ['boolean'],
            'sections.*.text' => ['nullable', 'string', 'max:2000'],
        ], ['accentColor.regex' => __('Pick a colour, such as #166534.')], [
            'name' => __('name'), 'accentColor' => __('accent colour'), 'vatNumber' => __('BIN/VAT number'), 'headerText' => __('header text'),
            'footerText' => __('footer text'), 'bankDetails' => __('bank details'), 'sections.*.text' => __('text'),
        ]);
        $keys = array_column($this->sections, 'key');
        $fixed = array_filter($keys, fn (string $key): bool => $key !== DocumentTemplate::TEXT_BLOCK);
        if (count($fixed) !== count(array_unique($fixed)) || count($keys) - count($fixed) > self::MAX_TEXT_BLOCKS) {
            $this->addError('sections', __('The block list is out of date. Reload the page and try again.'));

            return null;
        }

        $template = DB::transaction(function () use ($existing, $company): DocumentTemplate {
            Company::query()->lockForUpdate()->findOrFail($company->id);
            $template = $existing ?? new DocumentTemplate(['company_id' => $company->id]);
            $template->fill([
                'name' => $this->name, 'layout' => $this->layout, 'accent_color' => $this->accentColor, 'font' => $this->font,
                'vat_number' => $this->vatNumber ?: null, 'header_text' => $this->headerText ?: null, 'footer_text' => $this->footerText ?: null,
                'bank_details' => $this->bankDetails ?: null,
                'sections' => array_map(fn (array $section): array => ['key' => $section['key'], 'visible' => (bool) $section['visible']]
                    + ($section['key'] === DocumentTemplate::TEXT_BLOCK ? ['text' => trim((string) ($section['text'] ?? ''))] : []), $this->sections),
            ])->forceFill(['is_default' => $this->isDefault])->save();
            if ($this->isDefault) {
                DocumentTemplate::query()->where('company_id', $company->id)->whereKeyNot($template->id)->update(['is_default' => false]);
            }

            return $template;
        });
        $this->syncImage($template, DocumentTemplate::LOGO, $this->logo, $this->removeLogo, $user);
        $this->syncImage($template, DocumentTemplate::SIGNATURE, $this->signature, $this->removeSignature, $user);
        $this->reset('logo', 'removeLogo', 'signature', 'removeSignature');

        if (! $existing) {
            session()->flash('success', __('Template saved. The preview shows it now.'));

            return redirect()->route('admin.sales.templates.edit', $template);
        }
        $this->previewVersion++;
        session()->now('success', __('Template saved.'));

        return null;
    }

    public function render(): View
    {
        $template = $this->templateId ? DocumentTemplate::visibleTo(auth()->user())->find($this->templateId) : null;

        return view('livewire.admin.sales.templates.form', [
            'companyName' => Company::visibleTo(auth()->user())->whereKey($this->companyId)->value('name'),
            'layouts' => DocumentTemplate::layoutLabels(),
            'fonts' => array_map(fn (array $font): string => $font[0], DocumentTemplate::FONTS),
            'blockLabels' => DocumentTemplate::blockLabels(),
            'previewTypes' => collect(DocumentType::cases())->mapWithKeys(fn (DocumentType $type): array => [$type->value => $type->label()])->all(),
            'currentLogo' => $template?->getMedia(DocumentTemplate::LOGO)->first()?->filename,
            'currentSignature' => $template?->getMedia(DocumentTemplate::SIGNATURE)->first()?->filename,
        ])->layout('layouts.admin');
    }

    /** Stores a newly chosen image and removes the one it replaces, or the current one when asked. */
    private function syncImage(DocumentTemplate $template, string $collection, ?TemporaryUploadedFile $file, bool $remove, User $actor): void
    {
        if ($file === null && ! $remove) {
            return;
        }
        $media = app(MediaService::class);
        $previous = $template->getMedia($collection);
        if ($file !== null) {
            $media->attach($file, $actor, $collection, $template);
        }
        $previous->each(fn (Media $old) => $media->detach($old));
    }

    /**
     * @param  list<array{key: string, visible: bool, text?: string}>  $sections
     * @return list<array{id: string, key: string, visible: bool, text: string}>
     */
    private function withIds(array $sections): array
    {
        return array_map(fn (array $section): array => $this->newSection($section['key'], $section['visible'], $section['text'] ?? ''), $sections);
    }

    /** @return array{id: string, key: string, visible: bool, text: string} */
    private function newSection(string $key, bool $visible, string $text): array
    {
        return ['id' => Str::random(8), 'key' => $key, 'visible' => $visible, 'text' => $text];
    }

    /** The header company, while it is still the one this page was opened for, visible and active. */
    private function contextCompany(): ?Company
    {
        $company = app(CompanyContext::class)->company();

        return $company?->is_active && $company->id === $this->companyId ? $company : null;
    }
}
