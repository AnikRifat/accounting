<table class="brand"><tr>
    <td class="brand-cell" style="width: 45%;">
        @if($logo)<img class="logo" src="{{ $logo }}" alt="{{ $company->name }}">@else<div class="brand-name">{{ $company->name }}</div>@endif
    </td>
    <td class="brand-cell brand-details">
        @if($logo)<div class="brand-name">{{ $company->name }}</div>@endif
        @if($company->address)<div>{{ $company->address }}</div>@endif
        @if($company->phone)<div>{{ __('Phone: :phone', ['phone' => $company->phone]) }}</div>@endif
        @if($template->vat_number)<div>{{ __('BIN/VAT: :number', ['number' => $template->vat_number]) }}</div>@endif
        @if($template->header_text)<div>{!! nl2br(e($template->header_text), false) !!}</div>@endif
    </td>
</tr></table>
