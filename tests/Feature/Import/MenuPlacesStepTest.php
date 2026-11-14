<?php

declare(strict_types=1);

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\Menus\MenuPlacesStep;
use App\Domain\Import\Phases\Menus\WidgetsStep;
use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Themes\ThemeRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Import\MenuImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->companion = importFake();
    $this->source = importSource();
});

/**
 * Which menu each of the good theme's places shows.
 *
 * @return array<string, string|null>
 */
function mpShown(): array
{
    return app(ThemeSlotSettings::class)->bound(app(ThemeRegistry::class)->find('good'));
}

it('registers the places at 810 in every run, and the widgets at 890 in full runs only', function (): void {
    $phases = app(ImportPhases::class);

    expect($phases->find('menu-places'))->toBeInstanceOf(MenuPlacesStep::class)
        ->and($phases->find('menu-places')?->order())->toBe(810)
        ->and($phases->find('menu-places')?->runsIn(ImportRunKind::CatchUp))->toBeTrue()
        ->and($phases->find('widgets'))->toBeInstanceOf(WidgetsStep::class)
        ->and($phases->find('widgets')?->order())->toBe(890)
        ->and($phases->find('widgets')?->runsIn(ImportRunKind::Full))->toBeTrue()
        ->and($phases->find('widgets')?->runsIn(ImportRunKind::CatchUp))->toBeFalse();
});

it('shows each menu where the choices put it, run as a person', function (): void {
    $run = MenuImports::run($this->companion, $this->source, null, ['menu_locations' => ['primary' => 'primary', 'footer_navigation' => 'footer']], actor: $this->admin);

    expect(mpShown())->toBe(['primary' => MenuImports::navigationOf($this->source, '3')->id, 'footer' => MenuImports::navigationOf($this->source, '4')->id])
        ->and(MenuImports::runMessages($run, 'menu-places.'))->toBe([]);
});

it('leaves a place someone filled as it is on the run that made the menu, saying which menu shows there', function (): void {
    $ours = navCreate($this->admin, 'Ours');
    app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('good', 'primary', $ours->id, (string) $this->admin->id));

    $run = MenuImports::run($this->companion, $this->source, null, ['menu_locations' => ['primary' => 'primary', 'footer_navigation' => null]]);

    expect(mpShown())->toBe(['primary' => $ours->id, 'footer' => null])
        ->and(MenuImports::runMessages($run, 'menu-places.'))->toBe([
            ['menu-places.taken', 'The theme’s Header place already shows “Ours”, so “Main”, from WordPress’s primary location, wasn’t placed there.'],
        ]);
});

it('places a menu only on the run that made it, so a place cleared since stays empty', function (): void {
    $choices = ['menu_locations' => ['primary' => 'primary', 'footer_navigation' => 'footer']];
    MenuImports::run($this->companion, $this->source, null, $choices);
    app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('good', 'primary', null, (string) $this->admin->id));

    $again = MenuImports::run($this->companion, $this->source, null, $choices);

    expect(mpShown())->toBe(['primary' => null, 'footer' => MenuImports::navigationOf($this->source, '4')->id])
        ->and(MenuImports::runMessages($again, 'menu-places.'))->toBe([]);
});

it('says so when no place was chosen at all, as at the shell', function (): void {
    $run = MenuImports::run($this->companion, $this->source, null);

    expect(mpShown())->toBe(['primary' => null, 'footer' => null])
        ->and(MenuImports::runMessages($run, 'menu-places.'))->toBe([
            ['menu-places.none-chosen', 'WordPress showed menus in places of its theme, and this import had no choice of places for them, so none is shown yet: choose their places under Content › Navigation.'],
        ]);
});

it('places one menu in a place, and says what else was chosen for it or couldn’t be placed', function (): void {
    $answer = MenuImports::answer(
        [MenuImports::menu(3, [MenuImports::item(101)]), MenuImports::menu(4, [MenuImports::item(201)], 'Footer')],
        [],
        ['primary' => 3, 'footer_navigation' => 4, 'social' => 5, 'top' => 3, 'mobile' => 4],
    );

    $run = MenuImports::run($this->companion, $this->source, $answer, ['menu_locations' => [
        'primary' => 'primary', 'footer_navigation' => 'primary', 'social' => 'footer', 'top' => 'sidebar',
    ]]);

    expect(mpShown())->toBe(['primary' => MenuImports::navigationOf($this->source, '3')->id, 'footer' => null])
        ->and(MenuImports::runMessages($run, 'menu-places.'))->toBe([
            ['menu-places.menu-missing', 'The menu at WordPress’s social location isn’t on Teme, so the theme’s Footer place was left as it was.'],
            ['menu-places.no-place', 'The theme has no place called sidebar any more, so the menu at WordPress’s top location wasn’t placed.'],
            ['menu-places.not-chosen', 'WordPress shows a menu at its mobile location, which the import’s choices didn’t list, so it wasn’t placed: choose its place under Content › Navigation.'],
            ['menu-places.slot-twice', 'WordPress’s footer_navigation location was chosen for the theme’s Header place too, which takes the menu from primary already, so its menu wasn’t placed there.'],
        ]);
});
