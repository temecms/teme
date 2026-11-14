<?php

declare(strict_types=1);

namespace App\Http\Admin\Settings;

use App\Domain\Addresses\Operations\ChangeAddresses;
use App\Domain\Addresses\Operations\Input\ChangeAddressesInput;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Reading's Home page: what the home page shows, saved by ChangeAddresses,
 * which keeps every other address setting as it is and moves addresses
 * when the home page changes. It changes nothing itself.
 */
final class HomePageController extends Controller
{
    public function __invoke(Request $request, ChangeAddresses $change): RedirectResponse
    {
        $result = $change->execute(new ChangeAddressesInput(
            patterns: [],
            bases: [],
            authorBase: null,
            frontShows: $request->string('front_shows')->toString(),
            frontPageId: $request->string('front_page_id')->toString(),
            postsPageId: $request->string('posts_page_id')->toString(),
            actorId: (string) $request->user()?->getAuthIdentifier(),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => $result['recompute']
            ? __('Home page saved. Moving every address in the background.')
            : __('Home page saved.')]);

        return back(fallback: route('settings.show', ['group' => 'reading']));
    }
}
