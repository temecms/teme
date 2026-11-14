<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    ctStoreRow(ctDefinition());
    app(ContentTypeRegistry::class)->refreshStored();
});

dataset('admin addresses', [
    'Media' => ['/admin/media', 'media.index'],
    'Themes' => ['/admin/themes', 'themes.index'],
    'the inbox' => ['/admin/inbox', 'inbox.index'],
    'your jobs' => ['/admin/my-jobs', 'my-jobs.index'],
    'Categories' => ['/admin/categories', 'screens.index'],
    'Posts' => ['/admin/posts', 'screens.index'],
    'a type no route names' => ['/admin/films', 'screens.index'],
    'a term’s change' => ['/admin/categories/01JTERM', 'terms.update', 'PUT'],
    'an item’s details' => ['/admin/films/01JITEM/details', 'content.details', 'PATCH'],
]);

it('opens a type made after the routes were registered, at its own segment', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/films')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('content/index')
            ->where('type', 'films')
            ->where('labels.plural', 'Films')
            ->where('contentTypes.2.segment', 'films'));
});

it('starts a draft of it from its list, and opens it in the editor', function (): void {
    $response = $this->actingAs($this->admin)->post('/admin/films', ['title' => 'Arrival']);
    $draft = Content::query()->where('type', 'film')->sole();

    $response->assertRedirect(route('content.edit', $draft->id));
    $this->actingAs($this->admin)->get(route('content.edit', $draft->id))->assertOk();
});

it('answers 404 for a segment no type has', function (): void {
    $this->actingAs($this->admin)->get('/admin/reels')->assertNotFound();
});

it('sends each admin address to its own route', function (string $path, string $name, string $method = 'GET'): void {
    expect(Route::getRoutes()->match(Request::create($path, $method))->getName())->toBe($name);
})->with('admin addresses');

it('sends each admin address to the same route once the routes are cached', function (string $path, string $name, string $method = 'GET'): void {
    $compiled = Route::getRoutes()->compile();
    $routes = (new CompiledRouteCollection($compiled['compiled'], $compiled['attributes']))
        ->setRouter(app('router'))
        ->setContainer(app());

    expect($routes->match(Request::create($path, $method))->getName())->toBe($name);
})->with('admin addresses');

it('reserves every first segment the admin’s own routes use, and the builders’ screens', function (): void {
    $fixed = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'admin/'))
        ->map(fn (string $uri): string => explode('/', $uri)[1])
        ->reject(fn (string $segment): bool => str_starts_with($segment, '{'))
        ->unique()
        ->values()
        ->all();

    expect(array_values(array_diff($fixed, ContentTypeRegistry::RESERVED)))->toBe([])
        ->and(ContentTypeRegistry::RESERVED)->toContain('types', 'taxonomies', 'register');
});

it('refuses to give a type one of the admin’s own segments', function (string $segment): void {
    expect(fn () => app(ContentTypeRegistry::class)->register(new ContentType('thing', 'Thing', 'Things', $segment)))
        ->toThrow(InvalidArgumentException::class, 'reserved');
})->with(['themes', 'ai', 'inbox', 'my-jobs', 'types', 'taxonomies', 'register']);
