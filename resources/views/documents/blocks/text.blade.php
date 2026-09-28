@if(trim($section['text'] ?? '') !== '')
<div class="block">{!! nl2br(e($section['text']), false) !!}</div>
@endif
