@props(['name', 'label', 'id' => null, 'value' => null])
@php($id = $id ?? str_replace('.', '-', $name))
<div>
    <label class="check" for="{{ $id }}"><input id="{{ $id }}" name="{{ $name }}" type="checkbox" @if($value !== null) value="{{ $value }}" @endif {{ $attributes }} aria-describedby="{{ $id }}-error"><span>{{ $label }}</span></label>
    <span id="{{ $id }}-error" class="error">@error($name) {{ $message }} @enderror</span>
</div>
