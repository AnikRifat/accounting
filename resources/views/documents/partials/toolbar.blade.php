{{-- Screen-only actions above a rendered document or receipt; hidden when printing. $links: list of {label, href?, print?, back?}. --}}
<div class="doc-toolbar" role="toolbar" aria-label="{{ __('Document actions') }}">
    @foreach($links as $link)
        @if($link['print'] ?? false)
            <button type="button" class="tb-btn tb-primary" onclick="window.print()">{{ $link['label'] }}</button>
        @else
            <a @class(['tb-btn', 'tb-back' => $link['back'] ?? false]) href="{{ $link['href'] }}" @if($link['external'] ?? false) rel="nofollow" @endif>{{ $link['label'] }}</a>
        @endif
    @endforeach
</div>
