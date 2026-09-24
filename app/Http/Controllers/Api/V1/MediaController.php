<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreMediaRequest;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('media.view');

        return MediaResource::collection(Media::visibleTo($request->user())->latest('id')->paginate(15));
    }

    public function store(StoreMediaRequest $request, MediaService $service): MediaResource
    {
        return new MediaResource($service->store($request->file('file'), $request->user(), $request->validated('collection'), $request->user()));
    }

    public function show(Media $media): MediaResource
    {
        Gate::authorize('view', $media);

        return new MediaResource($media);
    }

    public function destroy(Request $request, Media $media, MediaService $service): Response
    {
        $service->delete($media, $request->user());

        return response()->noContent();
    }

    public function replace(StoreMediaRequest $request, Media $media, MediaService $service): MediaResource
    {
        return new MediaResource($service->replace($media, $request->file('file'), $request->user()));
    }

    public function download(Media $media, MediaService $service): StreamedResponse
    {
        return $service->download($media);
    }
}
