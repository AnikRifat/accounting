<?php

namespace App\Livewire\Admin\Media;

use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Index extends Component
{
    use WithFileUploads, WithPagination;

    public $file;

    public string $collection = 'default';

    public function saveUpload(MediaService $service): void
    {
        Gate::authorize('media.upload');
        $this->validate(MediaService::rules());
        $service->store($this->file, auth()->user(), $this->collection, auth()->user());
        $this->reset('file');
        $this->resetPage();
        session()->flash('success', 'File uploaded.');
    }

    public function delete(int $id, MediaService $service): void
    {
        $service->delete(Media::findOrFail($id), auth()->user());
        session()->flash('success', 'File deleted. Its tracking record has been retained.');
    }

    public function render(): View
    {
        Gate::authorize('media.view');

        return view('livewire.admin.media.index', ['items' => Media::visibleTo(auth()->user())->latest('id')->paginate(15), 'service' => app(MediaService::class)])->layout('layouts.admin');
    }
}
