<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

it('opens Settings on its hub, a card for each page under the approved headings', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');

    $cards = $this->actingAs($admin)
        ->get('/admin/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/index'))
        ->inertiaProps()['cards'];

    $general = collect($cards)->firstWhere('key', 'general');

    expect($general)->toMatchArray([
        'label' => 'General',
        'href' => '/admin/settings/general',
        'line' => 'The site’s name, tagline, time zone and language.',
        'heading' => 'The site',
        'plugin' => null,
    ])
        ->and(collect($cards)->firstWhere('key', 'people')['heading'])->toBe('People and services')
        ->and(collect($cards)->firstWhere('key', 'writing')['heading'])->toBe('Writing and discussion');
});

it('points the menu\'s Settings at the hub', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');

    $nav = $this->actingAs($admin)->get('/admin/settings')->inertiaProps()['nav'];

    expect(collect($nav)->firstWhere('key', 'settings')['href'])->toBe('/admin/settings');
});

it('refuses the hub to someone who may open no Settings page', function (): void {
    $author = User::factory()->create()->assignRole('author');

    $this->actingAs($author)->get('/admin/settings')->assertForbidden();
});
