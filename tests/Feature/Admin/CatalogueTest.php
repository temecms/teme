<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
});

it('shows the catalogue to anyone signed in while the site is local or under test', function (): void {
    $this->actingAs($this->author)
        ->get('/admin/_catalogue')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dev/catalogue'));
});

it('answers 404 once the site runs in production, even where the route was registered', function (): void {
    // isProduction() and environment() read $app['env'], not the config.
    $this->app['env'] = 'production';

    $this->actingAs($this->author)->get('/admin/_catalogue')->assertNotFound();
});

it('sends a guest to sign in', function (): void {
    $this->get('/admin/_catalogue')->assertRedirect(route('login'));
});
