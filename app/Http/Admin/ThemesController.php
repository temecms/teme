<?php

/**
 * Site › Themes: the installed themes, a theme's preview and activation, the
 * regions each places, and the chosen theme's settings with its menu
 * places, every change drafted and published through the design's
 * Operations. It changes nothing itself.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Design\Operations\ActivateTheme;
use App\Domain\Design\Operations\CreateDesignPreview;
use App\Domain\Design\Operations\DiscardDesignDraft;
use App\Domain\Design\Operations\Input\ActivateThemeInput;
use App\Domain\Design\Operations\Input\CreateDesignPreviewInput;
use App\Domain\Design\Operations\Input\DiscardDesignDraftInput;
use App\Domain\Design\Operations\Input\PublishDesignInput;
use App\Domain\Design\Operations\Input\SaveDesignDraftInput;
use App\Domain\Design\Operations\Input\StopPreviewingThemeInput;
use App\Domain\Design\Operations\PublishDesign;
use App\Domain\Design\Operations\SaveDesignDraft;
use App\Domain\Design\Operations\StopPreviewingTheme;
use App\Domain\Design\Queries\DesignScreen;
use App\Domain\Extensions\ExtensionsAccess;
use App\Domain\Extensions\Queries\ExtensionsScreen;
use App\Domain\Regions\Queries\RegionScreens;
use App\Domain\Regions\RegionWords;
use App\Domain\Themes\ActiveTheme;
use App\Domain\Themes\Theme;
use App\Domain\Themes\ThemeRegistry;
use App\Domain\Themes\ThemeSwitchNotes;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Teme\Contracts\Settings\Setting;

final class ThemesController extends Controller
{
    public function index(Request $request, DesignScreen $screen, RegionScreens $regions, ThemeRegistry $themes, ExtensionsScreen $extensions, ActiveTheme $active, ThemeSwitchNotes $notes): Response
    {
        $data = $screen->data();
        $previewed = $data['preview'] === null ? null : $themes->find($data['preview']['code']);
        $current = $active->get();

        return Inertia::render('themes/index', [
            ...$data,
            'regions' => $regions->index(),
            'previewRegions' => $previewed === null ? null : $regions->forTheme($previewed),
            'canEditRegions' => $request->user()?->can('regions.manage') === true,
            // What a switch away from the active theme hides: its own cards, which draw only while it is active.
            'hiddenCards' => $current === null ? [] : $notes->hiddenByLeaving($current),
            // Installing from the admin (I-211), for whoever may.
            'extensions' => $request->user()?->can(ExtensionsAccess::INSTALL) === true ? $extensions->data() : null,
        ]);
    }

    /**
     * The old address of a theme's own settings: they are drawn on Themes,
     * under the theme, so the theme the design names (the one previewed,
     * else the one in use) leads there, and any other installed theme's
     * address to the list.
     *
     * @since 0.8.0-alpha.1
     */
    public function settings(string $code, DesignScreen $screen, ThemeRegistry $themes): RedirectResponse
    {
        $theme = $themes->find($code) ?? abort(404);

        if ($theme->code !== $screen->data()['theme'] || ! $theme->usable()) {
            return to_route('themes.index');
        }

        return redirect()->to(route('themes.index').'#theme-settings');
    }

    /**
     * Previews a theme: SaveDesignDraft drafts the switch, refusing a theme
     * the brand colour fails, and the screen then shows the theme's settings
     * and places, and opens the site with it.
     */
    public function startPreview(Request $request, string $code, SaveDesignDraft $save): RedirectResponse
    {
        $save->execute(new SaveDesignDraftInput(['design' => ['theme' => $code]], (string) $request->user()?->getAuthIdentifier()));

        return to_route('themes.index');
    }

    public function stopPreview(Request $request, string $code, StopPreviewingTheme $stop): RedirectResponse
    {
        $name = $stop->execute(new StopPreviewingThemeInput($code, (string) $request->user()?->getAuthIdentifier()));

        if (is_string($name)) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Stopped previewing :name.', ['name' => $name])]);
        }

        return to_route('themes.index');
    }

    /**
     * Saves the keys changed since the last save. A refusal answers 422,
     * each message keyed `groups.{group}.{key}`.
     */
    public function saveDraft(Request $request, SaveDesignDraft $save, DesignScreen $screen): JsonResponse
    {
        $save->execute(new SaveDesignDraftInput(
            groups: (array) $request->input('groups', []),
            actorId: (string) $request->user()?->getAuthIdentifier(),
        ));

        return response()->json(['draft' => $screen->data()['draft']]);
    }

    /**
     * Activates a theme through ActivateTheme, then says it is live and how
     * many of its regions wait to be filled.
     */
    public function activate(Request $request, string $code, ActivateTheme $activate, RegionScreens $regions): RedirectResponse
    {
        $leaving = app(ActiveTheme::class)->get();
        $hidden = $leaving === null || $leaving->code === $code ? [] : app(ThemeSwitchNotes::class)->hiddenByLeaving($leaving);

        /** @var Theme $theme */
        $theme = $activate->execute(new ActivateThemeInput($code, (string) $request->user()?->getAuthIdentifier()));
        $empty = array_filter($regions->forTheme($theme)['regions'], fn (array $region): bool => $region['state'] === 'empty');
        $kept = array_sum(array_column($hidden, 'regions'));
        $message = RegionWords::live($theme->manifest?->name() ?? $theme->code, count($empty));

        Inertia::flash('toast', ['type' => 'success', 'message' => $kept === 0 ? $message : $message.' '.RegionWords::keptCards($kept)]);

        return to_route('themes.index');
    }

    /**
     * Publishes the design's draft. A draft that switches the theme goes
     * live only through ActivateTheme, which checks the theme can be used and
     * the brand colour against it.
     */
    public function publish(Request $request, DesignScreen $screen, PublishDesign $publish, ActivateTheme $activate, RegionScreens $regions): RedirectResponse
    {
        $drafted = $screen->draftedTheme();

        if ($drafted !== null) {
            return $this->activate($request, $drafted, $activate, $regions);
        }

        $publish->execute(new PublishDesignInput((string) $request->user()?->getAuthIdentifier()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Design published.')]);

        return back(fallback: route('themes.index'));
    }

    public function discard(Request $request, DiscardDesignDraft $discard): RedirectResponse
    {
        $discard->execute(new DiscardDesignDraftInput((string) $request->user()?->getAuthIdentifier()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Unpublished design changes discarded.')]);

        return back(fallback: route('themes.index'));
    }

    /**
     * A signed link that opens the page a section is about with the drafts shown.
     */
    public function previewLink(Request $request, DesignScreen $screen, CreateDesignPreview $create): JsonResponse
    {
        $section = $request->validate(['section' => ['required', 'string', Rule::in(Setting::FIELDSETS)]])['section'];

        return response()->json(['url' => $create->execute(new CreateDesignPreviewInput(
            path: $screen->previewPath($section),
            actorId: (string) $request->user()?->getAuthIdentifier(),
        ))]);
    }

    /**
     * The screenshot a theme declares; the request names only the theme.
     */
    public function screenshot(string $code, DesignScreen $screen): BinaryFileResponse
    {
        $screenshot = $screen->screenshot($code) ?? abort(404);

        return response()->file($screenshot['path'], ['Content-Type' => $screenshot['type']]);
    }
}
