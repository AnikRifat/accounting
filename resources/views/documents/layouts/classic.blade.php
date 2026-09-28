@extends('documents.shell')

{{-- Classic: logo left, company right, a rule under the title and a filled item header. --}}
@section('layout-css')
.title-cell, .meta-cell { padding-bottom: 8pt; border-bottom: 2pt solid {{ $accent }}; }
.parties td { padding-top: 2pt; }
@endsection
