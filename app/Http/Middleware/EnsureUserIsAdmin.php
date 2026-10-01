<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Only active staff (super admins and admins) may enter the admin panel.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Merchant users have their own portal.
        if ($request->user()?->isMerchantUser()) {
            return redirect()->route('portal.dashboard');
        }
        abort_unless($request->user()?->isStaff(), 403);

        return $next($request);
    }
}
