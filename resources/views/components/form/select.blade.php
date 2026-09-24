@props(['name', 'label', 'options' => [], 'help' => null])
<div class="field">
    <label for="{{ $name }}">{{ $label }}</label>
    <select id="{{ $name }}" name="{{ $name }}" {{ $attributes->merge(['class' => 'form-control']) }} aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}" aria-describedby="{{ $name }}-help {{ $name }}-error">
        @foreach($options as $value => $text) <option value="{{ $value }}">{{ $text }}</option> @endforeach
    </select>
    <span id="{{ $name }}-help" class="muted">{{ $help }}</span>
    <span id="{{ $name }}-error" class="error">@error($name) {{ $message }} @enderror</span>
</div>
