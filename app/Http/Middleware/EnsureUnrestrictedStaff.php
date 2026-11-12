<?php

namespace App\Http\Middleware;

use App\Support\ProductScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `unrestricted` — pages that look across products (volumes, card traffic) are closed to a product-restricted
 * partner such as a C2B partner.
 */
class EnsureUnrestrictedStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(ProductScope::current() !== null, 403);

        return $next($request);
    }
}
