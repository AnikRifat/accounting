@php
    $scrollTo = $scrollTo ?? 'body';
    $scrollSnippet = $scrollTo !== false ? "(\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()" : '';
    $pageName = $paginator->getPageName();
@endphp
{{-- App-wide Livewire pagination (overrides livewire::tailwind): result count on the left, pages on the right. Sticks to the bottom of the screen while its table is in view. --}}
<div class="pagination-wrap">
    @if($paginator->hasPages())
        <nav class="pagination" role="navigation" aria-label="{{ __('Pagination navigation') }}">
            <p class="muted">{{ __('Showing :first–:last of :total', ['first' => $paginator->firstItem(), 'last' => $paginator->lastItem(), 'total' => $paginator->total()]) }}</p>
            <div class="pagination-pages">
                @if($paginator->onFirstPage())
                    <span class="pagination-page" aria-disabled="true"><x-icon name="chevron-left" /><span class="sr-only">{{ __('Previous') }}</span></span>
                @else
                    <button type="button" class="pagination-page" wire:click="previousPage('{{ $pageName }}')" x-on:click="{{ $scrollSnippet }}" rel="prev" aria-label="{{ __('Previous') }}"><x-icon name="chevron-left" /></button>
                @endif
                @foreach($elements as $element)
                    @if(is_string($element))
                        <span class="pagination-page" aria-disabled="true">{{ $element }}</span>
                    @else
                        @foreach($element as $page => $url)
                            <span wire:key="paginator-{{ $pageName }}-page{{ $page }}">
                                @if($page == $paginator->currentPage())
                                    <span class="pagination-page" aria-current="page">{{ $page }}</span>
                                @else
                                    <button type="button" class="pagination-page" wire:click="gotoPage({{ $page }}, '{{ $pageName }}')" x-on:click="{{ $scrollSnippet }}" aria-label="{{ __('Page :page', ['page' => $page]) }}">{{ $page }}</button>
                                @endif
                            </span>
                        @endforeach
                    @endif
                @endforeach
                @if($paginator->hasMorePages())
                    <button type="button" class="pagination-page" wire:click="nextPage('{{ $pageName }}')" x-on:click="{{ $scrollSnippet }}" rel="next" aria-label="{{ __('Next') }}"><x-icon name="chevron-right" /></button>
                @else
                    <span class="pagination-page" aria-disabled="true"><x-icon name="chevron-right" /><span class="sr-only">{{ __('Next') }}</span></span>
                @endif
            </div>
        </nav>
    @endif
</div>
