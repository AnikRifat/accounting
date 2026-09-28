@if(trim((string) $template->bank_details) !== '')
<div class="block"><div class="section-title">{{ __('Bank details') }}</div><div>{!! nl2br(e($template->bank_details), false) !!}</div></div>
@endif
