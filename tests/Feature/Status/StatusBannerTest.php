<?php

declare(strict_types=1);

use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Backups\HealthyBackups;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/broken')]]);
});

it('tells administrators when the theme cannot be used, and why', function (): void {
    themeActivate('broken');
    $admin = User::factory()->create()->assignRole('administrator');

    $this->actingAs($admin)->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('statusBanner.level', 'error')
        ->where('statusBanner.message', fn (string $m): bool => str_contains($m, '"broken"'))
        ->where('statusBanner.actionLabel', 'Choose a theme')
        ->where('statusBanner.actionUrl', route('themes.index')));
});

it('shows nothing when all is well, and nothing to people who cannot fix it', function (): void {
    HealthyBackups::seed();

    $admin = User::factory()->create()->assignRole('administrator');
    // From 1.6g the default theme draws two menus: all is well once each place has one.
    $nav = navCreate($admin);

    foreach (['primary', 'footer'] as $slot) {
        app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('default', $slot, $nav->id, (string) $admin->id));
    }

    $this->actingAs($admin)->get('/admin')->assertInertia(fn (Assert $page) => $page->where('statusBanner', null));

    themeActivate('broken');
    $author = User::factory()->create()->assignRole('author');
    $this->actingAs($author)->get('/admin')->assertInertia(fn (Assert $page) => $page->where('statusBanner', null));
});
