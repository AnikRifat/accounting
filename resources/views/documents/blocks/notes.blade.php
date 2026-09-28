@if(trim((string) $document->notes) !== '')
<div class="block"><div class="section-title">{{ __('Notes') }}</div><div>{!! nl2br(e($document->notes), false) !!}</div></div>
@endif
