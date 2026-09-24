@props(['value', 'signed' => false])
{{-- Taka amount from integer paisa. `signed` colours positive green and negative red (net figures only). --}}
<span {{ $attributes->class(['money', 'money-positive' => $signed && $value > 0, 'money-negative' => $signed && $value < 0]) }}>{{ \App\Support\Money::format((int) $value) }}</span>
