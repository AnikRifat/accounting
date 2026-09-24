@props(['title' => null, 'description' => null, 'flush' => false, 'id' => null])
{{-- Surface for every block of content. `flush` drops the body padding so tables run edge to edge. Slots: actions (header right), footer. --}}
@php($headingId = $id ? $id.'-heading' : ($title ? 'card-'.substr(md5($title), 0, 8).'-heading' : null))
<section {{ $attributes->class(['card', 'card-flush' => $flush]) }} @if($id) id="{{ $id }}" @endif @if($title) aria-labelledby="{{ $headingId }}" @endif>
    @if($title || isset($actions))
        <div class="card-header">
            <div class="card-header-text">@if($title)<h2 id="{{ $headingId }}">{{ $title }}</h2>@endif @if($description)<p class="card-description">{{ $description }}</p>@endif</div>
            @isset($actions)<div class="btn-group">{{ $actions }}</div>@endisset
        </div>
    @endif
    @isset($toolbar){{ $toolbar }}@endisset
    <div class="card-body">{{ $slot }}</div>
    @isset($footer)<div class="card-footer">{{ $footer }}</div>@endisset
</section>
