{{-- CSS shared by documents and receipts. mPDF reads it too: table layout, pt sizes, no flex or grid. --}}
body { margin: 0; font-family: {!! $fontStack !!}; font-size: 9.5pt; line-height: 1.45; color: #1f2937; }
table { width: 100%; border-collapse: collapse; }
td, th { vertical-align: top; }
p { margin: 0 0 6pt; }
.block { margin-bottom: 12pt; }
.muted { color: #6b7280; }
.num { text-align: right; white-space: nowrap; }
.label { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.4pt; color: #6b7280; }
.section-title { font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.4pt; color: {{ $accent }}; margin-bottom: 3pt; }
.brand { margin-bottom: 14pt; }
.brand-cell { padding: 0; }
.brand-details { text-align: right; font-size: 8.5pt; color: #4b5563; }
.brand-name { font-size: 14pt; font-weight: bold; color: {{ $accent }}; }
.logo { max-height: 18mm; max-width: 60mm; }
.title-row { margin-bottom: 14pt; }
.doc-title { font-size: 20pt; font-weight: bold; color: {{ $accent }}; line-height: 1.15; }
.status { font-size: 9pt; font-weight: bold; color: #b91c1c; text-transform: uppercase; letter-spacing: 1pt; }
.meta td { padding: 1pt 0 1pt 10pt; font-size: 9pt; }
.meta .label { padding-top: 2.5pt; }
.party-name { font-size: 11pt; font-weight: bold; color: #111827; }
.parties { margin-bottom: 14pt; }
.fields { margin-bottom: 12pt; }
.fields td { padding: 0 10pt 4pt 0; width: 33%; }
.items-wrap { margin-bottom: 10pt; }
.items th { background-color: {{ $accent }}; color: #ffffff; font-size: 8pt; font-weight: bold; text-align: left; padding: 5pt 6pt; }
.items td { padding: 5pt 6pt; border-bottom: 0.5pt solid #e5e7eb; }
.items th.num, .items td.num { text-align: right; }
.items .index { width: 18pt; color: #6b7280; }
.totals-wrap { margin-bottom: 14pt; }
.totals td { padding: 3pt 6pt; }
.totals .grand { border-top: 1pt solid {{ $accent }}; font-weight: bold; font-size: 11pt; color: #111827; padding-top: 5pt; }
.totals .balance { background-color: #f3f4f6; font-weight: bold; color: #111827; }
.contract-body { margin-bottom: 14pt; font-size: 10pt; line-height: 1.6; }
.contract-body p { margin: 0 0 8pt; }
.signature { margin: 18pt 0 12pt; }
.sign-cell { text-align: center; }
.sign-img { max-height: 20mm; max-width: 55mm; }
.sign-space { height: 16mm; }
.sign-line { border-top: 0.75pt solid #374151; padding-top: 3pt; font-size: 8.5pt; color: #374151; }
.footer { border-top: 0.5pt solid #d1d5db; padding-top: 6pt; margin-top: 10pt; text-align: center; font-size: 8pt; color: #6b7280; }
.receipt-amount { font-size: 20pt; font-weight: bold; color: {{ $accent }}; }
.receipt-details td { padding: 6pt 0; border-bottom: 0.5pt solid #e5e7eb; }
.receipt-details .label { width: 35%; padding-top: 7.5pt; }
@if(! $pdf)
html { background: #e5e7eb; }
body { padding: 24px 12px; }
.sheet { position: relative; overflow: hidden; box-sizing: border-box; max-width: 210mm; min-height: 297mm; margin: 0 auto; padding: 14mm; background: #ffffff; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.12), 0 8px 24px rgba(15, 23, 42, 0.08); }
.items-wrap { overflow-x: auto; }
.logo, .sign-img { height: auto; }
.watermark { position: absolute; top: 38%; left: 0; right: 0; text-align: center; font-size: 96pt; font-weight: bold; letter-spacing: 8pt; color: rgba(185, 28, 28, 0.08); transform: rotate(-30deg); pointer-events: none; }
.doc-toolbar { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; max-width: 210mm; margin: 0 auto 12px; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; }
.tb-btn { display: inline-block; padding: 8px 14px; border: 1px solid #cbd5e1; border-radius: 8px; background: #ffffff; color: #0f172a; font: 600 13px/1.2 system-ui, -apple-system, 'Segoe UI', sans-serif; text-decoration: none; cursor: pointer; }
.tb-btn:hover { background: #f8fafc; }
.tb-btn:focus-visible { outline: 2px solid {{ $accent }}; outline-offset: 2px; }
.tb-primary { border-color: {{ $accent }}; background: {{ $accent }}; color: #ffffff; }
.tb-primary:hover { background: {{ $accent }}; opacity: 0.9; }
.tb-back { margin-right: auto; }
@media (max-width: 640px) { body { padding: 12px 8px; } .sheet { padding: 16px; min-height: 0; } .doc-title { font-size: 16pt; } }
@media print {
    @page { size: A4; margin: 14mm; }
    html { background: #ffffff; }
    body { padding: 0; }
    .sheet { max-width: none; min-height: 0; padding: 0; box-shadow: none; }
    .doc-toolbar { display: none; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
@endif
