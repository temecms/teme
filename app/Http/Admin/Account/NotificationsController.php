<?php

/**
 * Account › Notifications (I-691): every kind of notice a person may turn
 * off, from the registry and worded for them, core's own and then each
 * plugin's under its name, beside a word about their inbox; saved through UpdatePreferences, the offered choices' keys and
 * nothing else.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Account;

use App\Domain\Notices\Queries\NoticeChoices;
use App\Domain\Preferences\Operations\Input\UpdatePreferencesInput;
use App\Domain\Preferences\Operations\UpdatePreferences;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationsController extends Controller
{
    public function edit(Request $request, NoticeChoices $choices): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $grouped = $choices->grouped($user);

        return Inertia::render('account/notifications', [
            'notices' => $grouped['core'],
            // Each enabled plugin's kinds, under its own name (docs/plans/plugin-sockets-3.md).
            'pluginNotices' => $grouped['plugins'],
            'inboxHref' => route('inbox.index', [], false),
        ]);
    }

    public function update(Request $request, UpdatePreferences $update, NoticeChoices $choices): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        /** @var array{preferences: array<string, mixed>} $valid */
        $valid = $request->validate(['preferences' => ['required', 'array']]);

        // Only the choices this person is offered are this panel's (I-691); anything else sent is left alone.
        $chosen = array_intersect_key($valid['preferences'], array_flip($choices->keysFor($user)));
        abort_if($chosen === [], 422);

        // The empty item that stands for "no channel" arrives as null, since
        // the request turns empty strings into null; a list holds channels only.
        $chosen = array_map(fn (mixed $channels): mixed => is_array($channels) ? array_values(array_filter($channels, fn (mixed $channel): bool => $channel !== null && $channel !== '')) : $channels, $chosen);

        $update->execute(new UpdatePreferencesInput($chosen, (string) $user->getAuthIdentifier()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notifications saved.')]);

        return back();
    }
}
