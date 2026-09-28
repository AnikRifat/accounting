@if(trim((string) $template->footer_text) !== '')
<div class="footer">{!! nl2br(e($template->footer_text), false) !!}</div>
@endif
