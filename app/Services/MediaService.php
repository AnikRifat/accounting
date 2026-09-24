<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** All permanent file storage, URLs, replacement, and deletion go through this service. */
class MediaService
{
    public static function rules(): array
    {
        return [
            'file' => ['required', File::types(config('media.allowed_types'))->max(config('media.max_size_kb'))],
            'collection' => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z0-9_-]+$/'],
        ];
    }

    public function store(UploadedFile $file, User $actor, string $collection = 'default', ?Model $owner = null): Media
    {
        Gate::forUser($actor)->authorize('media.upload');
        Validator::make(['file' => $file, 'collection' => $collection], self::rules())->validate();
        $disk = config('media.disk');
        $uuid = (string) Str::uuid();
        $extension = $file->guessExtension() ?? 'bin';
        $path = Storage::disk($disk)->putFileAs('media/'.now()->format('Y/m'), $file, $uuid.'.'.$extension);
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Media storage failed.');
        }
        $dimensions = str_starts_with((string) $file->getMimeType(), 'image/') ? @getimagesize($file->getRealPath()) : false;
        try {
            return Media::create([
                'uuid' => $uuid, 'uploaded_by' => $actor->id,
                'mediable_type' => $owner?->getMorphClass(), 'mediable_id' => $owner?->getKey(),
                'collection' => $collection, 'disk' => $disk, 'path' => $path,
                'filename' => Str::limit(preg_replace('/[\x00-\x1F\x7F]/', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 255, ''),
                'mime_type' => $file->getMimeType(), 'size' => $file->getSize(),
                'width' => $dimensions ? $dimensions[0] : null, 'height' => $dimensions ? $dimensions[1] : null,
            ]);
        } catch (Throwable $error) {
            Storage::disk($disk)->delete($path);
            throw $error;
        }
    }

    public function url(Media $media): string
    {
        return URL::temporarySignedRoute('media.download', now()->addMinutes(config('media.url_lifetime_minutes')), ['media' => $media->id]);
    }

    public function download(Media $media): StreamedResponse
    {
        return Storage::disk($media->disk)->response($media->path, $media->filename, [
            'Content-Type' => $media->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ], str_starts_with($media->mime_type, 'image/') ? 'inline' : 'attachment');
    }

    public function delete(Media $media, User $actor): void
    {
        Gate::forUser($actor)->authorize('delete', $media);
        if (! Storage::disk($media->disk)->delete($media->path)) {
            throw new RuntimeException('Media deletion failed.');
        }
        $media->delete();
    }

    public function replace(Media $media, UploadedFile $file, User $actor): Media
    {
        Gate::forUser($actor)->authorize('delete', $media);
        $replacement = $this->store($file, $actor, $media->collection, $media->mediable);
        try {
            $this->delete($media, $actor);
        } catch (Throwable $error) {
            $this->delete($replacement, $actor);
            throw $error;
        }

        return $replacement;
    }
}
