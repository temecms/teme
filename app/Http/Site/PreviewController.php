<?php

/**
 * Shows one revision of one article to someone with no account, through a
 * preview link, exactly as the published page would look: the same theme,
 * template and document shell, with a bar above it saying what it is. It
 * asks the domain for the link, records the view, and renders through
 * PublicPage. A bad, expired or revoked token gets one and the same 404
 * page, the "Link expired" card, never a 403 and never different words for
 * different causes, which would confirm the link once existed. A preview,
 * and that card, is never indexed or cached, and its view is not recorded.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Cards\CardUsage;
use App\Domain\Contents\PreviewLinkViews;
use App\Domain\Contents\Queries\PreviewLinks;
use App\Domain\Languages\PageLanguage;
use App\Domain\Menus\SlotMenus;
use App\Domain\Regions\Regions;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\ItemLanguage;
use App\Domain\Site\PageWarnings;
use App\Domain\Site\SiteDates;
use App\Domain\Site\SiteLanguage;
use App\Domain\Themes\ViewIntent;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;

final class PreviewController extends Controller
{
    public function show(Request $request, string $token, PreviewLinks $links, PreviewLinkViews $views, ArticleViews $articles, SiteLanguage $language): Response
    {
        // Before the page is built: one application may serve several (B-227).
        app(PageWarnings::class)->start();
        app(CardUsage::class)->start();
        app(SlotMenus::class)->start($request->getPathInfo());
        app(Regions::class)->start();

        $preview = $links->resolve($token);

        if ($preview === null) {
            return $this->expired();
        }

        // Recorded so the writer can see the link was opened. Not a login:
        // nothing about the viewer is known or stored.
        $views->record($preview['linkId']);

        // Drawn in the item's own language (I-3360).
        ItemLanguage::use($preview['contentId']);
        $page = $articles->ofRevision($preview['contentId'], $preview['revisionId']);

        // Rendered before the page: the view factory empties its stacks once
        // a render finishes, so the bar is pushed only after its own.
        $bar = view('teme::components.preview-bar', [
            'expires' => SiteDates::show(SiteDates::inSite($preview['expiresAt']), app(PageLanguage::class)->tag(), 'LLL'),
        ])->render();
        View::startPush('teme-body-start', $bar);

        $response = PublicPage::render(new ViewIntent('single', type: $page->type, template: $page->template), $page);

        // Shared by hand, so search engines never list it, and never kept by a cache.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * The one answer for a token that does not open: the standalone card
     * saying the link has expired, with the same headers as a preview.
     */
    private function expired(): Response
    {
        $response = response()->view('errors.preview-expired', status: 404);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
