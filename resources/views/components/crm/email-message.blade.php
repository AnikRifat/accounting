@props(['email'])
{{-- Subject of a logged lead email, with the full message (and, when it failed, the mail server's answer) behind a disclosure. --}}
<p class="font-semibold text-heading">{{ $email->subject }}</p>
<details class="disclosure"><summary>{{ __('Message') }}</summary>
    <p class="mt-2">{!! nl2br(e($email->message), false) !!}</p>
    @if($email->cc)<p class="muted mt-2">{{ __('Cc: :addresses', ['addresses' => $email->cc]) }}</p>@endif
</details>
@if($email->failed())<p class="text-danger mt-2">{{ $email->error }}</p>@endif
