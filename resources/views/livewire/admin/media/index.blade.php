<div>
    <x-notices />
    <div class="page-header"><div><p class="eyebrow">Shared storage</p><h1>Media library</h1><p class="muted">Tracked images and documents, stored through one shared service.</p></div></div>
    @can('media.upload')<form wire:submit="saveUpload" class="panel stack mb-6"><div class="form-grid">
        <x-form.input name="file" label="Image or document" type="file" wire:model="file" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.txt,.csv" required help="JPG, PNG, WebP, GIF, PDF, text or CSV. Maximum 8 MB." />
        <x-form.input name="collection" label="Collection" wire:model="collection" required help="A purpose such as avatar, document, or banner." />
    </div><div><button class="btn" type="submit" wire:loading.attr="disabled">Upload file</button><span wire:loading class="muted ml-3">Uploading…</span></div></form>@endcan
    <div class="panel stack"><div class="table-wrap"><table><thead><tr><th>File</th><th>Collection</th><th>Size</th><th>Uploaded</th><th>Actions</th></tr></thead><tbody>
        @forelse($items as $item)<tr wire:key="media-{{ $item->id }}"><td><strong>{{ $item->filename }}</strong><p class="muted">{{ $item->mime_type }}</p></td><td>{{ $item->collection }}</td><td>{{ number_format($item->size / 1024, 1) }} KB</td><td>{{ $item->created_at->format('M j, Y') }}</td><td><div class="flex gap-4 items-center"><a class="text-link" href="{{ $service->url($item) }}" target="_blank" rel="noopener">Open</a>@can('delete', $item)<button class="btn btn-danger" wire:click="delete({{ $item->id }})" wire:confirm="Delete this file? Its tracking record will be retained." wire:loading.attr="disabled">Delete</button>@endcan</div></td></tr>
        @empty<tr><td colspan="5"><p class="muted">No files uploaded yet.</p></td></tr>@endforelse
    </tbody></table></div>{{ $items->links() }}</div>
</div>
