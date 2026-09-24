<?php

namespace App\Livewire\Admin\Entries;

use App\Models\JournalEntry;
use App\Services\LedgerService;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

/** Trashed entries of the header's company scope: restore (entries.delete) or delete permanently (entries.purge). */
class Trash extends Component
{
    use WithPagination;

    public function restore(int $entryId): void
    {
        Gate::authorize('entries.delete');
        $this->resetErrorBag('trash');
        $entry = $this->trashed()->findOrFail($entryId);
        try {
            app(LedgerService::class)->restore($entry, auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('trash', collect($exception->errors())->flatten()->first());

            return;
        }
        session()->now('success', __('Entry :number restored.', ['number' => $entry->number]));
    }

    public function purge(int $entryId): void
    {
        Gate::authorize('entries.purge');
        $this->resetErrorBag('trash');
        $entry = $this->trashed()->findOrFail($entryId);
        app(LedgerService::class)->purge($entry, auth()->user());
        session()->now('success', __('Entry :number deleted permanently.', ['number' => $entry->number]));
    }

    /** Permanently deletes every trashed entry in the current scope, one purge (and transaction) per entry. */
    public function emptyTrash(): void
    {
        Gate::authorize('entries.purge');
        $this->resetErrorBag('trash');
        $ids = $this->trashed()->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            // A trashed bill's purge already removed its trashed receipts or payments.
            $entry = $this->trashed()->find($id);
            if ($entry !== null) {
                app(LedgerService::class)->purge($entry, auth()->user());
            }
        }
        $this->resetPage();
        session()->now('success', __(':count entries deleted permanently.', ['count' => $ids->count()]));
    }

    public function render(): View
    {
        Gate::authorize('entries.delete');

        return view('livewire.admin.entries.trash', [
            'entries' => $this->trashed()->with(['company:id,name,code', 'party:id,name', 'deleter:id,name'])
                ->orderByDesc('deleted_at')->orderByDesc('id')->paginate(25),
            'showCompany' => app(CompanyContext::class)->isAll(),
        ])->layout('layouts.admin');
    }

    /** Trashed entries of companies the user can see, narrowed to the header's company scope. */
    private function trashed(): Builder
    {
        return JournalEntry::onlyTrashed()->visibleTo(auth()->user())->whereIn('company_id', app(CompanyContext::class)->companyIds());
    }
}
