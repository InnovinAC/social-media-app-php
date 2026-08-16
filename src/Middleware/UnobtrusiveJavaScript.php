<?php

declare(strict_types=1);

namespace Phpvin\Middleware;

use Closure;
use Phpvin\Http\Request;
use Phpvin\Http\Response;

/**
 * The server half of phpvin.js.
 *
 * XMLHttpRequest follows redirects itself and hands the browser the *final*
 * page, which is useless to a script that wanted a fragment: jQuery would swap
 * a whole HTML document into your `<div>`. This converts a redirect made
 * during an AJAX request into an empty 204 carrying the destination in a
 * header, and phpvin.js navigates there.
 *
 * The upshot is that one controller action can serve both worlds: redirect as
 * usual, and it does the right thing whether the form was posted normally or
 * over AJAX.
 */
final class UnobtrusiveJavaScript implements Middleware
{
    public const LOCATION_HEADER = 'X-Phpvin-Location';

    public function process(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isAjax() || ! $response->isRedirect()) {
            return $response;
        }

        $location = $response->getHeader('location');

        if ($location === null) {
            return $response;
        }

        return (new Response('', 204))->header(self::LOCATION_HEADER, $location);
    }
}
