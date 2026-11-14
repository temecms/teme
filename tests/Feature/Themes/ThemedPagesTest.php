<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good'), base_path('tests/Fixtures/themes/broken')]]);
    $this->app['env'] = 'local';
    themeActivate('good');
    $this->editor = User::factory()->create()->assignRole('editor');
    // Only here, after boot: a route added in tests/Pest.php would run before any application exists.
    Route::middleware('public')->get('/_teme-test/teapot', fn () => abort(418));
});

it('renders an article through the theme and says which template won', function (): void {
    addrPublished($this->editor, 'Hello World');

    addrGet('/hello-world/')
        ->assertOk()
        ->assertSee('data-template="single"', false)
        ->assertHeader('X-Teme-Template', 'theme::templates.single');
});

it('answers an unknown address with the theme\'s not-found page and a 404', function (): void {
    addrGet('/nowhere/')->assertNotFound()->assertHeader('X-Teme-Template', 'theme::templates.index');
});

it('tells the template whether it is at the site\'s address', function (): void {
    // The good fixture has no templates/latest, so the site's address falls to the index floor.
    addrGet('/')->assertOk()->assertSee('data-front="yes"', false);
    addrGet('/nowhere/')->assertNotFound()->assertSee('data-front="no"', false);
});

it('lists the latest posts on core\'s fallback when the theme is broken', function (): void {
    themeActivate('broken');
    addrPublished($this->editor, 'Still listed');

    // Core's fallback has a latest template of its own, so the latest posts never reach its index.
    addrGet('/')->assertOk()
        ->assertSee('data-fallback="latest"', false)
        ->assertSee('Still listed');
});

it('uses core\'s error view when the theme\'s own error view fails', function (): void {
    // The good fixture's templates/error throws on purpose when $page->code is 418, so the
    // theme's own error view fails and core's fallback must render instead.
    addrGet('/_teme-test/teapot')->assertStatus(418)->assertSee('data-fallback="error"', false);
});

it('sends no template headers in production', function (): void {
    $this->app['env'] = 'production';
    addrPublished($this->editor, 'Hello World');

    addrGet('/hello-world/')->assertHeaderMissing('X-Teme-Template');
});
