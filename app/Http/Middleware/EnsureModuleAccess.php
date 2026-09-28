<?php

namespace App\Http\Middleware;

use App\Enums\Module;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `module:reports` — the signed-in staff member must have that module.
 */
class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        $user = $request->user();

        foreach ($modules as $module) {
            abort_unless($user?->canAccess(Module::from($module)), 403);
        }

        return $next($request);
    }
}
