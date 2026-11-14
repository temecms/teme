<?php

declare(strict_types=1);

use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Domain\Menus\Operations\Input\PlaceImportedNavigationInput;
use App\Domain\Menus\Operations\PlaceImportedNavigation;
use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Settings\Models\SettingsChange;
use App\Domain\Themes\ThemeRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->menu = navCreate($this->admin, 'Imported');
});

/**
 * PlaceImportedNavigation in the good theme's $slot, as $actor, or as the
 * operator at the shell.
 *
 * @return array{outcome: string, shown: string}
 */
function pinPlace(string $slot, string $navigationId, ?User $actor = null, bool $elevated = true): array
{
    $place = fn (): array => app(PlaceImportedNavigation::class)->execute(
        new PlaceImportedNavigationInput('good', $slot, $navigationId, $actor === null ? null : (string) $actor->id),
    );

    return $actor === null && $elevated ? scElevated($place) : $place();
}

/**
 * Which menu each of the good theme's places shows.
 *
 * @return array<string, string|null>
 */
function pinShown(): array
{
    return app(ThemeSlotSettings::class)->bound(app(ThemeRegistry::class)->find('good'));
}

it('shows an imported menu in an empty place, as the import’s change', function (): void {
    expect(pinPlace('primary', $this->menu->id))->toBe(['outcome' => PlaceImportedNavigation::PLACED, 'shown' => $this->menu->id])
        ->and(pinShown())->toBe(['primary' => $this->menu->id, 'footer' => null])
        ->and(SettingsChange::query()->where('group_key', 'theme.good')->sole()->only(['origin', 'actor_id']))->toBe(['origin' => 'import', 'actor_id' => null]);
});

it('leaves a place that shows another menu as it is, and changes nothing where it shows this one', function (): void {
    $theirs = navCreate($this->admin, 'Theirs');
    app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('good', 'primary', $theirs->id, (string) $this->admin->id));

    expect(pinPlace('primary', $this->menu->id))->toBe(['outcome' => PlaceImportedNavigation::TAKEN, 'shown' => $theirs->id])
        ->and(pinPlace('footer', $this->menu->id)['outcome'])->toBe(PlaceImportedNavigation::PLACED)
        ->and(pinPlace('footer', $this->menu->id)['outcome'])->toBe(PlaceImportedNavigation::UNCHANGED)
        ->and(pinShown())->toBe(['primary' => $theirs->id, 'footer' => $this->menu->id]);
});

it('refuses a place the theme doesn’t have, and a menu that isn’t there', function (): void {
    expect(fn () => pinPlace('sidebar', $this->menu->id))->toThrow(ValidationException::class, 'The active theme has no menu place called “sidebar”. Reload the page and choose again.')
        ->and(fn () => pinPlace('primary', '01jb0000000000000000000000'))->toThrow(ValidationException::class, 'There is no such menu.')
        ->and(pinShown())->toBe(['primary' => null, 'footer' => null]);
});

it('writes nothing for anyone who may not import', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => pinPlace('primary', $this->menu->id, $editor))->toThrow(AuthorizationException::class, 'Not permitted to import a site.')
        ->and(fn () => pinPlace('primary', $this->menu->id, null, elevated: false))->toThrow(AuthorizationException::class, 'Not permitted to import a site.')
        ->and(pinShown()['primary'])->toBeNull()
        ->and(SettingsChange::query()->where('group_key', 'theme.good')->count())->toBe(0);
});
