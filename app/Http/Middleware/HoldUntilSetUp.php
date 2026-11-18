<?php

/**
 * Holds the public site and the API at a 503 until the site has its first
 * account (issue 246, I-3377): core's own page, before any theme runs, never
 * stored.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Api\ApiPaths;
use App\Domain\Api\ApiWords;
use App\Domain\Api\Problem;
use App\Domain\Api\ProblemCode;
use App\Domain\Api\Problems;
use App\Domain\Users\Queries\FirstRun;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class HoldUntilSetUp
{
    /** Seconds a client is asked to wait: WordPress's figure for its own maintenance page (wp_maintenance()). */
    public const int RETRY_AFTER = 600;

    /** The page has no script, image or form, only inline style, and is drawn outside PublicPage, which writes the site's own policy. */
    private const string POLICY = "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

    public function __construct(private FirstRun $firstRun) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->firstRun->needed()) {
            return $next($request);
        }

        $headers = ['Retry-After' => (string) self::RETRY_AFTER, 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex'];

        if (ApiPaths::is($request->getPathInfo())) {
            return Problems::answer(
                new Problem(503, ProblemCode::Unavailable, ApiWords::settingUp(), $request->getPathInfo()),
                ['Retry-After' => $headers['Retry-After'], 'Cache-Control' => 'no-store'],
            );
        }

        // Not HTML: one plain line, as Drupal answers these in maintenance.
        if ($request->routeIs('feeds.show', 'robots-txt', 'sitemaps.*', 'llms-txt', 'markdown.show')) {
            return new Response(__('This site is being set up and will open soon.').PHP_EOL, 503, [...$headers, 'Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return new Response(view('teme::holding', [
            'address' => (string) parse_url((string) config('app.url'), PHP_URL_HOST),
            'setupUrl' => route('first-run.show'),
        ])->render(), 503, [...$headers, 'Content-Type' => 'text/html; charset=UTF-8', 'Content-Security-Policy' => self::POLICY]);
    }
}
