<?php

/**
 * Settings › Discussion (1.8d's D2, D3): the switch and the Discussion
 * settings, each saved by its own Operation; it changes nothing itself.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Settings;

use App\Domain\Comments\Operations\ChangeDiscussionSettings;
use App\Domain\Comments\Operations\Input\ChangeDiscussionSettingsInput;
use App\Domain\Comments\Operations\Input\SwitchCommentsInput;
use App\Domain\Comments\Operations\SwitchComments;
use App\Domain\Comments\Queries\DiscussionScreen;
use App\Domain\Settings\Exceptions\StaleSettingsException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class DiscussionSettingsController extends Controller
{
    public function show(Request $request, DiscussionScreen $screen): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        return Inertia::render('settings/discussion', $screen->data($user));
    }

    public function update(Request $request, ChangeDiscussionSettings $change): RedirectResponse
    {
        /** @var array<string, mixed> $values */
        $values = (array) $request->input('values', []);

        try {
            $change->execute(new ChangeDiscussionSettingsInput(
                $values,
                $request->integer('expected_version'),
                (string) $request->user()?->getAuthIdentifier(),
            ));
        } catch (StaleSettingsException) {
            throw self::stale();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return back(fallback: route('settings.discussion'));
    }

    public function switchComments(Request $request, SwitchComments $switch): RedirectResponse
    {
        try {
            /** @var array{on: bool, changed: bool} $result */
            $result = $switch->execute(new SwitchCommentsInput(
                (string) $request->input('values.comments'),
                $request->integer('expected_version'),
                (string) $request->user()?->getAuthIdentifier(),
            ));
        } catch (StaleSettingsException) {
            throw self::stale();
        }

        $message = match (true) {
            ! $result['changed'] => __('Settings saved.'),
            $result['on'] => __('Comments switched on. Readers can comment wherever an item is open.'),
            default => __('Comments switched off. Stored comments are kept for when you switch them back on.'),
        };

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back(fallback: route('settings.discussion'));
    }

    /**
     * A form opened on an older version, in the generic screen's words.
     */
    private static function stale(): ValidationException
    {
        return ValidationException::withMessages([
            'expected_version' => __('These settings were changed by someone else while you had them open. Reload the page and try again.'),
        ]);
    }
}
