<?php

namespace App\Http\Middleware;

use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Pages that create company data need one active company in the header; otherwise ask for it first. */
class EnsureCompanySelected
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(CompanyContext::class)->company()?->is_active) {
            return redirect()->route('admin.choose-company', ['next' => $request->getRequestUri()]);
        }

        return $next($request);
    }
}
