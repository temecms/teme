<?php

declare(strict_types=1);

use App\Domain\Api\Llms\MarkdownPages;
use App\Domain\Feeds\FeedPath;
use App\Domain\Themes\ManifestValidator as ThemeManifestValidator;
use App\Http\Site\CommentController;
use App\Http\Site\DeclaredRouteController;
use App\Http\Site\Feeds\FeedController;
use App\Http\Site\LlmsTxtController;
use App\Http\Site\MarkdownController;
use App\Http\Site\Middleware\BareReportAnswer;
use App\Http\Site\Middleware\GuardCommentPost;
use App\Http\Site\Middleware\MarkCacheable;
use App\Http\Site\Middleware\MatchDeclaredRoutes;
use App\Http\Site\PendingCommentsController;
use App\Http\Site\PolicyReportController;
use App\Http\Site\PublicController;
use App\Http\Site\RobotsTxtController;
use App\Http\Site\SessionController;
use App\Http\Site\SitemapController;
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

// Declared public routes (plugins and core): fixed shapes that look up the
// registry, before feeds and the public fallback (docs/plans/plugin-public-routes.md).
Route::middleware(['public', 'throttle:declared-routes'])
    ->match(['get', 'post'], '.well-known/{name}', DeclaredRouteController::class)
    ->where('name', '[A-Za-z0-9._-]+')
    ->name('declared.well-known');

Route::middleware(['public', 'throttle:declared-routes'])
    ->match(['get', 'post'], '{code}/{path?}', DeclaredRouteController::class)
    // Skip reserved first segments so /api/… and /admin/… still reach their own routes and fallbacks (D3).
    ->where('code', '(?!admin(?:/|$)|api(?:/|$)|up(?:/|$)|build(?:/|$)|storage(?:/|$))'.ThemeManifestValidator::CODE_PATTERN)
    ->where('path', '.*')
    ->name('declared.plugin');

// Feeds (I-299): RSS 2.0 at WordPress's addresses. Every path ending in a feed name
// comes here; what is not a feed's goes back to PublicController. A fallback, so
// a route that names such a path itself, a plugin's or a theme's, is matched first.
Route::middleware(['public', 'throttle:site-search', MarkCacheable::class])
    ->get('{path}', FeedController::class)
    ->where('path', FeedPath::ROUTE)
    ->name('feeds.show')
    ->fallback();

// robots.txt, written by Teme (I-300) and stored by the page cache like a page.
Route::middleware(['public', MarkCacheable::class])->get('robots.txt', RobotsTxtController::class)->name('robots-txt');

// Sitemaps (I-296): the index, every page of each child and the stylesheet, stored by
// the page cache like pages; WordPress's and Yoast's index addresses sent to the index.
Route::middleware(['public', MarkCacheable::class])->group(function (): void {
    Route::get('sitemap.xml', [SitemapController::class, 'index'])->name('sitemaps.index');
    Route::get('sitemap.xsl', [SitemapController::class, 'stylesheet'])->name('sitemaps.stylesheet');
    Route::get('sitemap-{file}.xml', [SitemapController::class, 'child'])->where('file', '[a-z0-9][a-z0-9.-]*')->name('sitemaps.child');
    Route::get('wp-sitemap.xml', [SitemapController::class, 'moved'])->name('sitemaps.wordpress');
    Route::get('sitemap_index.xml', [SitemapController::class, 'moved'])->name('sitemaps.yoast');
});

// llms.txt and each listed item's Markdown copy (I-713), behind their switch:
// stored by the page cache like pages. What isn't one goes back to
// PublicController, as the feed's route does.
Route::middleware(['public', 'throttle:api', MarkCacheable::class])->group(function (): void {
    Route::get('llms.txt', LlmsTxtController::class)->name('llms-txt');
    Route::get('{path}.md', MarkdownController::class)->where('path', MarkdownPages::ROUTE)->name('markdown.show')->fallback();
});

Route::middleware('public')->group(function (): void {
    Route::get('/', [PublicController::class, 'handle'])->middleware([MatchDeclaredRoutes::class, 'throttle:site-search'])->name('home');
    Route::fallback([PublicController::class, 'handle'])->middleware([MatchDeclaredRoutes::class, 'throttle:site-search'])->name('public.path');
});
