<?php

/**
 * Study G, S13. Scripts are strict: the site's own files or this request's
 * nonce. Styles allow inline, because components inject <style> at run time
 * and style injection is a far smaller risk — revisited when the editor
 * arrives in 1.5, which must fit the script policy, never loosen it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Middleware;

use App\Domain\Extensions\Directory\DirectoryReader;
use App\Domain\Import\Queries\ImportSources;
use App\Domain\Site\Policy\PolicySources;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

final class AdminSecurityHeaders
{
    /**
     * The request attribute the signed-in preview sets once the person may
     * see it: its theme page needs the media disk's origin and the embed
     * providers' frames as well (I-1324).
     */
    public const string PREVIEW = 'teme.preview_policy';

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        // Read after the response: the preview's controller sets it while it runs.
        $preview = $request->attributes->get(self::PREVIEW) === true ? $this->previewSources($request) : null;

        // A policy the response brought, such as a plugin's SVG icon's
        // sandbox, is kept beside the admin's: a browser enforces both, so it
        // can only narrow what the page may do, never widen it.
        $own = $response->headers->all('content-security-policy');
        $response->headers->set('Content-Security-Policy', $this->policy($nonce, $preview));

        foreach ($own as $policy) {
            $response->headers->set('Content-Security-Policy', $policy, false);
        }
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', $request->routeIs('password.reset') ? 'no-referrer' : 'strict-origin-when-cross-origin');

        return $response;
    }

    /**
     * @param  array{media: list<string>, frames: list<string>}|null  $preview
     */
    private function policy(string $nonce, ?array $preview = null): string
    {
        $devServer = $this->devServerOrigin();
        $devSocket = $devServer === '' ? '' : preg_replace('#^http#', 'ws', $devServer);
        $media = implode(' ', $preview['media'] ?? []);
        $directory = DirectoryReader::origin();
        // An import's pictures, shown from the old site while it copies them (#716).
        $imported = implode(' ', app(ImportSources::class)->pictureHosts());

        return implode('; ', array_filter([
            "default-src 'self'",
            trim("script-src 'self' 'nonce-{$nonce}' {$devServer}"),
            trim("style-src 'self' 'unsafe-inline' {$devServer}"),
            // The plugin and theme directory's own icons and screenshots, on Add plugin and Add theme.
            trim(preg_replace('/\s+/', ' ', "img-src 'self' data: {$directory} {$media} {$imported}") ?? ''),
            $preview === null ? null : trim("media-src 'self' {$media}"),
            $preview === null || $preview['frames'] === [] ? null : 'frame-src '.implode(' ', $preview['frames']),
            "font-src 'self'",
            trim("connect-src 'self' {$devServer} {$devSocket}"),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]));
    }

    /**
     * What the preview's theme page needs beyond the admin's own policy:
     * the public media disk's origin, when it isn't this one, and each
     * embed provider's frame origin, from config/embeds.php.
     *
     * @return array{media: list<string>, frames: list<string>}
     */
    private function previewSources(Request $request): array
    {
        return [
            'media' => PolicySources::media($request->getSchemeAndHttpHost()),
            'frames' => PolicySources::embedFrames(),
        ];
    }

    /**
     * The Vite dev server, while `npm run dev` runs — in the local
     * environment only.
     */
    private function devServerOrigin(): string
    {
        if (! App::isLocal() || ! Vite::isRunningHot()) {
            return '';
        }

        return rtrim(trim((string) file_get_contents(Vite::hotFile())), '/');
    }
}
