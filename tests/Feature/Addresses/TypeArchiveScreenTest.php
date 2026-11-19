<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\TypeArchives;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
});

it('shows a site of posts and pages no archive page on Settings › Addresses', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings/addresses')
        ->assertInertia(fn (Assert $page) => $page->component('settings/addresses')->where('archives', []));
});

it('lists Films’ archive page with its address, its feed, and nothing in its way (I-3304)', function (): void {
    ltTypes();

    $this->actingAs($this->admin)->get('/admin/settings/addresses')
        ->assertInertia(fn (Assert $page) => $page->where('archives', [
            ['key' => 'film', 'plural' => 'Films', 'base' => 'films', 'address' => '/films/', 'feed' => '/films/feed/', 'holder' => null],
        ]));
});

it('names the page that holds an archive page’s address', function (): void {
    archReelsAfterPage($this->admin);

    $this->actingAs($this->admin)->get('/admin/settings/addresses')
        ->assertInertia(fn (Assert $page) => $page->where('archives.0.key', 'reel')->where('archives.0.holder', 'the page "Reels"'));
});

it('saves an archive page’s address from the screen, and refuses one the site uses under its own field', function (): void {
    ltTypes();

    $this->actingAs($this->admin)->put('/admin/settings/addresses', ['author_base' => 'author', 'archives' => ['film' => 'movies']])
        ->assertSessionHasNoErrors();

    app()->forgetInstance(AddressSettings::class);

    expect(TypeArchives::path('film'))->toBe('movies');

    $this->actingAs($this->admin)->put('/admin/settings/addresses', ['author_base' => 'author', 'archives' => ['film' => 'admin']])
        ->assertSessionHasErrors(['archives.film' => 'Addresses starting /admin/ are used by the site itself.']);
});

it('names the archive page that had an address first when two types’ archive pages start at it', function (): void {
    archMediaTypes();

    $this->actingAs($this->admin)->get('/admin/settings/addresses')
        ->assertInertia(fn (Assert $page) => $page
            ->where('archives.0.key', 'photo')->where('archives.0.base', 'media')->where('archives.0.holder', null)
            ->where('archives.1.key', 'video')->where('archives.1.base', 'media')->where('archives.1.holder', 'the archive page "Photos"'));
});

it('gives the site’s own address for the previews, with no trailing slash', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings/addresses')
        ->assertInertia(fn (Assert $page) => $page->where('site', rtrim(url('/'), '/')));
});
