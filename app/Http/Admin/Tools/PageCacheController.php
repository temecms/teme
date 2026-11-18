<?php

/**
 * Tools › Page cache (I-467): how the page cache is set up, and clearing
 * it through ClearPageCache. It changes nothing itself.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools;

use App\Domain\Cache\Operations\ClearPageCache;
use App\Domain\Cache\Operations\Input\ClearPageCacheInput;
use App\Domain\Cache\Queries\PageCacheScreen;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PageCacheController extends Controller
{
    public function show(PageCacheScreen $screen): Response
    {
        return Inertia::render('tools/page-cache', $screen->data());
    }

    public function clear(Request $request, ClearPageCache $clear): RedirectResponse
    {
        $clear->execute(new ClearPageCacheInput((string) $request->user()?->getAuthIdentifier()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Page cache cleared. Each page is drawn afresh on its next visit.')]);

        return to_route('tools.page-cache.show');
    }
}
