<?php

/**
 * Content › Regions (I-395): the active theme's regions with their state,
 * and the rows kept from other themes. Filling a region the first time
 * hands it to CreateRegion, then opens it in the editor; the controller
 * changes nothing itself.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Regions\Operations\CreateRegion;
use App\Domain\Regions\Operations\Input\CreateRegionInput;
use App\Domain\Regions\Queries\RegionScreens;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class RegionsController extends Controller
{
    public function index(RegionScreens $screens): Response
    {
        return Inertia::render('regions/index', $screens->index());
    }

    public function store(Request $request, CreateRegion $createRegion): RedirectResponse
    {
        $region = $createRegion->execute(new CreateRegionInput(
            $request->string('theme')->toString(),
            $request->string('region')->toString(),
            (string) $request->user()?->getAuthIdentifier(),
        ));

        return to_route('content.edit', ['content' => $region->id]);
    }
}
