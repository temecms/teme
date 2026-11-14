<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Addresses\TypeArchives;
use App\Domain\Sitemaps\Queries\ListedItems;
use App\Domain\Sitemaps\SitemapAddress;
use App\Domain\Sitemaps\Sitemaps;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    app(PathProjection::class)->syncUser($this->author);
    $this->locs = fn (): array => array_map(fn (SitemapAddress $address): string => $address->loc, app(Sitemaps::class)->page('archives')?->addresses ?? []);
});

it('lists each archive page in a child of its own after the site’s address, dated by its newest item (I-296)', function (): void {
    ltTypes();
    ltItem($this->author, 'film', 'Arrival');
    $this->travel(1)->minutes();
    ltItem($this->author, 'film', 'Dune');
    $page = app(Sitemaps::class)->page('archives');

    expect(array_slice(app(Sitemaps::class)->index(), 0, 2))->toBe(['http://localhost/sitemap-home.xml', 'http://localhost/sitemap-archives.xml'])
        ->and(($this->locs)())->toBe(['http://localhost/films/'])
        ->and($page?->addresses[0]->lastmod?->equalTo(app(ListedItems::class)->newest('film')))->toBeTrue();
});

it('leaves an archive page out while it lists nothing live, while its box hides it, and while a page holds its address', function (): void {
    ltTypes();

    expect(app(Sitemaps::class)->index())->not->toContain('http://localhost/sitemap-archives.xml');

    ltItem($this->author, 'film', 'Arrival');
    cmSettings(['hide_archive_film' => true], 'seo');

    expect(app(Sitemaps::class)->index())->not->toContain('http://localhost/sitemap-archives.xml');

    cmSettings(['hide_archive_film' => false], 'seo');
    archReelsAfterPage($this->admin);
    ltItem($this->author, 'reel', 'Reel one');

    expect(($this->locs)())->toBe(['http://localhost/films/']);
});

it('gives a site of posts and pages no archive page and no archives child', function (): void {
    ltItem($this->author, 'post', 'Harbour news');

    expect(TypeArchives::types())->toBe([])
        ->and(app(Sitemaps::class)->index())->not->toContain('http://localhost/sitemap-archives.xml')
        ->and(app(Sitemaps::class)->page('archives'))->toBeNull();
});

it('lists an address two types’ archive pages start at once', function (): void {
    archMediaTypes();
    ltItem($this->author, 'photo', 'Harbour at dawn');
    ltItem($this->author, 'video', 'Harbour at dusk');

    expect(($this->locs)())->toBe(['http://localhost/media/']);
});
