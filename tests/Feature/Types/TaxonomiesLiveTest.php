<?php

declare(strict_types=1);

use App\Domain\Addresses\Operations\ChangeAddresses;
use App\Domain\Addresses\Operations\Input\ChangeAddressesInput;
use App\Domain\Contents\Models\Content;
use App\Domain\Taxonomy\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('brings Genres in from a file, and its term screen, menu, picker, public page, feed and base work at once', function (): void {
    $admin = ctAdmin();

    $this->artisan('teme:types:import', ['file' => base_path('tests/Fixtures/Types/teme-types-genres.json')])
        ->expectsOutput('Added film (Films).')
        ->expectsOutput('Added the taxonomy genre (Genres).')
        ->assertSuccessful();

    $this->actingAs($admin)->get('/admin/genres')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('terms/index')
        ->where('types.0.key', 'post')
        ->where('types.1.key', 'film'));

    $this->actingAs($admin)->post('/admin/genres', ['name' => 'Action'])->assertRedirect();
    $action = Term::query()->where('taxonomy', 'genre')->sole();

    $this->actingAs($admin)->post('/admin/films', ['title' => 'Arrival'])->assertRedirect();
    $film = Content::query()->where('type', 'film')->sole();

    $this->actingAs($admin)->get(route('content.edit', $film->id))->assertInertia(fn (Assert $page) => $page
        ->where('session.taxonomies.0.key', 'genre')
        ->where('session.taxonomies.0.terms.0.name', 'Action'));

    $post = typesDraft($admin, 'post', 'A review', [$action->id]);
    $arrival = typesDraft($admin, 'film', 'Arrival', [$action->id]);
    listsPublish($post, $admin);
    listsPublish($arrival, $admin);

    addrGet('/genre/action/')->assertOk()->assertSee('Arrival')->assertSee('A review');
    addrGet('/genre/action/feed/')->assertOk()->assertSee('Arrival');

    $this->actingAs($admin)->get('/admin/films?genre='.$action->id)->assertInertia(fn (Assert $page) => $page
        ->where('filters.genre', (string) $action->id));

    scElevated(fn () => app(ChangeAddresses::class)->execute(new ChangeAddressesInput(patterns: [], bases: ['genre' => 'kind'], authorBase: null, frontShows: null, frontPageId: null, postsPageId: null, actorId: (string) $admin->id)));
    addrGet('/kind/action/')->assertOk()->assertSee('Arrival');
    addrGet('/genre/action/')->assertHeader('Location', url('/').'/kind/action/');
});
