<?php

declare(strict_types=1);

use App\Domain\Site\ListingViews;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    // The fixture theme's own number is 10 (tests/Fixtures/themes/good/teme-theme.json).
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('gives listings the theme\'s own number until the site sets one, then the site\'s', function (): void {
    expect(app(ListingViews::class)->perPage())->toBe(10);

    $this->actingAs($this->admin)->from('/admin/settings/reading')
        ->put('/admin/settings/reading', ['values' => ['posts_per_page' => 4], 'expected_version' => 0])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/settings/reading');

    expect(app(ListingViews::class)->perPage())->toBe(4)
        ->and(app(ListingViews::class)->pagination(1, 9, '/category/news/')->total)->toBe(3);
});

it('gives the theme\'s number back when the site\'s is set to 0', function (): void {
    $this->actingAs($this->admin)->put('/admin/settings/reading', ['values' => ['posts_per_page' => 4], 'expected_version' => 0])->assertSessionHasNoErrors();

    // The site's number took over first, so the theme's comes back, not stays.
    expect(app(ListingViews::class)->perPage())->toBe(4);

    $this->actingAs($this->admin)->put('/admin/settings/reading', ['values' => ['posts_per_page' => 0], 'expected_version' => 1])->assertSessionHasNoErrors();

    expect(app(ListingViews::class)->perPage())->toBe(10);
});

it('takes a number from 0 to 100, as a theme\'s own runs to 100', function (int $refused): void {
    $this->actingAs($this->admin)->from('/admin/settings/reading')
        ->put('/admin/settings/reading', ['values' => ['posts_per_page' => $refused], 'expected_version' => 0])
        ->assertSessionHasErrors('values.posts_per_page');

    expect(app(ListingViews::class)->perPage())->toBe(10);
})->with([-1, 101]);

it('draws Reading with Posts per page and the theme\'s own number under it', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings/reading')
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/group')
            ->where('heading', ['key' => 'reading', 'label' => 'Reading', 'description' => 'What the home page shows, and how many items each listing page holds.'])
            ->where('fields.0.key', 'posts_per_page')
            ->where('values.posts_per_page', 0)
            ->where('themePerPage', ['theme' => 'Fixture Good', 'number' => 10]));

    // Only Reading draws it.
    $this->actingAs($this->admin)->get('/admin/settings/general')->assertInertia(fn (Assert $page) => $page->where('themePerPage', null));
});

it('keeps Reading to whoever may change General, and writes nothing for anyone else', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($editor)->get('/admin/settings/reading')->assertForbidden();
    $this->actingAs($editor)->put('/admin/settings/reading', ['values' => ['posts_per_page' => 4], 'expected_version' => 0])->assertForbidden();

    expect(app(ListingViews::class)->perPage())->toBe(10);
});
