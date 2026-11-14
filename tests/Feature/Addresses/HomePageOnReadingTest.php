<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Jobs\RecomputeAddresses;
use App\Domain\Settings\Queries\FindSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('chooses the home page on Reading, keeping every address setting, and moves addresses in the background', function (): void {
    $welcome = addrPublished($this->admin, 'Welcome', 'page');
    $news = addrPublished($this->admin, 'News', 'page');
    addrChange($this->admin, ['bases' => ['tag' => 'topic']]);
    Queue::fake();

    $this->actingAs($this->admin)->from('/admin/settings/reading')
        ->put('/admin/settings/reading/home-page', ['front_shows' => AddressSettings::FRONT_PAGE, 'front_page_id' => $welcome->id, 'posts_page_id' => $news->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/settings/reading');

    $reader = app(SettingsReader::class);

    expect($reader->string('addresses', 'front_shows'))->toBe(AddressSettings::FRONT_PAGE)
        ->and($reader->string('addresses', 'front_page_id'))->toBe($welcome->id)
        ->and($reader->string('addresses', 'posts_page_id'))->toBe($news->id)
        ->and($reader->string('addresses', 'base_tag'))->toBe('topic')
        ->and($reader->string('addresses', 'author_base'))->toBe('author');

    Queue::assertPushed(RecomputeAddresses::class);
});

it('saves the addresses on Addresses and keeps the home page', function (): void {
    $welcome = addrPublished($this->admin, 'Welcome', 'page');
    addrChange($this->admin, ['frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $welcome->id]);

    $this->actingAs($this->admin)->from('/admin/settings/addresses')
        ->put('/admin/settings/addresses', ['author_base' => 'writer'])
        ->assertSessionHasNoErrors();

    $reader = app(SettingsReader::class);

    expect($reader->string('addresses', 'author_base'))->toBe('writer')
        ->and($reader->string('addresses', 'front_shows'))->toBe(AddressSettings::FRONT_PAGE)
        ->and($reader->string('addresses', 'front_page_id'))->toBe($welcome->id);
});

it('refuses a home page that isn\'t a published page, and writes nothing', function (): void {
    $this->actingAs($this->admin)->from('/admin/settings/reading')
        ->put('/admin/settings/reading/home-page', ['front_shows' => AddressSettings::FRONT_PAGE, 'front_page_id' => '', 'posts_page_id' => ''])
        ->assertSessionHasErrors(['frontPageId' => 'Choose a published page for the home page.']);

    expect(app(SettingsReader::class)->string('addresses', 'front_shows'))->toBe(AddressSettings::FRONT_LATEST);
});

it('writes nothing for someone who may not change the addresses, and shows them the home page read-only', function (): void {
    $person = User::factory()->create();
    $person->givePermissionTo('settings.general.manage');
    $welcome = addrPublished($this->admin, 'Welcome', 'page');

    $this->actingAs($person)
        ->put('/admin/settings/reading/home-page', ['front_shows' => AddressSettings::FRONT_PAGE, 'front_page_id' => $welcome->id, 'posts_page_id' => ''])
        ->assertForbidden();

    expect(app(SettingsReader::class)->string('addresses', 'front_shows'))->toBe(AddressSettings::FRONT_LATEST);

    $this->actingAs($person)->get('/admin/settings/reading')
        ->assertInertia(fn (Assert $page) => $page->where('homePage.canChange', false));
});

it('draws the home page on Reading, with the chosen page\'s title', function (): void {
    $welcome = addrPublished($this->admin, 'Welcome', 'page');
    addrChange($this->admin, ['frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $welcome->id]);

    $this->actingAs($this->admin)->get('/admin/settings/reading')
        ->assertInertia(fn (Assert $page) => $page
            ->where('homePage.frontShows', AddressSettings::FRONT_PAGE)
            ->where('homePage.frontPageId', $welcome->id)
            ->where('homePage.postsPageId', '')
            ->where('homePage.chosenPages', [$welcome->id => 'Welcome'])
            ->where('homePage.latestWords', 'posts')
            ->where('homePage.pageTypes', ['page'])
            ->where('homePage.canChange', true));

    // Only Reading draws it.
    $this->actingAs($this->admin)->get('/admin/settings/general')->assertInertia(fn (Assert $page) => $page->where('homePage', null));
});

it('finds the home page settings on Reading', function (): void {
    expect(app(FindSettings::class)->run('home page shows', $this->admin)['settings'])
        ->toContain(['key' => 'addresses.front_shows', 'label' => 'Home page shows', 'screen' => 'Reading', 'href' => '/admin/settings/reading#field-front_shows']);
});
