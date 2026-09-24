@props(['tone' => 'neutral', 'dot' => false])
{{-- Status chip: soft tint background with strong text. Tones: neutral, success, warning, danger, info, primary. --}}
<span {{ $attributes->class(['badge', 'badge-'.$tone => $tone !== 'neutral', 'badge-dot' => $dot]) }}>{{ $slot }}</span>
