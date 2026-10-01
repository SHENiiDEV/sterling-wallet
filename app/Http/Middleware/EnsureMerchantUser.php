<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMerchantUser
{
    /**
     * The merchant portal: active merchant users linked to a company.
     * Staff are sent to the admin panel.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isStaff()) {
            return redirect()->route('admin.dashboard');
        }
        abort_unless($user?->isMerchantUser(), 403, 'Your portal access is not active. Contact your account manager.');

        return $next($request);
    }
}
