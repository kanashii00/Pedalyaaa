<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops browsers and proxies from caching sensitive authenticated pages.
 *
 * After logout, pressing Back must re-request the page (not render a cached
 * copy), so the auth middleware runs again and sends the visitor to login.
 * Authenticated navigation is unaffected: Back simply refetches the page,
 * which still renders normally while the session is valid.
 */
class PreventBackHistory
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
