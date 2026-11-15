<?php

declare(strict_types=1);

use App\Domain\Menus\Operations\CreateNavigation;
use App\Domain\Menus\Operations\Input\CreateNavigationInput;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\SettingsReader;
use Tests\Fixtures\Languages\Languages;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    siteSetLanguage($this->admin, 'en-GB');
    Languages::set(['fr', 'es'], hidden: ['es']);
    $this->main = navCreate($this->editor, 'Main');
    $this->principal = app(CreateNavigation::class)->execute(new CreateNavigationInput('Principal', (string) $this->editor->id, 'fr'));
});

it('gives Show in the site\'s languages, the menu\'s own, and each language\'s menu in each place', function (): void {
    $this->actingAs($this->editor)->get("/admin/navigation/{$this->principal->id}")
        ->assertInertia(fn (Assert $page) => $page->component('navigation/edit')
            ->where('readers.languages.0.tag', 'en-GB')
            ->where('readers.languages.1.tag', 'fr')
            ->where('readers.language', 'fr')
            ->where('readers.bindings.fr.primary', null)
            ->where('readers.bindings.es.primary', null));

    $this->actingAs($this->editor)->get("/admin/navigation/{$this->main->id}")
        ->assertInertia(fn (Assert $page) => $page->where('readers.language', 'en-GB'));
});

it('shows a menu in a place to one language\'s readers only, leaving the default language\'s as it was', function (): void {
    $this->actingAs($this->editor)
        ->put("/admin/navigation/{$this->main->id}/slots", ['theme' => 'good', 'slot' => 'primary', 'navigation_id' => $this->main->id])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->editor)
        ->put("/admin/navigation/{$this->principal->id}/slots", ['theme' => 'good', 'slot' => 'primary', 'navigation_id' => $this->principal->id, 'language' => 'fr'])
        ->assertSessionHasNoErrors();

    expect(app(SettingsReader::class)->string('theme.good', 'nav_primary'))->toBe($this->main->id)
        ->and(SettingsGroupRecord::query()->find('theme.good')->translations)->toEqual(['fr' => ['nav_primary' => $this->principal->id]]);

    $this->actingAs($this->editor)->get("/admin/navigation/{$this->principal->id}")
        ->assertInertia(fn (Assert $page) => $page->where('readers.bindings.fr.primary', $this->principal->id));
});

it('gives Show in no languages on a site with one', function (): void {
    $plain = navCreate($this->editor, 'Footer');
    Languages::set([]);

    $this->actingAs($this->editor)->get("/admin/navigation/{$plain->id}")
        ->assertInertia(fn (Assert $page) => $page->where('readers', ['languages' => [], 'language' => null, 'bindings' => []]));
});
