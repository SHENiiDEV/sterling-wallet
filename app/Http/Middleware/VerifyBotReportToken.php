<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * External report bot auth: `Authorization: Bearer {BOT_REPORTS_API_KEY}`.
 */
class VerifyBotReportToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('sterling.bot_api_key');
        $given = (string) $request->bearerToken();

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Invalid bot token.'], 401);
        }

        return $next($request);
    }
}
