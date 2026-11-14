<?php

declare(strict_types=1);

namespace App\Http\Admin\Settings;

use App\Domain\Addresses\Jobs\RecomputeAddresses;
use App\Domain\Addresses\Operations\ChangeAddresses;
use App\Domain\Addresses\Operations\Input\ChangeAddressesInput;
use App\Domain\Addresses\Queries\AddressScreen;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Addresses: the Permalinks screen. Saving goes through
 * ChangeAddresses, and the screen polls the background recompute's progress.
 */
final class AddressesController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('settings/addresses', AddressScreen::data());
    }

    public function update(Request $request, ChangeAddresses $change): RedirectResponse
    {
        $result = $change->execute(new ChangeAddressesInput(
            patterns: array_map('strval', (array) $request->input('patterns', [])),
            bases: array_map(fn (mixed $base): string => (string) $base, (array) $request->input('bases', [])),
            authorBase: $request->string('author_base')->toString(),
            // The home page is chosen on Reading (HomePageController).
            frontShows: null,
            frontPageId: null,
            postsPageId: null,
            actorId: (string) $request->user()?->getAuthIdentifier(),
            archives: array_map(fn (mixed $address): string => (string) $address, (array) $request->input('archives', [])),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => $result['recompute']
            ? __('Addresses saved. Moving every address in the background.')
            : __('Addresses saved.')]);

        return back(fallback: route('settings.addresses.show'));
    }

    /**
     * The recompute's progress, from the cache: nothing in the database.
     */
    public function progress(): JsonResponse
    {
        /** @var array{done?: int, total?: int, running?: bool} $progress */
        $progress = (array) Cache::get(RecomputeAddresses::PROGRESS, []);

        return response()->json([
            'done' => (int) ($progress['done'] ?? 0),
            'total' => (int) ($progress['total'] ?? 0),
            'running' => (bool) ($progress['running'] ?? false),
        ]);
    }
}
