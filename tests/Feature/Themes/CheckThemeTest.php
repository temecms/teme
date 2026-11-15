<?php

declare(strict_types=1);

use App\Domain\Themes\ActiveTheme;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [
        base_path('tests/Fixtures/themes/good'),
        base_path('tests/Fixtures/themes/broken'),
        base_path('tests/Fixtures/themes/h1-none-single'),
        base_path('tests/Fixtures/themes/h1-two-home'),
    ]]);
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('passes a good theme and reports its asset sizes', function (): void {
    $this->artisan('teme:theme:check', ['code' => 'good'])
        ->expectsOutputToContain('CSS')
        ->assertSuccessful();
});

it('fails a broken theme and lists every error', function (): void {
    $this->artisan('teme:theme:check', ['code' => 'broken'])
        ->expectsOutputToContain('single-page-landing')
        ->assertFailed();
});

it('passes the default theme with one H1 on its home page and on an item', function (): void {
    config(['teme.theme_paths' => []]);
    addrPublished($this->editor, 'Hello World');

    $this->artisan('teme:theme:check', ['code' => 'default', '--json' => true])
        ->expectsOutputToContain('"errors": []')
        ->assertSuccessful();
});

it('fails a theme whose single pages have no H1', function (): void {
    addrPublished($this->editor, 'Hello World');

    $this->artisan('teme:theme:check', ['code' => 'h1-none-single'])
        ->expectsOutputToContain('The single item page /hello-world/ has no H1.')
        ->assertFailed();
});

it('fails a theme whose home page has two H1s', function (): void {
    addrPublished($this->editor, 'Hello World');

    $this->artisan('teme:theme:check', ['code' => 'h1-two-home'])
        ->expectsOutputToContain('The home page has more than one H1 (2).')
        ->assertFailed();
});

it('reports the H1 errors in the JSON report beside the other errors', function (): void {
    addrPublished($this->editor, 'Hello World');

    $this->artisan('teme:theme:check', ['code' => 'h1-two-home', '--json' => true])
        ->expectsOutputToContain('The home page has more than one H1 (2).')
        ->assertFailed();
});

it('draws the checked theme and not the active one, and gives the site its own back', function (): void {
    addrPublished($this->editor, 'Hello World');
    themeActivate('good');

    $this->artisan('teme:theme:check', ['code' => 'h1-none-single'])->assertFailed();

    expect(app(ActiveTheme::class)->get()?->code)->toBe('good');
    addrGet('/hello-world/')->assertOk()->assertSee('data-template="single"', false);
});

it('warns that no single page was checked when nothing is published', function (): void {
    $this->artisan('teme:theme:check', ['code' => 'good'])
        ->expectsOutputToContain('no item is published')
        ->assertSuccessful();
});
