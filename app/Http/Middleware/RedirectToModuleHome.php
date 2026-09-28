<?php

namespace App\Http\Middleware;

use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin home is the accounting dashboard; someone who can't open Accounting (no permission, or the module is
 * disabled) lands on the home of the first module they can open.
 */
class RedirectToModuleHome
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $modules = $user ? Modules::available($user) : [];
        if ($user && ! isset($modules[Modules::ACCOUNTING])) {
            if ($home = array_values($modules)[0][2] ?? null) {
                return redirect()->route($home);
            }
            abort_unless(Modules::enabled(Modules::ACCOUNTING), 404);
        }

        return $next($request);
    }
}
