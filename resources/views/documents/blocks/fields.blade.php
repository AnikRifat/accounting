@if($fields !== [])
<table class="fields">
    @foreach(array_chunk($fields, 3) as $row)
        <tr>
            @foreach($row as $field)<td><div class="label">{{ $field['label'] }}</div><div>{{ $field['value'] }}</div></td>@endforeach
            @for($i = count($row); $i < 3; $i++)<td></td>@endfor
        </tr>
    @endforeach
</table>
@endif
