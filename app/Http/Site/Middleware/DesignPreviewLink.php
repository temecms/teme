<?php

declare(strict_types=1);

namespace App\Http\Site\Middleware;

use App\Domain\Design\Operations\CreateDesignPreview;
use App\Domain\Settings\DesignPreview;
use App\Domain\Themes\ActiveTheme;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Opens a design preview for one public request when its link is signed and
 * unexpired, and refuses a bad link with a 403 rather than showing live
 * values as if they were the preview. A preview's response is never cached
 * or indexed. First in the public group.
 */
final class DesignPreviewLink
{
    private const array PRIVATE_HEADERS = [
        'Cache-Control' => 'no-store, private',
        'X-Robots-Tag' => 'noindex, nofollow',
    ];

    public function __construct(
        private readonly DesignPreview $preview,
        private readonly ActiveTheme $theme,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $previewing = $request->query->has(CreateDesignPreview::PARAMETER);

        if ($previewing && ! URL::hasValidSignature($request)) {
            $this->switchTo(false);

            throw new HttpException(403, 'This preview link has expired.', headers: self::PRIVATE_HEADERS);
        }

        // Set on every request, active or not: a process serving several
        // requests keeps scoped services between them (B-227).
        $this->switchTo($previewing);

        $response = $next($request);

        if ($previewing) {
            $response->headers->add(self::PRIVATE_HEADERS);
        }

        return $response;
    }

    /**
     * ActiveTheme reads `design.theme` once and keeps it, so it reads again
     * when this request sees other values than the one before.
     */
    private function switchTo(bool $previewing): void
    {
        if ($this->preview->set($previewing)) {
            $this->theme->forget();
        }
    }
}
