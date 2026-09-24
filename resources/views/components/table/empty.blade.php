@props(['colspan', 'emoji' => '📭'])
<tr><td class="table-empty" colspan="{{ $colspan }}"><span class="table-empty-emoji" aria-hidden="true">{{ $emoji }}</span>{{ $slot }}</td></tr>
