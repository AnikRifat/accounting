@props(['name', 'label', 'type' => 'text', 'help' => null, 'id' => null])
@php($id = $id ?? str_replace('.', '-', $name))
<div class="field">
    <label for="{{ $id }}">{{ $label }} @if($attributes->has('required')) <span aria-hidden="true">*</span> @endif</label>
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" {{ $attributes->merge(['class' => 'form-control']) }} aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}" aria-describedby="{{ $id }}-help {{ $id }}-error">
    <span id="{{ $id }}-help" class="muted">{{ $help }}</span>
    <span id="{{ $id }}-error" class="error">@error($name) {{ $message }} @enderror</span>
</div>
