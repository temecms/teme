<?php

/**
 * The public site's routes: every address a visitor reads.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Api\Llms\MarkdownPages;
use App\Domain\Feeds\FeedPath;
use App\Domain\Themes\ManifestValidator as ThemeManifestValidator;
use App\Http\Site\AdsTxtController;
use App\Http\Site\CommentController;
use App\Http\Site\DeclaredRouteController;
use App\Http\Site\Feeds\FeedController;
use App\Http\Site\LlmsTxtController;
use App\Http\Site\MarkdownController;
use App\Http\Site\Middleware\BareReportAnswer;
use App\Http\Site\Middleware\GuardCommentPost;
use App\Http\Site\Middleware\MarkCacheable;
use App\Http\Site\Middleware\MatchDeclaredRoutes;
use App\Http\Site\Middleware\ServeRootFiles;
use App\Http\Site\PendingCommentsController;
use App\Http\Site\PolicyReportController;
use App\Http\Site\PublicController;
use App\Http\Site\RegionFragmentController;
use App\Http\Site\RobotsTxtController;
use App\Http\Site\SessionController;
use App\Http\Site\SitemapController;
use App\Http\Site\SuggestController;
use App\Http\Site\UnlockController;
use Illuminate\Support\Facades\Route;

// The public site: loaded after every other route file, in its own group
// with no session, cookies or CSRF (I-262), so 1.7's page cache can store
// its pages, and beside it core's /_teme/session. The fallback sees only
// what no other route matched.

// Who is reading, for core's script on every public page (I-163): kept by
// no cache, and answered with no session unless the browser carries the
// signed-in marker (the reader group, bootstrap/app.php).
Route::get('_teme/session', SessionController::class)->middleware('reader')->name('teme.session');

// Core's comment addresses: the form's post (I-593), a comment's own address,
// which finds its page (I-272), the reply page without JavaScript (I-594), and
// this browser's own comments, for core's script (1.8b′).
// Reserved (_teme), so the page cache passes them by and nothing stores them.
Route::post('_teme/comments', [CommentController::class, 'store'])->middleware(GuardCommentPost::class)->name('teme.comments.store');
Route::get('_teme/comment/{comment}', [CommentController::class, 'show'])->where('comment', '[0-9a-z]{26}')->name('teme.comments.show');
Route::get('_teme/comment/{comment}/reply', [CommentController::class, 'reply'])->where('comment', '[0-9a-z]{26}')->name('teme.comments.reply');
Route::get('_teme/comments/pending', PendingCommentsController::class)->name('teme.comments.pending');

// The password form's post for an item protected by one (I-1700): no session and no token,
// tries counted per address; reserved (_teme), so the page cache passes it by.
Route::post('_teme/unlock', UnlockController::class)->name('teme.unlock');

// Browsers' policy reports (I-3375): anyone may send one, so no session and no token (a report
// carries no authority to borrow), a count of reports per address and per site, and a spool
// instead of a table; reserved (_teme), so the page cache passes it by.
Route::post('_teme/csp', PolicyReportController::class)->middleware([BareReportAnswer::class, 'throttle:csp-reports'])->name('teme.csp');

// Search suggestions for a theme's overlay: read-only JSON, counted per address, stored by the
// page cache for a short time and kept apart by its query and language (docs/guides/developers/themes/archive-pages.md).
Route::middleware(['public', 'throttle:suggest', MarkCacheable::class])->get('_teme/suggest', SuggestController::class)->name('teme.suggest');

// A region that waits for a viewport width, answered to core's script: HTML, stored by the page cache
// like the page and kept apart by its name and language (docs/guides/developers/themes/regions.md).
Route::middleware(['public', 'throttle:region-fragment'])->get('_teme/region', RegionFragmentController::class)->name('teme.region');

// Exact public addresses before any catch-all fallback (docs/plans/plugin-public-routes.md).
Route::middleware(['public', MarkCacheable::class])->get('robots.txt', RobotsTxtController::class)->name('robots-txt');

// ads.txt from Settings › Site code (I-064), stored by the page cache like robots.txt.
Route::middleware(['public', MarkCacheable::class])->get('ads.txt', AdsTxtController::class)->name('ads-txt');

// Sitemaps (I-296): the index, every page of each child and the stylesheet, stored by
// the page cache like pages; WordPress's and Yoast's index addresses sent to the index.
Route::middleware(['public', MarkCacheable::class])->group(function (): void {
    Route::get('sitemap.xml', [SitemapController::class, 'index'])->name('sitemaps.index');
    Route::get('sitemap.xsl', [SitemapController::class, 'stylesheet'])->name('sitemaps.stylesheet');
    Route::get('sitemap-{file}.xml', [SitemapController::class, 'child'])->where('file', '[a-z0-9][a-z0-9.-]*')->name('sitemaps.child');
    Route::get('wp-sitemap.xml', [SitemapController::class, 'moved'])->name('sitemaps.wordpress');
    Route::get('sitemap_index.xml', [SitemapController::class, 'moved'])->name('sitemaps.yoast');
});

Route::middleware(['public', 'throttle:api', MarkCacheable::class])->get('llms.txt', LlmsTxtController::class)->name('llms-txt');

// Declared well-known addresses: exact enough to stay a normal route (docs/plans/plugin-public-routes.md).
Route::middleware(['public', 'throttle:declared-routes'])
    ->match(['get', 'post'], '.well-known/{name}', DeclaredRouteController::class)
    ->where('name', '[A-Za-z0-9._-]+')
    ->name('declared.well-known');

// Fallbacks in order (docs/plans/plugin-public-routes.md): feeds and .md keep their route names for
// HoldUntilSetUp and beat the declared catch-all; late-registered probes
// (e.g. `_probe.md`) still win as ordinary routes. Declared {code}/{path?}
// is next so plugin paths answer before the public site; miss falls through
// to FeedController then PublicController. public.path is last.
Route::middleware(['public', 'throttle:declared-routes', MatchDeclaredRoutes::class, MarkCacheable::class])
    ->get('{path}', FeedController::class)
    ->where('path', FeedPath::ROUTE)
    ->name('feeds.show')
    ->fallback();

Route::middleware(['public', 'throttle:api', 'throttle:declared-routes', MatchDeclaredRoutes::class, MarkCacheable::class])
    ->get('{path}.md', MarkdownController::class)
    ->where('path', MarkdownPages::ROUTE)
    ->name('markdown.show')
    ->fallback();

Route::middleware(['public', 'throttle:declared-routes'])
    ->match(['get', 'post'], '{code}/{path?}', DeclaredRouteController::class)
    // Skip reserved first segments so /api/… and /admin/… still reach their own routes and fallbacks.
    ->where('code', '(?!admin(?:/|$)|api(?:/|$)|up(?:/|$)|build(?:/|$)|storage(?:/|$))'.ThemeManifestValidator::CODE_PATTERN)
    ->where('path', '.*')
    ->name('declared.plugin')
    ->fallback();

// Declared routes are counted before they answer; with no match the
// limiter counts a search as site-search does.
Route::middleware('public')->group(function (): void {
    Route::get('/', [PublicController::class, 'handle'])->middleware(['throttle:declared-routes', MatchDeclaredRoutes::class])->name('home');
    Route::post('/', DeclaredRouteController::class)->middleware('throttle:declared-routes')->name('declared.home');
    // A plugin's root file answers before any declared route or page (docs/guides/developers/plugins/root-files.md).
    Route::fallback([PublicController::class, 'handle'])->middleware(['throttle:declared-routes', ServeRootFiles::class, MatchDeclaredRoutes::class])->name('public.path');
});
