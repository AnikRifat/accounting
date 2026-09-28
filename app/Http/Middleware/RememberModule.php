<?php

namespace App\Http\Middleware;

use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Remembers the module of the page, so shared pages keep showing that module's navigation. */
class RememberModule
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($module = Modules::forRoute($request->route()?->getName())) {
            $request->session()->put(Modules::SESSION_KEY, $module);
        }

        return $next($request);
    }
}
