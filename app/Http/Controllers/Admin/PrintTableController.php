<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;

/** Shows a print page prepared by App\Support\TableExport, only to the user who prepared it. */
class PrintTableController extends Controller
{
    public function __invoke(string $token): View
    {
        $page = Cache::get('table-print:'.$token);
        abort_unless(is_array($page) && $page['user_id'] === auth()->id(), 404);

        return view('print.table', ['page' => $page]);
    }
}
