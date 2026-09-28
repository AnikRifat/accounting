<?php

namespace App\Http\Middleware;

use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The admin home is the accounting dashboard; someone without it lands on the home of the first module they can open. */
class RedirectToModuleHome
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->can('dashboard.view') && ($home = array_values(Modules::available($user))[0][2] ?? null)) {
            return redirect()->route($home);
        }

        return $next($request);
    }
}
