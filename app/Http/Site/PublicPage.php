<?php

/**
 * A public page rendered through the template its intent resolves to, with
 * the view model as `$page`, for the template and the document shell, and,
 * outside production, headers naming the template, its candidates and the
 * composers that ran. An error page falls back to core's own when the
 * theme's fails (I-249). A design preview's page carries a bar saying so.
 * A page core's fallback drew is a degraded render, which the page cache
 * never stores. Every page it draws carries the public site's content
 * security policy, built while it was drawn.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\CardUsage;
use App\Domain\Languages\PageLanguage;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Menus\SlotMenus;
use App\Domain\Regions\Regions;
use App\Domain\Settings\DesignPreview;
use App\Domain\Site\Insertions\InsertionOrigins;
use App\Domain\Site\PageWarnings;
use App\Domain\Site\Policy\PagePolicy;
use App\Domain\Site\Policy\PolicyMode;
use App\Domain\Site\Policy\PolicyReports;
use App\Domain\Site\ViewModels\ErrorView;
use App\Domain\Site\ViewModels\NotFoundView;
use App\Domain\Site\ViewModels\RespondView;
use App\Domain\Themes\ComposerRegistry;
use App\Domain\Themes\Resolution;
use App\Domain\Themes\TemplateResolver;
use App\Domain\Themes\ThemeWords;
use App\Domain\Themes\ViewIntent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class PublicPage
{
    /**
     * Starts a public page's state afresh: its warnings, the cards it draws,
     * its menus and its regions (B-227): one application may serve several.
     */
    public static function start(Request $request): void
    {
        app(PageWarnings::class)->start();
        app(CardUsage::class)->start();
        app(SlotMenus::class)->start($request->getPathInfo());
        app(Regions::class)->start();
    }

    /**
     * Core's page about writing a comment (I-593), started afresh, drawn as
     * kind `respond`, and never stored by any cache.
     */
    public static function respond(Request $request, RespondView $view, int $status): Response
    {
        self::start($request);
        $response = self::render(new ViewIntent('respond'), $view, $status);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * The theme's not-found page, answered 404 in place (I-249).
     */
    public static function notFound(Request $request): Response
    {
        return self::render(new ViewIntent('notfound'), new NotFoundView($request->getPathInfo()), 404);
    }

    public static function render(ViewIntent $intent, object $model, int $status = 200, int $pageNumber = 1): Response
    {
        app(PagePolicy::class)->start();
        // Insertion origins were collected while PathResolver built the article, before this start().
        app(InsertionOrigins::class)->apply(app(PagePolicy::class));
        $resolution = app(TemplateResolver::class)->resolve($intent);
        ThemeWords::use();
        self::recordFallback($resolution);
        app(ComposerRegistry::class)->startPage($intent, $model);
        self::pushDesignPreviewBar();

        // with() rather than a data array: an array keyed by the word page
        // reads, to NoTypeLiteralsTest, as a type named in code.
        return self::secured(self::described(new Response(view($resolution->view)->with('page', $model), $status), $resolution, $intent, $pageNumber));
    }

    /**
     * An HTTP error on a public route: the theme's error-{code} or error
     * template, or core's error view when the theme has neither, or its own
     * fails, which is reported.
     */
    public static function error(HttpExceptionInterface $exception): Response
    {
        app(PagePolicy::class)->start();
        $code = $exception->getStatusCode();
        $language = self::errorLanguage();
        $intent = new ViewIntent('error', code: $code);
        $model = new ErrorView($code);
        $resolution = app(TemplateResolver::class)->resolve($intent);
        ThemeWords::use();
        app(ComposerRegistry::class)->startPage($intent, $model);
        self::pushDesignPreviewBar();

        try {
            $html = view($resolution->view)->with('page', $model)->render();
        } catch (Throwable $failure) {
            report($failure);
            $resolution = new Resolution(TemplateResolver::CORE_ERROR, $resolution->candidates, true);
            $html = view(TemplateResolver::CORE_ERROR)->with('page', $model)->render();
        }

        self::recordFallback($resolution);

        $response = new Response($html, $code, $exception->getHeaders());
        $response->headers->set('Content-Language', $language);

        return self::secured(self::described($response, $resolution, $intent, 1));
    }

    /**
     * An error page's language (I-3360): the one already chosen for the
     * page, else the shown language whose prefix starts the address, else
     * the default.
     */
    private static function errorLanguage(): string
    {
        $page = app(PageLanguage::class);

        if (! $page->isSet()) {
            $languages = app(SiteLanguages::class);
            $first = explode('/', trim(request()->getPathInfo(), '/'), 2)[0];
            $tag = $first === '' ? null : $languages->byPrefix($first);
            $page->use($tag !== null && $languages->isShown($languages->stored($tag)) ? $tag : $languages->defaultTag());
        }

        return $page->tag();
    }

    /**
     * The page's content security policy, as drawn, in the header its mode
     * names (I-3375), with where browsers send reports; nothing when it is
     * off, or for the admin's signed-in preview, which keeps the admin's.
     */
    private static function secured(Response $response): Response
    {
        $header = PolicyMode::current()->header();
        $request = request();
        $route = $request->route();

        if ($header === null || ($route instanceof Route && in_array('admin', $route->gatherMiddleware(), true))) {
            return $response;
        }

        $response->headers->set($header, app(PagePolicy::class)->header($request->getSchemeAndHttpHost()));
        $response->headers->set('Reporting-Endpoints', PolicyReports::endpointsHeader());

        return $response;
    }

    /**
     * While a design preview is active, the bar that says so, above the page.
     * Rendered first and pushed just before the page renders, as the preview
     * of an article pushes its own: the view factory empties its stacks when
     * a top-level render finishes (B-247).
     */
    private static function pushDesignPreviewBar(): void
    {
        if (! app(DesignPreview::class)->active()) {
            return;
        }

        $bar = view('teme::components.design-preview-bar', [
            'back' => route('themes.index'),
        ])->render();

        View::startPush('teme-body-start', $bar);
    }

    /**
     * When core's plain fallback drew the page, for a missing or broken
     * theme or a theme error view that failed, the render is degraded, and
     * the page cache never stores it (I-262).
     */
    private static function recordFallback(Resolution $resolution): void
    {
        if ($resolution->fallback) {
            app(RenderRecord::class)->refuse(RenderRecord::DEGRADED);
        }
    }

    /**
     * Outside production, the page's template, its candidates, the
     * composers that ran and anything that went wrong drawing it, as ASCII,
     * so developer tools show them as written.
     */
    private static function described(Response $response, Resolution $resolution, ViewIntent $intent, int $pageNumber): Response
    {
        if (app()->isProduction()) {
            return $response;
        }

        $candidates = array_map(fn (array $candidate): string => $candidate['name'].($candidate['exists'] ? ' (exists)' : ' (missing)'), $resolution->candidates);
        $composers = app(ComposerRegistry::class)->ran();

        $response->headers->set('X-Teme-Template', self::ascii($resolution->view));
        $response->headers->set('X-Teme-Template-Candidates', self::ascii($candidates === [] ? 'none' : implode(', ', $candidates)));
        $response->headers->set('X-Teme-Composers', self::ascii($composers === [] ? 'none' : implode(', ', $composers)));

        $warnings = self::warnings($intent, $pageNumber);

        if ($warnings !== []) {
            $response->headers->set('X-Teme-Warnings', self::ascii(implode('; ', $warnings)));
        }

        return $response;
    }

    /**
     * What went wrong drawing the page, with, on a single page and the first
     * page of the latest posts, whether exactly one image loaded first.
     *
     * @return list<string>
     */
    private static function warnings(ViewIntent $intent, int $pageNumber): array
    {
        $warnings = app(PageWarnings::class);
        $messages = $warnings->all();

        if ($intent->kind === 'single' || ($intent->kind === 'latest' && $pageNumber === 1)) {
            $count = $warnings->priorityCount();

            if ($count === 0) {
                $messages[] = 'No priority image on this page';
            } elseif ($count > 1) {
                $messages[] = "{$count} priority images on this page";
            }
        }

        return $messages;
    }

    /**
     * A Unicode slug's letters percent-encoded, as in its address.
     */
    private static function ascii(string $value): string
    {
        return (string) preg_replace_callback('/[^\x20-\x7E]/u', fn (array $match): string => rawurlencode($match[0]), $value);
    }
}
