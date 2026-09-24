@props(['name', 'label', 'type' => 'text', 'help' => null, 'id' => null])
@php
    $id = $id ?? str_replace('.', '-', $name);
    $hasError = $errors->has($name);
    $describedBy = trim(($help && ! $hasError ? $id.'-help ' : '').($hasError ? $id.'-error' : ''));
@endphp
<div class="field">
    <label for="{{ $id }}">{{ $label }}@if($attributes->has('required'))<span class="required-mark" aria-hidden="true"> *</span>@endif</label>
    @if($type === 'search')<div class="input-search"><x-icon name="search" />@endif
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" {{ $attributes->merge(['class' => 'form-control']) }} aria-invalid="{{ $hasError ? 'true' : 'false' }}" @if($describedBy) aria-describedby="{{ $describedBy }}" @endif>
    @if($type === 'search')</div>@endif
    @if($hasError)<p id="{{ $id }}-error" class="error">{{ $errors->first($name) }}</p>@elseif($help)<p id="{{ $id }}-help" class="field-help">{{ $help }}</p>@endif
</div>
