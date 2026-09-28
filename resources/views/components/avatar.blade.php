@props(['name' => '', 'url' => null, 'size' => null])
{{-- Round profile picture: the photo when there is one, otherwise the initials of the name. Decorative; the name is shown beside it. --}}
<span {{ $attributes->class(['avatar', 'avatar-'.$size => $size]) }} aria-hidden="true">@if($url)<img src="{{ $url }}" alt="" loading="lazy">@else{{ collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->join('') ?: '?' }}@endif</span>
