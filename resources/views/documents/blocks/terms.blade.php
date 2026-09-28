@if(trim((string) $document->terms) !== '')
<div class="block"><div class="section-title">{{ __('Terms and conditions') }}</div><div>{!! nl2br(e($document->terms), false) !!}</div></div>
@endif
