<?php

declare(strict_types=1);

use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\DeleteNavigation;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Domain\Menus\Operations\Input\DeleteNavigationInput;
use App\Domain\Menus\ThemeSlotSettings;
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
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('binds a menu to a slot the theme declares', function (): void {
    $nav = navCreate($this->editor);

    app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('good', 'primary', $nav->id, (string) $this->editor->id));

    expect(app(ThemeSlotSettings::class)->bound(app(ThemeRegistry::class)->find('good')))->toBe(['primary' => $nav->id, 'footer' => null]);
});

it('refuses a slot the theme does not declare', function (): void {
    $nav = navCreate($this->editor);

    expect(fn () => app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('good', 'sidebar', $nav->id, (string) $this->editor->id)))
        ->toThrow(ValidationException::class);
});

it('clears the slot when its menu is deleted', function (): void {
    $nav = navCreate($this->editor);
    app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('good', 'primary', $nav->id, (string) $this->editor->id));

    app(DeleteNavigation::class)->execute(new DeleteNavigationInput($nav->id, (string) $this->editor->id));

    expect(app(ThemeSlotSettings::class)->bound(app(ThemeRegistry::class)->find('good'))['primary'])->toBeNull();
});

it('writes nothing when the person may do neither', function (): void {
    $nav = navCreate($this->editor);
    $author = User::factory()->create()->assignRole('author');

    expect(fn () => app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('good', 'primary', $nav->id, (string) $author->id)))
        ->toThrow(AuthorizationException::class);
    expect(app(ThemeSlotSettings::class)->bound(app(ThemeRegistry::class)->find('good'))['primary'])->toBeNull();
});
