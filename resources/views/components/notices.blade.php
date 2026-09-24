{{-- Flash toast for session('success'): top centre, dismisses itself after five seconds. --}}
@if(session('success'))
    <div class="toast-region">
        <div class="toast" role="status" x-cloak x-data="{ show: false }" x-init="$nextTick(() => show = true); setTimeout(() => show = false, 5000)" x-show="show" x-transition:enter="toast-enter" x-transition:enter-start="toast-hidden" x-transition:leave="toast-enter" x-transition:leave-end="toast-hidden">
            <span class="toast-icon"><x-icon name="check" /></span>
            <span>{{ session('success') }}</span>
            <button type="button" class="toast-close" x-on:click="show = false" aria-label="{{ __('Dismiss') }}"><x-icon name="x" width="14" height="14" /></button>
        </div>
    </div>
@endif
