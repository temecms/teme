<?php

/**
 * Content › Regions (I-395): the active theme's regions on one screen, in
 * their groups, with the chosen one's cards to add, edit, move, save and
 * publish in place, and the rows kept from other themes. Filling a region
 * the first time hands it to CreateRegion; discarding its changes hands it
 * to DiscardRegionChanges. The controller changes nothing itself.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Cards\CardManifest;
use App\Domain\Cards\CardRegistry;
use App\Domain\Contents\Exceptions\StaleRevisionException;
use App\Domain\Regions\Operations\CreateRegion;
use App\Domain\Regions\Operations\DiscardRegionChanges;
use App\Domain\Regions\Operations\Input\CreateRegionInput;
use App\Domain\Regions\Operations\Input\DiscardRegionChangesInput;
use App\Domain\Regions\Queries\RegionScreens;
use App\Domain\SiteCode\RegionCode;
use App\Domain\SiteCode\SiteCode;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class RegionsController extends Controller
{
    public function index(Request $request, RegionScreens $screens): Response
    {
        $userId = (string) $request->user()?->getAuthIdentifier();

        return Inertia::render('regions/index', [
            ...$screens->workspace($request->string('region')->toString() ?: null, $userId),
            'manifest' => CardManifest::forBrowser(app(CardRegistry::class)),
            // Whether this person may read and change a Custom HTML card's code, and the host's switch, as the editor sends them.
            'canManageSiteCode' => RegionCode::manages($request->user()),
            'siteCodeLocked' => SiteCode::disallowed(),
            'siteUrl' => url('/'),
            'editor' => [
                'autosaveIdleMs' => (int) config('editor.autosave_idle_ms', 2000),
                'autosaveMaxMs' => (int) config('editor.autosave_max_ms', 10000),
                'checkpointAfterMinutes' => (int) config('editor.checkpoint_after_minutes', 5),
            ],
        ]);
    }

    /**
     * Fills a region the first time, then shows it chosen on the screen.
     */
    public function store(Request $request, CreateRegion $createRegion): RedirectResponse
    {
        $region = $request->string('region')->toString();

        $createRegion->execute(new CreateRegionInput(
            $request->string('theme')->toString(),
            $region,
            (string) $request->user()?->getAuthIdentifier(),
        ));

        return to_route('regions.index', ['region' => $region]);
    }

    /**
     * Throws away the region's unpublished changes, then shows it again,
     * saying so; a stale working revision is said as an error, and nothing
     * changes.
     */
    public function discard(Request $request, string $content, DiscardRegionChanges $discard, RegionScreens $screens): RedirectResponse
    {
        $key = $screens->keyOf($content);
        abort_if($key === null, 404);

        try {
            $discard->execute(new DiscardRegionChangesInput($content, $request->string('working_revision_id')->toString(), (string) $request->user()?->getAuthIdentifier()));
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Changes discarded.')]);
        } catch (StaleRevisionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Someone else saved this region meanwhile, so nothing was discarded. Look again before you try.')]);
        }

        return to_route('regions.index', ['region' => $key]);
    }
}
