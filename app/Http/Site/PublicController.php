<?php

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Cache\RenderRecord;
use App\Domain\Languages\PageLanguage;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Redirects\AddressCounts;
use App\Domain\Redirects\RedirectLookup;
use App\Domain\Site\PathResolver;
use App\Domain\Site\ReaderKeys;
use App\Domain\Site\ViewModels\NotFoundView;
use App\Domain\Themes\ViewIntent;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Every public address: the home page and the fallback route, with no
 * session or cookies (I-262). PathResolver decides what the path is; this
 * turns its answer into a 301, or a page rendered through the theme, a 404
 * in place for an address with nothing at it (I-249), unless a redirect
 * answers it there (I-297).
 */
final class PublicController extends Controller
{
    public function handle(Request $request): Response
    {
        // Before the page is built: one application may serve several (B-227).
        PublicPage::start($request);

        $answer = PathResolver::resolve($request->getPathInfo(), (string) $request->getQueryString(), ReaderKeys::from($request));

        if ($answer['kind'] === 'notfound') {
            $redirected = self::redirected($request);

            if ($redirected !== null) {
                return $redirected;
            }

            // Every stored "not found" page carries it, so a pattern redirect can remove them all (I-297).
            app(RenderRecord::class)->tag(RedirectLookup::NOT_FOUND_TAG);

            // Counted in a file, written in batches by a scheduled Operation (I-298).
            app(AddressCounts::class)->notFound($request->getPathInfo(), $request->headers->get('referer'));
        }

        // The page's language, before anything of it is drawn (I-3360): its item's or term's, else its prefix's.
        $languages = app(SiteLanguages::class);
        $pageLanguage = app(PageLanguage::class);
        $pageLanguage->use($languages->of($answer['language'] ?? null));

        $response = $answer['kind'] === 'redirect'
            // Not redirect(): Laravel's URL generator trims the trailing
            // slash, and the canonical address would redirect to itself.
            ? new RedirectResponse(rtrim(url('/'), '/').($answer['redirect'] ?? '/'), 301)
            : PublicPage::render(
                $answer['intent'] ?? new ViewIntent('notfound'),
                $answer['model'] ?? new NotFoundView($request->getPathInfo()),
                $answer['kind'] === 'notfound' ? 404 : 200,
                $answer['pageNumber'] ?? 1,
            );

        if ($answer['kind'] !== 'redirect') {
            $response->headers->set('Content-Language', $pageLanguage->tag());
        }

        // Nothing at a protected item's address is ever stored, its form or its page (I-1700).
        if ($answer['private'] ?? false) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        // A search page is for readers, not for search engines' indexes (1.6h).
        if ($answer['kind'] === 'view' && ($answer['intent'] ?? null)?->kind === 'search') {
            $response->headers->set('X-Robots-Tag', 'noindex, follow');
        }

        // Which rule answered, outside production, beside the template headers.
        if (! app()->isProduction()) {
            $response->headers->set('X-Teme-Address', $answer['address']);
        }

        return $response;
    }

    /**
     * A redirect's answer where routing missed, so it never hides a live page
     * or an old address's own (I-858): a 3xx to where it sends readers, or
     * the theme's 410 or 451 page; null when none answers.
     */
    private static function redirected(Request $request): ?Response
    {
        $redirect = app(RedirectLookup::class)->answer($request->getPathInfo(), (string) $request->getQueryString());

        if ($redirect === null) {
            // An old WordPress file address of a file an import brought (I-1851).
            $file = app(RedirectLookup::class)->oldFile($request->getPathInfo());

            if ($file === null) {
                return null;
            }

            $response = new RedirectResponse($file, 301);

            if (! app()->isProduction()) {
                $response->headers->set('X-Teme-Address', 'old-file');
            }

            return $response;
        }

        // Counted in a file, written in batches by a scheduled Operation (I-297).
        app(AddressCounts::class)->hit($redirect->redirectId);

        $response = $redirect->location === null
            ? PublicPage::error(new HttpException($redirect->status))
            : new RedirectResponse($redirect->location, $redirect->status);

        // Which rule answered, outside production, as for every public address.
        if (! app()->isProduction()) {
            $response->headers->set('X-Teme-Address', 'redirect');
        }

        return $response;
    }
}
