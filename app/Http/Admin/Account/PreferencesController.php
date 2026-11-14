<?php

declare(strict_types=1);

namespace App\Http\Admin\Account;

use App\Domain\Notices\NoticePreferences;
use App\Domain\Preferences\BuiltInPreferences;
use App\Domain\Preferences\Operations\Input\UpdatePreferencesInput;
use App\Domain\Preferences\Operations\UpdatePreferences;
use App\Domain\Preferences\PreferenceDefinition;
use App\Domain\Preferences\PreferenceRegistry;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The person's own preferences screen, drawn from the registry with the
 * settings screen's components, and the one way to change them, through
 * UpdatePreferences. A background save — the rail's width, the light or dark
 * switch — asks for JSON and gets 204, so nothing re-renders. The comment
 * notice choices are Account › Notifications' (I-691).
 */
final class PreferencesController extends Controller
{
    public function edit(Request $request, PreferenceRegistry $registry): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        // A list preference has no control here; 1.5h's Screen options draws
        // them. The notice choices are drawn apart, on Account › Notifications.
        $fields = array_values(array_map(
            fn (PreferenceDefinition $definition): array => $definition->toField(),
            array_filter($registry->all(), fn (PreferenceDefinition $definition): bool => $definition->type !== 'list' && ! str_starts_with($definition->key, NoticePreferences::PREFIX)),
        ));

        return Inertia::render('account/preferences', [
            'fields' => $fields,
            'values' => PreferencesFor::user($user),
            'lookDescriptions' => BuiltInPreferences::LOOK_DESCRIPTIONS,
        ]);
    }

    public function update(Request $request, UpdatePreferences $update): HttpResponse
    {
        /** @var array{preferences: array<string, mixed>} $valid */
        $valid = $request->validate(['preferences' => ['required', 'array']]);

        $update->execute(new UpdatePreferencesInput($valid['preferences'], (string) $request->user()?->getAuthIdentifier()));

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Preferences saved.')]);

        return back();
    }
}
