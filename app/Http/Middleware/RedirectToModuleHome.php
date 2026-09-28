<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The admin home is the accounting dashboard; someone who only works in the CRM lands on the CRM dashboard instead. */
class RedirectToModuleHome
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->can('dashboard.view') && $user->can('crm.view')) {
            return redirect()->route('admin.crm.dashboard');
        }

        return $next($request);
    }
}
