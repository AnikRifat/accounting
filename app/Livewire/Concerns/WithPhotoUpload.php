<?php

namespace App\Livewire\Concerns;

use App\Models\Media;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\File;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The optional photo field of a form whose record uses App\Concerns\HasPhoto. Add photoRules() to the form's
 * validation and call syncPhoto() after the record is saved; the record's own write authorizes the upload.
 */
trait WithPhotoUpload
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $photo = null;

    public bool $removePhoto = false;

    /** @return array<string, array<int, mixed>> */
    protected function photoRules(): array
    {
        return ['photo' => ['nullable', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(4096)], 'removePhoto' => ['boolean']];
    }

    /** Stores a newly chosen photo and removes the one it replaces, or the current one when asked. */
    protected function syncPhoto(Model $owner, User $actor): void
    {
        if ($this->photo === null && ! $this->removePhoto) {
            return;
        }
        $media = app(MediaService::class);
        $previous = $owner->getMedia($owner::PHOTO);
        if ($this->photo !== null) {
            $media->attach($this->photo, $actor, $owner::PHOTO, $owner);
        }
        $previous->each(fn (Media $file) => $media->detach($file));
        $owner->unsetRelation('photo');
        $this->reset('photo', 'removePhoto');
    }
}
