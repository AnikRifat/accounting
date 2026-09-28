<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A CSV with the lead import columns and one example row. */
class LeadImportTemplateController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Phone', 'Email', 'Organisation', 'Address', 'Source', 'Service', 'Status', 'Next call', 'Notes']);
            fputcsv($out, ['Rahim Uddin', '01711000000', 'rahim@example.com', 'Rahim Traders', 'Motijheel, Dhaka', 'Facebook', '', 'New', now()->addDay()->format('Y-m-d'), 'Asked for a price list']);
            fclose($out);
        }, 'lead-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
