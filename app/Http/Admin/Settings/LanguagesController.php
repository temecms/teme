<?php

declare(strict_types=1);

namespace App\Http\Admin\Settings;

use App\Domain\Languages\Operations\AddSiteLanguage;
use App\Domain\Languages\Operations\ChangeSiteLanguage;
use App\Domain\Languages\Operations\ChangeTranslatedTypes;
use App\Domain\Languages\Operations\Input\AddSiteLanguageInput;
use App\Domain\Languages\Operations\Input\ChangeSiteLanguageInput;
use App\Domain\Languages\Operations\Input\ChangeTranslatedTypesInput;
use App\Domain\Languages\Operations\Input\RemoveSiteLanguageInput;
use App\Domain\Languages\Operations\Input\ReorderSiteLanguagesInput;
use App\Domain\Languages\Operations\RemoveSiteLanguage;
use App\Domain\Languages\Operations\ReorderSiteLanguages;
use App\Domain\Languages\Queries\LanguagesScreen;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Settings\Exceptions\StaleSettingsException;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Languages (I-3360; docs/proposals/multilingual-screens.md): the
 * site's languages in one table, and which types are translated. Adding,
 * changing, reordering and removing a language go through their
 * Operations, each answered with a toast.
 */
final class LanguagesController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('settings/languages', LanguagesScreen::data());
    }

    public function store(Request $request, AddSiteLanguage $add, SiteLanguages $languages): RedirectResponse
    {
        $tag = $request->string('tag')->toString();

        return $this->answered(fn () => $add->execute(new AddSiteLanguageInput(
            $tag,
            $request->string('prefix')->toString(),
            $request->boolean('shown'),
            $request->integer('expected_version'),
            (string) $request->user()?->getAuthIdentifier(),
        )), __('Added :language.', ['language' => $languages->label($tag)]));
    }

    public function update(Request $request, string $tag, ChangeSiteLanguage $change, SiteLanguages $languages): RedirectResponse
    {
        $shown = $request->boolean('shown');
        $wasShown = $languages->isShown($tag);
        $label = $languages->label($tag);

        return $this->answered(fn () => $change->execute(new ChangeSiteLanguageInput(
            $tag,
            $request->string('prefix')->toString(),
            $shown,
            $request->integer('expected_version'),
            (string) $request->user()?->getAuthIdentifier(),
        )), match (true) {
            $shown && ! $wasShown => __(':language is now shown to readers.', ['language' => $label]),
            ! $shown && $wasShown => __(':language is now hidden from readers.', ['language' => $label]),
            default => __('Saved :language.', ['language' => $label]),
        });
    }

    public function destroy(Request $request, string $tag, RemoveSiteLanguage $remove, SiteLanguages $languages): RedirectResponse
    {
        $label = $languages->label($tag);

        return $this->answered(fn () => $remove->execute(new RemoveSiteLanguageInput(
            $tag,
            $request->integer('expected_version'),
            (string) $request->user()?->getAuthIdentifier(),
        )), __('Removed :language.', ['language' => $label]));
    }

    public function reorder(Request $request, ReorderSiteLanguages $reorder): RedirectResponse
    {
        return $this->answered(fn () => $reorder->execute(new ReorderSiteLanguagesInput(
            array_values(array_filter((array) $request->input('tags', []), is_string(...))),
            $request->integer('expected_version'),
            (string) $request->user()?->getAuthIdentifier(),
        )), __('Saved the order of the languages.'));
    }

    public function translated(Request $request, ChangeTranslatedTypes $change): RedirectResponse
    {
        return $this->answered(fn () => $change->execute(new ChangeTranslatedTypesInput(
            array_values(array_filter((array) $request->input('untranslated_types', []), is_string(...))),
            array_values(array_filter((array) $request->input('untranslated_taxonomies', []), is_string(...))),
            $request->integer('expected_version'),
            (string) $request->user()?->getAuthIdentifier(),
        )), __('Saved what is translated.'));
    }

    /**
     * Runs the Operation, then flashes its toast; a form opened on an older
     * version of the languages is refused with words, as the settings' are.
     */
    private function answered(Closure $run, string $toast): RedirectResponse
    {
        try {
            $run();
        } catch (StaleSettingsException) {
            throw ValidationException::withMessages([
                'expected_version' => __('Someone changed the languages while you were editing. Reload and try again.'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $toast]);

        return back(fallback: route('settings.languages', [], false));
    }
}
