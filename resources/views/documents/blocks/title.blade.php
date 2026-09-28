<table class="title-row"><tr>
    <td class="title-cell" style="width: 55%;">
        <div class="doc-title">{{ $heading }}</div>
        @if($heading !== $type->label())<div class="muted">{{ $type->label() }}</div>@endif
        @if($watermark)<div class="status">{{ $watermark }}</div>@endif
    </td>
    <td class="meta-cell">
        <table class="meta">
            <tr><td class="label">{{ __('Number') }}</td><td class="num">{{ $document->displayNumber() }}</td></tr>
            <tr><td class="label">{{ __('Date') }}</td><td class="num">{{ $document->issue_date?->format('d M Y') }}</td></tr>
            @if($document->due_date && $type->dueLabel())<tr><td class="label">{{ $type->dueLabel() }}</td><td class="num">{{ $document->due_date->format('d M Y') }}</td></tr>@endif
            @if($document->reference)<tr><td class="label">{{ __('Reference') }}</td><td class="num">{{ $document->reference }}</td></tr>@endif
        </table>
    </td>
</tr></table>
