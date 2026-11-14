<?php

declare(strict_types=1);

namespace App\Http\Admin\Dashboard;

use App\Domain\Dashboard\Queries\Dashboard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Home: the person's own work first, then the site's figures, read through
 * Dashboard. It names no model.
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request, Dashboard $dashboard): Response
    {
        $viewer = $request->user();
        abort_if($viewer === null, 403);

        return Inertia::render('dashboard', $dashboard->forUser($viewer));
    }
}
