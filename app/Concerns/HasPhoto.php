<?php

namespace App\Concerns;

use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\DB;

/**
 * An optional profile photo, stored as media in the `photo` collection. Use together with HasMedia. Deleting the
 * record removes its photo file once the delete is committed. Eager-load `photo` in lists.
 */
trait HasPhoto
{
    public const PHOTO = 'photo';

    protected static function bootHasPhoto(): void
    {
        static::deleted(function (Model $model): void {
            $files = Media::query()->where('mediable_type', $model->getMorphClass())->where('mediable_id', $model->getKey())->get();
            DB::afterCommit(fn () => $files->each(fn (Media $media) => app(MediaService::class)->detach($media)));
        });
    }

    public function photo(): MorphOne
    {
        return $this->morphOne(Media::class, 'mediable')->ofMany(['id' => 'max'], fn (Builder $query) => $query->where('collection', self::PHOTO));
    }

    /** A short-lived signed URL of the photo, or null without one. */
    public function photoUrl(): ?string
    {
        return $this->photo ? app(MediaService::class)->url($this->photo) : null;
    }
}
