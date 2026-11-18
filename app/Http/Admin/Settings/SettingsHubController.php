<?php

declare(strict_types=1);

namespace App\Http\Admin\Settings;

use App\Domain\Navigation\SettingsHub;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Settings hub: a card for each Settings page the person may open,
 * under the hub's headings; 403 when there is none.
 */
final class SettingsHubController extends Controller
{
    public function __invoke(Request $request, SettingsHub $hub): Response
    {
        $user = $request->user();
        $cards = $user === null ? [] : $hub->cardsFor($user);

        abort_if($cards === [], 403);

        return Inertia::render('settings/index', ['cards' => $cards]);
    }
}
