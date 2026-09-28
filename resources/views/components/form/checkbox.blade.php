@props(['name', 'label', 'id' => null, 'value' => null, 'help' => null])
@php
    $id = $id ?? str_replace('.', '-', $name);
    $hasError = $errors->has($name);
    $describedBy = trim(($help && ! $hasError ? $id.'-help ' : '').($hasError ? $id.'-error' : ''));
@endphp
<div class="stack-sm">
    <label class="check" for="{{ $id }}"><input id="{{ $id }}" name="{{ $name }}" type="checkbox" @if($value !== null) value="{{ $value }}" @endif {{ $attributes }} @if($hasError) aria-invalid="true" @endif @if($describedBy) aria-describedby="{{ $describedBy }}" @endif><span>{{ $label }}</span></label>
    @if($hasError)<p id="{{ $id }}-error" class="error">{{ $errors->first($name) }}</p>@elseif($help)<p id="{{ $id }}-help" class="field-help">{{ $help }}</p>@endif
</div>
