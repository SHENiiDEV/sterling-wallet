<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything but the public home page (the console, the portal, the login, files) is told to stay out of search
 * results, also for responses that are not HTML, which a meta tag could not reach.
 */
class KeepPrivatePagesOutOfSearch
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->routeIs('home', 'robots', 'sitemap')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
