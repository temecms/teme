<?php

declare(strict_types=1);

use App\Domain\Sitemaps\SitemapAddress;
use App\Domain\Sitemaps\Sitemaps;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Sitemaps\SitemapEntry;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Seokit\NewsSitemap;
use Tests\Fixtures\Plugins\Seokit\Seokit;

require_once __DIR__.'/Seokit.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create()->assignRole('author');
    $this->locs = fn (string $name, ?string $from = null): array => array_map(fn (SitemapAddress $address): string => $address->loc, app(Sitemaps::class)->page($from === null ? $name : "{$name}.{$from}")?->addresses ?? []);
});

afterEach(function (): void {
    Seokit::reset();
    PluginFixtures::forget();
});

it('adds the plugin’s sitemap to the index, under a name of its own, its paths and items at their addresses', function (): void {
    seokitEnabled();
    $post = headPost($this->author, 'Harbour news');
    $draft = lifecycleDraft($this->author, 'Not yet');
    NewsSitemap::$entries = fn (?string $from): array => [
        SitemapEntry::path('/news/today/', new DateTimeImmutable('2026-11-01T09:00:00Z')),
        SitemapEntry::item($post->id),
        SitemapEntry::item($draft->id),
    ];

    expect(app(Sitemaps::class)->index())->toContain('http://localhost/sitemap-plugin-seokit-news.xml')
        ->and(($this->locs)('plugin-seokit-news'))->toBe(['http://localhost/news/today/', 'http://localhost/harbour-news/']);
});

it('gives each later page an address of its own, from the keys the plugin starts them at', function (): void {
    seokitEnabled();
    NewsSitemap::$starts = ['b2', 'NOT A KEY'];
    NewsSitemap::$entries = fn (?string $from): array => [SitemapEntry::path($from === null ? '/news/' : "/news/{$from}/")];

    expect(app(Sitemaps::class)->index())->toContain('http://localhost/sitemap-plugin-seokit-news.xml', 'http://localhost/sitemap-plugin-seokit-news.b2.xml')
        ->and(app(Sitemaps::class)->index())->not->toContain('http://localhost/sitemap-plugin-seokit-news.NOT A KEY.xml')
        ->and(($this->locs)('plugin-seokit-news', 'b2'))->toBe(['http://localhost/news/b2/']);
});

it('drops an answer that isn’t entries, and records the fault, when the plugin throws', function (): void {
    ['faults' => $faults] = seokitEnabled();
    NewsSitemap::$entries = fn (): array => ['/news/', SitemapEntry::path('/news/kept/')];

    expect(($this->locs)('plugin-seokit-news'))->toBe(['http://localhost/news/kept/']);

    NewsSitemap::$entries = fn (): array => throw new RuntimeException('Broken');

    expect(($this->locs)('plugin-seokit-news'))->toBe([])
        ->and(array_map(fn (array $fault): string => $fault[1], $faults->seen))->toBe(['tests/seokit#sitemaps']);
});

it('serves the sitemap at its address', function (): void {
    seokitEnabled();
    NewsSitemap::$entries = fn (): array => [SitemapEntry::path('/news/')];

    $this->get('/sitemap-plugin-seokit-news.xml')->assertOk()->assertSee('<loc>http://localhost/news/</loc>', false);
});
