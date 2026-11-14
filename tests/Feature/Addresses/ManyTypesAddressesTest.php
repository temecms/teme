<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\ListingTypes;
use App\Domain\Design\Queries\DesignScreen;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    $this->author = User::factory()->create()->assignRole('author');
    ltTypes();
});

// A guard: it pins what ArchiveType answered, now asked of ListingTypes::first().
it('gives terms, authors and dates the first archive type’s trailing slash whatever the home page lists, and each item its own type’s', function (): void {
    addrChange($this->admin, ['patterns' => ['film' => '/films/{slug}']]);
    cmSettings([ListingTypes::SETTING => ['film']], 'reading');
    $film = ltItem($this->author, 'film', 'Arrival');

    expect(Addresses::trailingSlash())->toBeTrue()
        ->and(Addresses::toUrl('category/news'))->toBe('/category/news/')
        ->and(Addresses::relativeUrl($film))->toBe('/films/arrival');
});

it('takes the home page only from a type with no archive: a recipe may be one, a film may not', function (): void {
    $film = ltItem($this->admin, 'film', 'Arrival');
    $recipe = ltItem($this->admin, 'recipe', 'Fish stew');

    expect(fn () => addrChange($this->admin, ['frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $film->id]))
        ->toThrow(ValidationException::class, 'Choose a published page for the home page.');

    expect(app(SettingsReader::class)->string('addresses', 'front_shows'))->toBe(AddressSettings::FRONT_LATEST);

    addrChange($this->admin, ['frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $recipe->id]);

    expect(AddressSettings::current()->frontPageId)->toBe($recipe->id);
});

it('names the ticked types in Reading’s Home page, and offers only types with no archive as its pages', function (): void {
    $reading = fn () => $this->actingAs($this->admin)->get('/admin/settings/reading');

    $reading()->assertInertia(fn (Assert $page) => $page
        ->where('homePage.latestWords', 'posts')
        ->where('homePage.pageTypes', ['page', 'recipe']));

    cmSettings([ListingTypes::SETTING => ['post', 'film']], 'reading');

    $reading()->assertInertia(fn (Assert $page) => $page->where('homePage.latestWords', 'posts and films'));

    cmSettings([ListingTypes::SETTING => []], 'reading');

    $reading()->assertInertia(fn (Assert $page) => $page->where('homePage.latestWords', 'items'));
});

it('offers Settings › Addresses’ presets to the first archive type’s address alone, and lists Films with the other addresses', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings/addresses')
        ->assertInertia(fn (Assert $page) => $page
            ->where('types.0.key', 'post')
            ->where('types.0.main', true)
            ->where('types.1.main', false)
            ->where('types.2.key', 'film')
            ->where('types.2.main', false)
            ->where('types.3.key', 'recipe')
            ->where('types.3.main', false));
});

it('previews Design’s Content section on the newest item the home page lists', function (): void {
    $story = ltItem($this->author, 'post', 'Harbour story');
    $this->travel(1)->minutes();
    $film = ltItem($this->author, 'film', 'Arrival');

    expect(app(DesignScreen::class)->previewPath('content'))->toBe(Addresses::relativeUrl($story));

    cmSettings([ListingTypes::SETTING => ['post', 'film']], 'reading');

    expect(app(DesignScreen::class)->previewPath('content'))->toBe(Addresses::relativeUrl($film));
});

it('has no archive type left: every listing asks which types it holds', function (): void {
    expect(class_exists('App\Domain\Addresses\ArchiveType'))->toBeFalse();
});
