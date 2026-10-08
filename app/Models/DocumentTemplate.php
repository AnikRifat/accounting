<?php

namespace App\Models;

use App\Concerns\HasMedia;
use App\Enums\DocumentType;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company's document design: base layout, colours, font, logo and signature (media), fixed texts and the ordered
 * list of blocks the builder shows or hides. Used alike by the preview, the print page and the PDF.
 */
#[Fillable(['company_id', 'name', 'layout', 'accent_color', 'font', 'sections', 'vat_number', 'header_text', 'footer_text', 'bank_details'])]
class DocumentTemplate extends Model
{
    use HasMedia;

    public const LOGO = 'logo';

    public const SIGNATURE = 'signature';

    public const LAYOUTS = ['classic', 'modern', 'compact'];

    /** Font key => [label, CSS stack, mPDF font]. */
    public const FONTS = [
        'sans' => ['Sans', '"DejaVu Sans", Arial, sans-serif', 'dejavusans'],
        'serif' => ['Serif', '"DejaVu Serif", Georgia, serif', 'dejavuserif'],
        'condensed' => ['Condensed', '"DejaVu Sans Condensed", "Arial Narrow", sans-serif', 'dejavusanscondensed'],
    ];

    /** Blocks in their default order. `text` blocks are free text and can be added more than once. */
    public const BLOCKS = ['header', 'title', 'parties', 'fields', 'items', 'totals', 'notes', 'terms', 'bank', 'signature', 'footer'];

    public const TEXT_BLOCK = 'text';

    protected $attributes = ['layout' => 'classic', 'accent_color' => '#166534', 'font' => 'sans', 'is_default' => false];

    protected function casts(): array
    {
        return ['sections' => 'array', 'is_default' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return list<array{key: string, visible: bool, text?: string}> */
    public static function defaultSections(): array
    {
        return array_map(fn (string $key): array => ['key' => $key, 'visible' => true], self::BLOCKS);
    }

    /** @return array<string, string> */
    public static function blockLabels(): array
    {
        return ['header' => __('Logo and company'), 'title' => __('Title, number and dates'), 'parties' => __('Customer or supplier'),
            'fields' => __('Custom fields'), 'items' => __('Items'), 'totals' => __('Totals'), 'notes' => __('Notes'), 'terms' => __('Terms'),
            'bank' => __('Bank details'), 'signature' => __('Signature'), 'footer' => __('Footer'), self::TEXT_BLOCK => __('Text block')];
    }

    /** @return array<string, string> */
    public static function layoutLabels(): array
    {
        return ['classic' => __('Classic'), 'modern' => __('Modern'), 'compact' => __('Compact')];
    }

    /**
     * The sections to render, in order: stored ones cleaned up, with any block missing from them appended hidden.
     *
     * @return list<array{key: string, visible: bool, text?: string}>
     */
    public function orderedSections(): array
    {
        $sections = collect($this->sections ?? [])->filter(fn (mixed $section): bool => is_array($section)
            && in_array($section['key'] ?? null, [...self::BLOCKS, self::TEXT_BLOCK], true))->values();
        $missing = array_diff(self::BLOCKS, $sections->pluck('key')->all());

        return [...$sections->map(fn (array $section): array => ['key' => $section['key'], 'visible' => (bool) ($section['visible'] ?? true)]
            + ($section['key'] === self::TEXT_BLOCK ? ['text' => (string) ($section['text'] ?? '')] : []))->all(),
            ...array_map(fn (string $key): array => ['key' => $key, 'visible' => false], array_values($missing))];
    }

    public function fontStack(): string
    {
        return (self::FONTS[$this->font] ?? self::FONTS['sans'])[1];
    }

    public function pdfFont(): string
    {
        return (self::FONTS[$this->font] ?? self::FONTS['sans'])[2];
    }

    /** Templates of the companies the user may access. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('company_id', $user->accessibleCompanyIds(Modules::SALES));
    }

    /**
     * The template a document renders with: its own, else its type's default (document_sequences), else the company
     * default, else the first one, else an unsaved standard design.
     */
    public static function forDocument(int $companyId, ?int $templateId, ?DocumentType $type = null): self
    {
        $templates = self::query()->where('company_id', $companyId);
        $typeDefault = $type ? DocumentSequence::query()->where('company_id', $companyId)->where('type', $type)->value('template_id') : null;
        foreach (array_filter([$templateId, $typeDefault]) as $id) {
            if ($template = (clone $templates)->find($id)) {
                return $template;
            }
        }

        return (clone $templates)->orderByDesc('is_default')->orderBy('id')->first()
            ?? new self(['company_id' => $companyId, 'name' => __('Standard'), 'sections' => self::defaultSections()]);
    }
}
