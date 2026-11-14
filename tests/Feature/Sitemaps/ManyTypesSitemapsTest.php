<?php

declare(strict_types=1);

use App\Domain\Addresses\ListingTypes;
use App\Domain\Addresses\PathProjection;
use App\Domain\Sitemaps\AuthorsSitemap;
use App\Domain\Sitemaps\HomeSitemap;
use App\Domain\Sitemaps\Queries\ListedItems;
use App\Domain\Sitemaps\SitemapAddress;
use App\Domain\Sitemaps\SitemapPage;
use App\Domain\Sitemaps\Sitemaps;
use App\Domain\Sitemaps\TermsSitemap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    ltTypes();
    $this->locs = fn (?SitemapPage $page): ?array => $page === null ? null : array_map(fn (SitemapAddress $address): string => $address->loc, $page->addresses);
});

it('lists a person whose only live work is a film, and not one whose only work is a recipe', function (): void {
    $critic = User::factory()->create(['name' => 'Ann Critic'])->assignRole('author');
    $cook = User::factory()->create(['name' => 'Cy Cook'])->assignRole('author');
    app(PathProjection::class)->syncUser($critic);
    app(PathProjection::class)->syncUser($cook);
    ltItem($critic, 'film', 'Arrival');
    ltItem($cook, 'recipe', 'Fish stew');

    expect(($this->locs)(app(Sitemaps::class)->page('authors')))->toBe(['http://localhost/author/ann-critic/']);
});

it('lists a category holding only a film, and a tag holding only a recipe', function (): void {
    $news = typesTerm($this->admin, 'category', 'News');
    $quick = typesTerm($this->admin, 'tag', 'Quick');
    ltItem($this->admin, 'film', 'Arrival', [$news]);
    ltItem($this->admin, 'recipe', 'Fish stew', [$quick]);

    expect(app(Sitemaps::class)->index())->toContain('http://localhost/sitemap-terms-category.xml', 'http://localhost/sitemap-terms-tag.xml')
        ->and(($this->locs)(app(Sitemaps::class)->page('terms-tag')))->toBe(['http://localhost/tag/quick/']);
});

it('dates the site’s address by the newest item of the types Reading ticks', function (): void {
    ltItem($this->admin, 'post', 'Harbour story');
    $this->travel(1)->minutes();
    ltItem($this->admin, 'film', 'Arrival');
    $home = fn (): ?SitemapAddress => app(Sitemaps::class)->page('home')?->addresses[0] ?? null;
    $items = app(ListedItems::class);

    expect($home()?->lastmod?->equalTo($items->newest('post')))->toBeTrue();

    cmSettings([ListingTypes::SETTING => ['post', 'film']], 'reading');

    expect($home()?->lastmod?->equalTo($items->newest('film')))->toBeTrue()
        ->and($items->newest('post', 'film')?->equalTo($items->newest('film')))->toBeTrue();
});

it('records the lists each child shows for the page cache: authors the types with an archive, a taxonomy the types that use it, the home the ticked types', function (): void {
    expect(app(AuthorsSitemap::class)->tags(AuthorsSitemap::NAME))->toBe(['list:post', 'list:film'])
        ->and(app(TermsSitemap::class)->tags(TermsSitemap::PREFIX.'tag'))->toBe(['list:post', 'list:recipe'])
        ->and(app(HomeSitemap::class)->tags(HomeSitemap::NAME))->toBe(['list:post']);
});
