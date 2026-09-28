@props(['current' => null, 'name' => '', 'pending' => false, 'label' => null])
{{-- The optional profile photo field of a form using App\Livewire\Concerns\WithPhotoUpload: square crop, the current photo and a remove option. --}}
<div class="stack-sm span-full">
    <x-form.image name="photo" :label="$label ?? __('Photo')" accept="image/jpeg,image/png,image/webp" aspect="1" :help="__('Optional. JPG, PNG or WebP, cropped to a square.')" />
    @if($current)
        <div class="flex flex-wrap items-center gap-3">
            <x-avatar :url="$current" :name="$name" size="lg" />
            @if($pending)<span class="muted">{{ __('The new photo replaces it when you save.') }}</span>@else<x-form.checkbox name="removePhoto" :label="__('Remove the current photo')" wire:model="removePhoto" />@endif
        </div>
    @endif
</div>
