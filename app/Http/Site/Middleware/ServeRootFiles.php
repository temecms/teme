<?php

declare(strict_types=1);

namespace App\Http\Site\Middleware;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\PublicRoutes\RootFile;
use App\Domain\PublicRoutes\RootFiles;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as PlainResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers a plugin's root file (docs/guides/developers/plugins/root-files.md) in front of the public site's
 * fallback: a GET for /{name}.txt that an enabled plugin's `rootFiles`
 * answers gets its body as plain text, never indexed and never stored. Any
 * other request passes on unchanged, so redirects and the "not found" page
 * still apply; a stored "not found" at such a path carries the root-files
 * tag, which a plugin with root files purges.
 */
final class ServeRootFiles
{
    public function __construct(private readonly RootFiles $rootFiles) {}

    public function handle(Request $request, Closure $next): Response
    {
        $name = ltrim($request->getPathInfo(), '/');

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }

        if (preg_match(RootFile::NAME, $name) !== 1) {
            return $next($request);
        }

        $body = $this->rootFiles->find($name);

        if ($body === null) {
            app(RenderRecord::class)->tag(PageTags::ROOT_FILES);

            return $next($request);
        }

        return new PlainResponse($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Robots-Tag' => 'noindex',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }
}
