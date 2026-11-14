<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;

/**
 * Lets a request name the site's own host, APP_URL's, and no other
 * (I-380), so a forged Host header builds no link and fills no cache. A
 * health check made inside the container, whose connection comes from the
 * container itself, may also name localhost.
 */
final class TrustSiteHost extends TrustHosts
{
    /** The connection's address when a check runs inside the container. */
    private const array LOOPBACK = ['127.0.0.1', '::1'];

    /**
     * @param  Closure(Request): mixed  $next
     */
    public function handle(Request $request, $next): mixed
    {
        // Symfony keeps the list for the whole process: this request's alone.
        Request::setTrustedHosts($this->shouldSpecifyTrustedHosts() ? $this->hostsFor($request) : []);

        try {
            return $next($request);
        } finally {
            // Clear the list once the request is answered, so no later
            // request or code inherits it.
            Request::setTrustedHosts([]);
        }
    }

    /**
     * The host patterns this request may name: APP_URL's, and the loopback
     * names when its connection comes from the container itself.
     *
     * @return list<string>
     */
    public function hostsFor(Request $request): array
    {
        $patterns = [];
        $site = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($site !== '') {
            $patterns[] = '^'.preg_quote($site).'$';
        }

        if (in_array($request->server->get('REMOTE_ADDR'), self::LOOPBACK, true)) {
            array_push($patterns, '^localhost$', '^127\.0\.0\.1$', '^\[::1\]$');
        }

        return $patterns;
    }
}
