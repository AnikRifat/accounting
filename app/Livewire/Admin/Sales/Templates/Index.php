<?php

namespace App\Livewire\Admin\Sales\Templates;

use App\Models\Document;
use App\Models\DocumentSequence;
use App\Models\DocumentTemplate;
use App\Models\Media;
use App\Services\MediaService;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** Document templates of the header companies. Managing them needs `sales.setup`. */
class Index extends Component
{
    public function mount(): void
    {
        Gate::authorize('sales.setup');
    }

    /**
     * Deletes a template and its logo and signature. Documents and document types that used it fall back to the
     * company's default template (the foreign keys are set null), so deleting is allowed even for the last one:
     * documents then print with the built-in standard design.
     */
    public function delete(int $templateId): void
    {
        Gate::authorize('sales.setup');
        $template = DocumentTemplate::visibleTo(auth()->user())->whereIn('company_id', app(CompanyContext::class)->companyIds())->findOrFail($templateId);
        $files = $template->media()->get();
        DB::transaction(fn () => $template->delete());
        $files->each(fn (Media $file) => app(MediaService::class)->detach($file));
        session()->now('success', __(':name deleted.', ['name' => $template->name]));
    }

    public function render(): View
    {
        Gate::authorize('sales.setup');
        $templates = DocumentTemplate::visibleTo(auth()->user())->whereIn('company_id', app(CompanyContext::class)->companyIds())->with('company:id,name')
            ->addSelect(['documents_count' => Document::query()->selectRaw('COUNT(*)')->whereColumn('documents.template_id', 'document_templates.id')])
            ->orderBy('company_id')->orderByDesc('is_default')->orderBy('name')->get();
        $defaultFor = DocumentSequence::query()->whereIn('template_id', $templates->modelKeys())->get(['template_id', 'type'])
            ->groupBy('template_id')->map(fn ($sequences) => $sequences->map(fn (DocumentSequence $sequence): string => $sequence->type->pluralLabel())->sort()->values()->all());

        return view('livewire.admin.sales.templates.index', [
            'templates' => $templates, 'defaultFor' => $defaultFor, 'showCompany' => app(CompanyContext::class)->isAll(),
            'layouts' => DocumentTemplate::layoutLabels(),
        ])->layout('layouts.admin');
    }
}
