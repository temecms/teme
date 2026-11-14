<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Menus;

use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Operations\Input\PlaceImportedNavigationInput;
use App\Domain\Menus\Operations\PlaceImportedNavigation;
use App\Domain\Themes\ActiveTheme;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Shows the imported menus in the active theme's places (I-343), once
 * every menu is here, as the import's choices map WordPress's menu
 * locations to them: only on the run that made each menu, never over a
 * place a person filled, and every menu left unplaced said. Reads
 * WordPress's locations live; a run carried on runs it again from the
 * start.
 */
final readonly class MenuPlacesStep implements StepPhase
{
    public function __construct(private ImportMap $map, private ActiveTheme $theme) {}

    public function key(): string
    {
        return 'menu-places';
    }

    public function order(): int
    {
        return 810;
    }

    public function label(): string
    {
        return 'Menu places';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        $assigned = [];

        foreach ((array) ($reader->whole('menus')['assigned_locations'] ?? []) as $location => $menu) {
            if (is_numeric($menu) && (int) $menu > 0) {
                $assigned[(string) $location] = (int) $menu;
            }
        }

        if ($assigned === []) {
            return StepResult::done();
        }

        $choices = $context->choice('menu_locations');

        if (! is_array($choices)) {
            return StepResult::done([new ImportMessage('menu-places.none-chosen', 'WordPress showed menus in places of its theme, and this import had no choice of places for them, so none is shown yet: choose their places under Content › Navigation.')]);
        }

        $theme = $this->theme->get();
        $places = $theme?->manifest?->navigations() ?? [];
        $filled = [];
        $messages = [];

        foreach ($assigned as $location => $menu) {
            $location = (string) $location;

            if (! array_key_exists($location, $choices)) {
                $messages[] = new ImportMessage('menu-places.not-chosen', "WordPress shows a menu at its {$location} location, which the import’s choices didn’t list, so it wasn’t placed: choose its place under Content › Navigation.", ['location' => $location]);

                continue;
            }

            $slot = $choices[$location];

            // Don't place it: the person chose so.
            if (! is_string($slot) || $slot === '') {
                continue;
            }

            if ($theme === null || ! array_key_exists($slot, $places)) {
                $messages[] = new ImportMessage('menu-places.no-place', "The theme has no place called {$slot} any more, so the menu at WordPress’s {$location} location wasn’t placed.", ['location' => $location, 'slot' => $slot]);

                continue;
            }

            $place = $places[$slot];

            if (isset($filled[$slot])) {
                $messages[] = new ImportMessage('menu-places.slot-twice', "WordPress’s {$location} location was chosen for the theme’s {$place} place too, which takes the menu from {$filled[$slot]} already, so its menu wasn’t placed there.", ['location' => $location, 'slot' => $slot, 'first' => $filled[$slot]]);

                continue;
            }

            $entry = $this->map->entry($context->sourceId, SourceRef::of('menu', $menu));
            $navigation = $entry?->teme_id === null ? null : Navigation::query()->find($entry->teme_id);

            if ($entry === null || $navigation === null) {
                $messages[] = new ImportMessage('menu-places.menu-missing', "The menu at WordPress’s {$location} location isn’t on Teme, so the theme’s {$place} place was left as it was.", ['location' => $location, 'slot' => $slot]);

                continue;
            }

            // Placed only by the run that made the menu: a place a person cleared or changed since stays as they left it.
            if ($entry->run_id !== $context->runId || $entry->outcome !== ImportOutcome::Created) {
                continue;
            }

            try {
                $placed = app(PlaceImportedNavigation::class)->execute(new PlaceImportedNavigationInput($theme->code, $slot, $navigation->id, $context->actorId()));
            } catch (ValidationException $refused) {
                $reason = (string) (Arr::first(Arr::flatten($refused->errors())) ?? '');
                $messages[] = new ImportMessage('menu-places.refused', "Teme couldn’t place the menu from WordPress’s {$location} location: {$reason}", ['location' => $location, 'slot' => $slot]);

                continue;
            }

            $filled[$slot] = $location;

            if ($placed['outcome'] === PlaceImportedNavigation::TAKEN) {
                $shown = (string) Navigation::query()->whereKey($placed['shown'])->value('name');
                $messages[] = new ImportMessage('menu-places.taken', "The theme’s {$place} place already shows “{$shown}”, so “{$navigation->name}”, from WordPress’s {$location} location, wasn’t placed there.", [
                    'location' => $location, 'slot' => $slot, 'menu' => $navigation->name, 'shown' => $shown,
                ]);
            }
        }

        return StepResult::done($messages);
    }
}
