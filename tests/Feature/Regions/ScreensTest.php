<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
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
});

it('lists the active theme\'s regions with their state', function (): void {
    $this->actingAs($this->editor)->get('/admin/regions')->assertInertia(fn (Assert $page) => $page
        ->component('regions/index')
        ->where('regions.0.key', 'after-content')
        ->where('regions.0.state', 'empty')
        ->where('regions.0.cards', ['callout', 'button']));
});

it('creates a region and opens it in the editor in region mode', function (): void {
    $response = $this->actingAs($this->editor)->post('/admin/regions', ['theme' => 'good', 'region' => 'after-content']);

    $region = Content::query()->where('type', 'region')->firstOrFail();
    $response->assertRedirect("/admin/content/{$region->id}/edit");

    $this->actingAs($this->editor)->get("/admin/content/{$region->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('kind', 'region')
        ->where('allowedCards', ['callout', 'button']));
});

it('refuses the screen to people without the permission', function (): void {
    $author = User::factory()->create()->assignRole('author');

    $this->actingAs($author)->get('/admin/regions')->assertForbidden();
});

it('sends each region\'s cards by their labels, and the most blocks it holds', function (): void {
    $this->actingAs($this->editor)->get('/admin/regions')->assertInertia(fn (Assert $page) => $page
        ->component('regions/index')
        ->where('regions.0.cards', ['callout', 'button'])
        ->where('regions.0.cardLabels', ['Callout', 'Button'])
        ->where('regions.0.limit', 'At most 5 blocks'));
});

it('tells the editor\'s region mode what the region takes', function (): void {
    $region = regionCreate($this->editor);

    $this->actingAs($this->editor)->get("/admin/content/{$region->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->component('content/edit')
        ->where('themeRegion.label', 'After the article')
        ->where('themeRegion.themeName', 'Fixture Good')
        ->where('themeRegion.takes', 'Takes text and these cards: Callout and Button. At most 5 blocks.')
        ->where('themeRegion.max', 5));
});

it('lists the regions kept from other themes under an open Unplaced heading, with each region\'s limit', function (): void {
    expect(uiComponent('pages/regions/index.tsx'))
        ->toContain('data-test="unplaced"')
        ->toContain('Kept as they were from themes you aren’t using. Each shows again if you switch back to its theme.')
        ->toContain('Regions kept from themes not in use')
        ->toContain('row.cardLabels')
        ->toContain('row.limit')
        ->not->toContain('Collapsible')
        ->not->toContain('Regions of other themes');
});

it('heads a region\'s editor in its column with its name, its theme and what it takes, and keeps the header bar to the way back', function (): void {
    expect(uiComponent('pages/content/edit.tsx'))
        ->toContain("title={__('Region: :label, used by :theme', {")
        ->toContain('theme: themeRegion.themeName,')
        ->toContain('label: themeRegion.label,')
        ->toContain('description={themeRegion.takes}')
        ->and(uiComponent('components/editor/editor-header.tsx'))
        ->not->toContain('title?: string');
});
