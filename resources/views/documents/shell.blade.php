{{-- A rendered document, shared by every base layout (documents/layouts/*): the layout adds its own CSS, the blocks come in the template's order. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $heading }} {{ $document->displayNumber() }} · {{ $company->name }}</title>
    <style>
        @include('documents.partials.base-css')
        @yield('layout-css')
    </style>
</head>
<body>
    @if($toolbar && ! $pdf){{ $toolbar }}@endif
    <div class="sheet">
        @if($watermark && ! $pdf)<div class="watermark" aria-hidden="true">{{ $watermark }}</div>@endif
        @foreach($sections as $section)
            @include('documents.blocks.'.$section['key'])
        @endforeach
    </div>
</body>
</html>
