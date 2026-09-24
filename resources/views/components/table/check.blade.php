@props(['value', 'label'])
<td class="select-col"><input type="checkbox" class="table-check" value="{{ $value }}" wire:model="selected" aria-label="{{ __('Select :name', ['name' => $label]) }}"></td>
