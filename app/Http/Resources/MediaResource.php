<?php

namespace App\Http\Resources;

use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'uuid' => $this->uuid, 'filename' => $this->filename,
            'collection' => $this->collection, 'mime_type' => $this->mime_type, 'size' => $this->size,
            'width' => $this->width, 'height' => $this->height,
            'url' => app(MediaService::class)->url($this->resource), 'created_at' => $this->created_at->toIso8601String()];
    }
}
