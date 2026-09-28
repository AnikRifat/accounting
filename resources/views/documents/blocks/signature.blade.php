<table class="signature"><tr>
    <td style="width: 62%;"></td>
    <td class="sign-cell">
        @if($signature)<img class="sign-img" src="{{ $signature }}" alt="{{ __('Signature') }}">@else<div class="sign-space"></div>@endif
        <div class="sign-line">{{ __('Authorised signature') }}</div>
    </td>
</tr></table>
