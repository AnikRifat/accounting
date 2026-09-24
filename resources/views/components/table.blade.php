@props(['caption' => null, 'showCaption' => false, 'grouped' => false])
{{-- Every data table. `head` is the header row's cells, the default slot the body rows (or whole <tbody> groups with `grouped`), `foot` the footer rows. --}}
<div class="table-wrap">
    <table {{ $attributes->class(['table']) }}>
        @if($caption)<caption @class(['table-caption' => $showCaption, 'sr-only' => ! $showCaption])>{{ $caption }}</caption>@endif
        @isset($head)<thead><tr>{{ $head }}</tr></thead>@endisset
        @if($grouped){{ $slot }}@else<tbody>{{ $slot }}</tbody>@endif
        @isset($foot)<tfoot>{{ $foot }}</tfoot>@endisset
    </table>
</div>
