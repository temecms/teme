<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->menu = navCreate($this->editor, 'Main');
});

it('names the theme whose places a menu can be shown in', function (): void {
    $this->actingAs($this->editor)->get("/admin/navigation/{$this->menu->id}")->assertInertia(fn (Assert $page) => $page
        ->component('navigation/edit')
        ->where('themeName', 'Fixture Good'));
});

it('tells whoever creates a menu what to do next', function (): void {
    $this->actingAs($this->editor)
        ->post('/admin/navigation', ['name' => 'Footer links'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Menu created. Add its items, then choose where it shows.');
});

it('names the menu by its new name when it is renamed', function (): void {
    $this->actingAs($this->editor)
        ->put("/admin/navigation/{$this->menu->id}", ['name' => 'Header links'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Renamed to “Header links”.');
});

it('names the menu when its items are saved', function (): void {
    $this->actingAs($this->editor)
        ->put("/admin/navigation/{$this->menu->id}/items", ['tree' => [['kind' => 'home']]])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Saved “Main”.');
});

it('names the menu when it is deleted', function (): void {
    $this->actingAs($this->editor)
        ->delete("/admin/navigation/{$this->menu->id}")
        ->assertRedirect('/admin/navigation')
        ->assertInertiaFlash('toast.message', 'Deleted “Main”.');
});

it('says which menu a place now shows, or that it shows none', function (string $choice): void {
    $this->actingAs($this->editor)
        ->put("/admin/navigation/{$this->menu->id}/slots", [
            'theme' => 'good',
            'slot' => 'primary',
            'navigation_id' => $choice === 'a menu' ? $this->menu->id : '',
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', $choice === 'a menu' ? 'Saved. Header shows “Main”.' : 'Saved. Header shows no menu.');
})->with(['a menu', 'none']);

it('offers New menu, keeps Edit under each name and Delete in its "…" menu, and confirms a delete with Delete menu', function (): void {
    expect(uiComponent('pages/navigation/index.tsx'))
        ->toContain("__('New menu')")
        ->toContain("__('Create menu')")
        ->toContain('<RowLine')
        ->toContain('<RowMenu')
        ->toContain("label: __('Delete menu…')")
        ->toContain("confirmLabel={__('Delete menu')}")
        ->not->toMatch('/>\s*Create\s*<\//');
});

it('puts a menu\'s editor under the Navigation trail, with All menus, Rename…, Save menu and Delete this menu', function (): void {
    expect(uiComponent('pages/navigation/edit.tsx'))
        ->toContain("title: __('Navigation')")
        ->toContain("__('All menus')")
        ->toContain("__('Rename…')")
        ->toContain('Save menu')
        ->toContain('Rename menu')
        ->toContain("__('Delete this menu')")
        ->toContain("confirmLabel={__('Delete menu')}")
        ->toContain('themeName')
        ->not->toContain('← Navigation');
});

it('offers a Heading after Home page in Add an item, and says what a heading’s row needs, in their own words', function (): void {
    $panel = uiComponent('components/navigation/add-item-panel.tsx');

    expect(strpos($panel, "{ key: 'heading', label: 'Heading' }"))->toBeGreaterThan(strpos($panel, "{ key: 'home', label: 'Home page' }"))
        ->and($panel)->toContain('A label that leads nowhere and opens the items under it, such as “Services” over your service pages.')
        ->toContain('Add heading')
        ->toContain('Give the heading a label.')
        ->toContain('”. Move the items it opens under it.')
        ->toContain('aria-label="What to add"')
        ->not->toContain('What the item points at')
        ->and(uiComponent('components/navigation/tree-editor.tsx'))
        ->toContain('Needs an item under it')
        ->toContain('A heading can’t go to the third level, where nothing can go under it.');
});
