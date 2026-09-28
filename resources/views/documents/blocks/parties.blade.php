@if($party)
<table class="parties"><tr>
    <td style="width: 60%;">
        <div class="label">{{ $partyLabel }}</div>
        <div class="party-name">{{ $party->name }}</div>
        @if($party->address)<div>{{ $party->address }}</div>@endif
        @if($party->phone)<div>{{ $party->phone }}</div>@endif
        @if($party->email)<div>{{ $party->email }}</div>@endif
    </td>
    <td></td>
</tr></table>
@endif
