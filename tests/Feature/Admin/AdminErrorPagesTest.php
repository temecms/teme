<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    Route::middleware(['web', 'admin', 'auth'])->group(function (): void {
        Route::get('/admin/_in-admin-refused', fn () => abort(403, request()->string('reason')->toString()));
    });

    cmSettings(['site_title' => 'The Garden City Ledger'], 'general');
});

it('draws a missing admin page inside the admin for someone signed in', function (): void {
    $author = User::factory()->create()->assignRole('author');

    $this->actingAs($author)
        ->get('/admin/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/in-admin')
            ->where('status', 404)
            ->where('role', 'Author'),
        );
});

it('draws a refusal inside the admin with the policy\'s own reason', function (): void {
    $author = User::factory()->create()->assignRole('author');

    $this->actingAs($author)
        ->get('/admin/_in-admin-refused?'.http_build_query(['reason' => 'You can’t edit this item.']))
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/in-admin')
            ->where('status', 403)
            ->where('reason', 'You can’t edit this item.')
            ->where('site', 'The Garden City Ledger'),
        );
});

it('leaves the general words to the page when the refusal gives only Laravel\'s own', function (): void {
    $author = User::factory()->create()->assignRole('author');

    $this->actingAs($author)
        ->get('/admin/_in-admin-refused')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->where('reason', null));
});

it('leaves a JSON request its own answer', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('author'))
        ->getJson('/admin/no-such-page')
        ->assertNotFound()
        ->assertHeaderMissing('X-Inertia');
});
