<?php

/**
 * Shows a person the latest saved version of an item they may edit, as
 * readers would see it once published: the active theme's page for the
 * item's working revision, under a bar that says what it is, with no site
 * code. It writes nothing, so no Operation runs, and the page is never
 * indexed or kept.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Contents;

use App\Domain\Cards\CardUsage;
use App\Domain\Contents\Queries\WorkingPreview;
use App\Domain\Design\SiteDesign;
use App\Domain\Menus\SlotMenus;
use App\Domain\Regions\Regions;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\PageWarnings;
use App\Domain\SiteCode\SiteCodeWithheld;
use App\Domain\Themes\ViewIntent;
use App\Http\Admin\Middleware\AdminSecurityHeaders;
use App\Http\Controllers\Controller;
use App\Http\Site\PublicPage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;

final class ContentPreviewController extends Controller
{
    public function __invoke(Request $request, string $content, WorkingPreview $previews, ArticleViews $articles): Response
    {
        $preview = $previews->for($content, (string) $request->user()?->getAuthIdentifier());

        abort_if($preview === null, 404);
        abort_unless($preview['canEdit'], 403, __("You can't preview this item."));

        // The admin's policy with what a theme page needs, and none of the
        // site's own code on the admin's address (I-1324).
        $request->attributes->set(AdminSecurityHeaders::PREVIEW, true);
        app(SiteCodeWithheld::class)->withhold();

        // Before the page is built, as for the shared preview: one
        // application may serve several pages.
        app(PageWarnings::class)->start();
        app(CardUsage::class)->start();
        app(SlotMenus::class)->start($request->getPathInfo());
        app(SiteDesign::class)->start($request->getPathInfo());
        app(Regions::class)->start();

        $page = $articles->ofRevision($preview['contentId'], $preview['revisionId']);

        $bar = view('teme::components.preview-bar', ['expires' => null])->render();
        View::startPush('teme-body-start', $bar);

        $response = PublicPage::render(new ViewIntent('single', type: $page->type, template: $page->template), $page);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
