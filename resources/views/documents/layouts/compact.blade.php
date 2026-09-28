@extends('documents.shell')

{{-- Compact: smaller type and tighter spacing, for long item lists. --}}
@section('layout-css')
body { font-size: 8.5pt; line-height: 1.35; }
.brand { margin-bottom: 8pt; }
.brand-name { font-size: 12pt; }
.doc-title { font-size: 14pt; }
.title-row { margin-bottom: 8pt; }
.title-cell, .meta-cell { padding-top: 6pt; border-top: 0.75pt solid #d1d5db; }
.parties, .fields, .totals-wrap { margin-bottom: 8pt; }
.items th { background-color: #ffffff; color: {{ $accent }}; border-bottom: 1pt solid {{ $accent }}; padding: 3pt 4pt; }
.items td { padding: 3pt 4pt; }
.totals td { padding: 2pt 4pt; }
.block { margin-bottom: 8pt; }
.signature { margin: 10pt 0 8pt; }
@endsection
