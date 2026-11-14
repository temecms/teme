<?php

declare(strict_types=1);

use App\Domain\Comments\Discussion;
use App\Domain\Comments\DiscussionSettings;
use App\Domain\Contents\Models\Content;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

it('brings types in from a file, and their lists, editor and public addresses work at once', function (): void {
    $admin = ctAdmin();

    $this->artisan('teme:types:import', ['file' => base_path('tests/Fixtures/Types/teme-types.json')])
        ->expectsOutput('Added film (Films).')
        ->expectsOutput('Added recipe (Recipes).')
        ->assertSuccessful();

    $this->actingAs($admin)
        ->get('/admin/recipes')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('content/index')
            ->where('labels.plural', 'Recipes')
            ->where('contentTypes.2.segment', 'films')
            ->where('contentTypes.3.segment', 'recipes'));

    $this->actingAs($admin)->post('/admin/films', ['title' => 'Arrival'])->assertRedirect();
    $film = Content::query()->where('type', 'film')->sole();
    $this->actingAs($admin)->get(route('content.edit', $film->id))->assertOk();

    listsPublish($film, $admin);

    addrGet('/films/arrival/')->assertOk()->assertSee('Arrival');

    expect(app(SettingsReader::class)->string('addresses', 'pattern_recipe'))->toBe('/recipes/{slug}/')
        ->and(DiscussionSettings::current()->forNewItem('recipe'))->toBe(Discussion::Open);
});
