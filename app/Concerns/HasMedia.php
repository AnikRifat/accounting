<?php

namespace App\Concerns;

use App\Models\Media;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;

trait HasMedia
{
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function addMedia(UploadedFile $file, User $actor, string $collection = 'default'): Media
    {
        return app(MediaService::class)->store($file, $actor, $collection, $this);
    }

    public function getMedia(string $collection = 'default'): Collection
    {
        return $this->media()->where('collection', $collection)->latest('id')->get();
    }

    public function getFirstMediaUrl(string $collection = 'default'): ?string
    {
        $media = $this->getMedia($collection)->first();

        return $media ? app(MediaService::class)->url($media) : null;
    }

    public function clearMedia(User $actor, string $collection = 'default'): void
    {
        foreach ($this->getMedia($collection) as $media) {
            app(MediaService::class)->delete($media, $actor);
        }
    }
}
