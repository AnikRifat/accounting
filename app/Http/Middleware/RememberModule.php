<?php

namespace App\Http\Middleware;

use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers the module of the page, so shared pages keep showing that module's navigation, and hides the pages of a
 * disabled module as 404. The admin home is left to RedirectToModuleHome, which sends it to the first open module.
 * Also runs on Livewire updates (persistent middleware), so a disabled module's components can't be driven either.
 */
class RememberModule
{
    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();
        if ($module = Modules::forRoute($routeName)) {
            abort_if(! Modules::enabled($module) && $routeName !== 'admin.dashboard', 404);
            $request->session()->put(Modules::SESSION_KEY, $module);
        }

        return $next($request);
    }
}
