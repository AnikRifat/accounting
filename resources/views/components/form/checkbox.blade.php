@props(['name', 'label', 'id' => null, 'value' => null])
@php($id = $id ?? str_replace('.', '-', $name))
<div class="stack-sm">
    <label class="check" for="{{ $id }}"><input id="{{ $id }}" name="{{ $name }}" type="checkbox" @if($value !== null) value="{{ $value }}" @endif {{ $attributes }} @error($name) aria-describedby="{{ $id }}-error" aria-invalid="true" @enderror><span>{{ $label }}</span></label>
    @error($name)<p id="{{ $id }}-error" class="error">{{ $message }}</p>@enderror
</div>
