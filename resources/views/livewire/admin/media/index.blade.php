<div class="page">
    <x-notices />
    <x-page-header :title="__('Media library')" :description="__('Tracked images and documents, stored through one shared service.')" />
    @can('media.upload')
        <x-card :title="__('Upload file')">
            <form wire:submit="saveUpload" class="stack">
                <div class="form-grid">
                    <x-form.input name="file" :label="__('Image or document')" type="file" wire:model="file" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.txt,.csv" required :help="__('JPG, PNG, WebP, GIF, PDF, text or CSV. Maximum 8 MB.')" />
                    <x-form.input name="collection" :label="__('Collection')" wire:model="collection" required :help="__('A purpose such as avatar, document, or banner.')" />
                </div>
                <div><x-button type="submit" icon="upload" wire:loading.attr="disabled">{{ __('Upload file') }}</x-button></div>
            </form>
        </x-card>
    @endcan
    <x-card flush>
        <x-table :caption="__('Media library')">
            <x-slot:head><th>{{ __('File') }}</th><th>{{ __('Collection') }}</th><th class="num">{{ __('Size') }}</th><th>{{ __('Uploaded') }}</th><th class="actions-col"><span class="sr-only">{{ __('Actions') }}</span></th></x-slot:head>
            @forelse($items as $item)
                <tr wire:key="media-{{ $item->id }}">
                    <td><strong>{{ $item->filename }}</strong><p class="muted">{{ $item->mime_type }}</p></td>
                    <td><x-badge>{{ $item->collection }}</x-badge></td>
                    <td class="num">{{ number_format($item->size / 1024, 1) }} KB</td>
                    <td class="nowrap">{{ $item->created_at->format('d M Y') }}</td>
                    <td><div class="row-actions">
                        <x-button variant="ghost" size="sm" icon="external" :href="$service->url($item)" :navigate="false" target="_blank" rel="noopener" :label="__('Open :name', ['name' => $item->filename])">{{ __('Open') }}</x-button>
                        @can('delete', $item)<x-button variant="danger" size="sm" icon="trash" wire:click="delete({{ $item->id }})" wire:confirm="{{ __('Delete this file? Its tracking record will be retained.') }}" :label="__('Delete :name', ['name' => $item->filename])">{{ __('Delete') }}</x-button>@endcan
                    </div></td>
                </tr>
            @empty
                <x-table.empty colspan="5" emoji="🖼️">{{ __('No files uploaded yet.') }}</x-table.empty>
            @endforelse
        </x-table>
        {{ $items->links() }}
    </x-card>
</div>
