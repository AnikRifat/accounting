@php($appSettings = \App\Models\ApplicationSetting::values())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
    <title>{{ $page['title'] }} · {{ $appSettings['app_name'] }}</title>
    <style>
        @page { size: A4 {{ $page['orientation'] }}; margin: 14mm 12mm 16mm; @bottom-right { content: "{{ __('Page') }} " counter(page) " / " counter(pages); font: 9px system-ui, sans-serif; color: #64748b; } }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; font: 11px/1.45 'Geist', system-ui, -apple-system, 'Segoe UI', sans-serif; color: #0f172a; }
        header { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; padding-bottom: 10px; margin-bottom: 12px; border-bottom: 2px solid #0f172a; }
        h1 { margin: 0; font-size: 17px; letter-spacing: -.02em; }
        .meta { color: #475569; font-size: 10px; text-align: right; }
        .subtitle { margin-top: 2px; color: #475569; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        th { padding: 6px 8px; background: #f1f5f9; border-bottom: 1px solid #cbd5e1; font-size: 9.5px; text-align: left; text-transform: uppercase; letter-spacing: .04em; color: #334155; }
        td { padding: 5px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        tr { break-inside: avoid; }
        tbody tr:nth-child(even) td { background: #f8fafc; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .note { margin-top: 10px; color: #b45309; }
        @media print { body { padding: 0; } tbody tr:nth-child(even) td { -webkit-print-color-adjust: exact; print-color-adjust: exact; } th { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
    <header>
        <div><h1>{{ $page['title'] }}</h1>@if($page['subtitle'])<p class="subtitle">{{ $page['subtitle'] }}</p>@endif</div>
        <div class="meta"><strong>{{ $appSettings['app_name'] }}</strong><br>{{ __('Printed :date by :name', ['date' => now()->format('d M Y, h:i A'), 'name' => auth()->user()->name]) }}<br>{{ trans_choice('{1} :count row|[2,*] :count rows', count($page['rows']), ['count' => count($page['rows'])]) }}</div>
    </header>
    <table>
        <thead><tr>@foreach($page['columns'] as $column)<th @class(['num' => in_array($column['type'], ['money', 'number'], true)])>{{ $column['label'] }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse($page['rows'] as $row)
                <tr>@foreach($row as $index => $cell)<td @class(['num' => in_array($page['columns'][$index]['type'], ['money', 'number'], true)])>{{ $cell }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($page['columns']) }}">{{ __('Nothing to print.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
    @if($page['truncated'])<p class="note">{{ __('Only the first :count rows are printed. Narrow the filters or export to Excel for everything.', ['count' => number_format(\App\Support\TableExport::PRINT_LIMIT)]) }}</p>@endif
</body>
</html>
