<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('editor');
    $this->site = dtSite($this->author);
});

it('leads the front page with the newest pinned post, with priority, then the pinned row, then the feed', function (): void {
    $this->app['env'] = 'local';
    $response = addrGet('/');
    $dom = dtDom($response->getContent());

    expect($dom->query('//*[contains(@class,"lead")]//h2/a')->item(0)->textContent)->toBe($this->site['pinnedNewest']->title)
        ->and(substr_count($response->getContent(), 'fetchpriority="high"'))->toBe(1)
        ->and($dom->query('//*[contains(@class,"featured")]//article')->length)->toBe(1)
        ->and($dom->query('//*[contains(@class,"feed-list")]//article')->length)->toBeGreaterThan(0);
    $response->assertHeaderMissing('X-Teme-Warnings');
});

it('switches the feed to a grid and the header to none from settings, losing no post', function (): void {
    designPublish(['theme.default' => ['feed_layout' => 'grid', 'latest_header' => 'none']]);

    $dom = dtDom(addrGet('/')->getContent());

    expect($dom->query('//*[contains(@class,"feed-grid")]')->length)->toBe(1)
        ->and($dom->query('//*[contains(@class,"lead")]')->length)->toBe(0)
        ->and($dom->query('//*[contains(@class,"feed-grid")]//article//h2/a')->item(0)->textContent)->toBe($this->site['pinnedNewest']->title);
});

it('titles an archive from its subject, with the description', function (): void {
    $html = addrGet('/category/'.$this->site['news']->slug.'/')->getContent();

    expect(dtDom($html)->query('//h1')->item(0)->textContent)->toContain('News')
        ->and($html)->toContain($this->site['news']->description);
});

it('answers an unknown address with the theme\'s own 404 page and a search form', function (): void {
    $response = addrGet('/nowhere/');

    $response->assertNotFound();
    // The header draws two search forms on every page; the page's own is in <main>.
    expect(dtDom($response->getContent())->query('//main//form[@role="search"]')->length)->toBe(1);
});
