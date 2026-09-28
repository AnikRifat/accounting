@extends('documents.shell')

{{-- Modern: a coloured band across the top, a large title and a light item header with an accent rule. --}}
@section('layout-css')
.brand-cell { background-color: {{ $accent }}; color: #ffffff; padding: 12pt 14pt; }
.brand-details, .brand-name { color: #ffffff; }
.brand { margin-bottom: 18pt; }
.doc-title { font-size: 24pt; letter-spacing: -0.3pt; }
.items th { background-color: #f3f4f6; color: {{ $accent }}; border-bottom: 1.5pt solid {{ $accent }}; }
.totals .grand { background-color: {{ $accent }}; color: #ffffff; border-top: 0; }
.section-title { border-bottom: 0.5pt solid #e5e7eb; padding-bottom: 2pt; }
@endsection
