<?php

declare(strict_types=1);

use App\Domain\Navigation\Queries\ShellBrand;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('marks the site with the first letter or digit of its title, in capitals', function (string $title, string $mark): void {
    cmSettings(['site_title' => $title], 'general');

    expect(app(ShellBrand::class)->forViewer(true)['mark'])->toBe($mark);
})->with([
    'a plain title' => ['The Garden City Ledger', 'T'],
    'a quoted title' => ['“Quoted” News', 'Q'],
    'a number first' => ['9 to 5 Weekly', '9'],
    'an accented letter' => ['élan', 'É'],
    'a title with no letter or digit' => ['— · —', 'T'],
]);

it('shares the product, its version and its environment with a signed-in person', function (): void {
    cmSettings(['site_title' => 'The Garden City Ledger'], 'general');

    $this->actingAs($this->admin)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('brand.mark', 'T')
        ->where('brand.product', 'Teme')
        ->where('brand.version', config('teme.version'))
        ->where('brand.environment', app()->environment()));
});

it('tells a guest the product and the environment, never the version', function (): void {
    $this->get('/admin/login')->assertInertia(fn (Assert $page) => $page
        ->component('auth/login')
        ->where('brand.product', 'Teme')
        ->where('brand.version', null)
        ->where('brand.environment', app()->environment()));
});

it('names the environment the server runs in', function (): void {
    $this->app['env'] = 'staging';

    $this->actingAs($this->admin)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('brand.environment', 'staging'));
});

it('follows the site title when it changes', function (): void {
    cmSettings(['site_title' => 'Alpha'], 'general');
    $first = $this->actingAs($this->admin)->get(route('dashboard'))->viewData('page')['props']['brand']['mark'];

    cmSettings(['site_title' => 'Zed'], 'general');
    $second = $this->actingAs($this->admin)->get(route('dashboard'))->viewData('page')['props']['brand']['mark'];

    expect($first)->toBe('A')
        ->and($second)->toBe('Z');
});
