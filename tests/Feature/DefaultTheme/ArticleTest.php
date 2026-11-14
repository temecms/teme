<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
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

it('sets out the article in study Q\'s order, leaving out what is missing', function (): void {
    $article = $this->site['longRead'];   // has a category, a written excerpt, a featured image, tags, and an author with a bio
    $html = addrGet('/'.$article->slug.'/')->getContent();

    $positions = array_map(fn (string $marker): int => (int) strpos($html, $marker), [
        'class="kicker"', '<h1', 'class="standfirst"', 'class="meta byline"', 'class="featured-media', 'class="prose"',
        'class="tags"', 'class="author-box"', 'data-region="after-content"', 'id="comments"', 'class="related"',
    ]);

    expect($positions)->each->toBeGreaterThan(0)
        ->and($positions)->toBe(array_values(array_unique($positions)))
        ->and($positions)->toEqual(collect($positions)->sort()->values()->all());
});

it('shows reading time and Updated only when they apply', function (): void {
    $html = addrGet('/'.$this->site['longRead']->slug.'/')->getContent();

    expect($html)->toContain('min read')->not->toContain('Updated');
});

it('hides the featured image, author box and related posts when the owner turns them off', function (): void {
    designPublish(['theme.default' => ['featured_image_style' => 'hidden', 'show_author_box' => false, 'show_related' => false]]);

    $html = addrGet('/'.$this->site['longRead']->slug.'/')->getContent();

    expect($html)->not->toContain('class="featured-media')->not->toContain('class="author-box"')->not->toContain('class="related"');
});

it('keeps pages free of byline, tags and related posts', function (): void {
    $html = addrGet('/about/')->getContent();

    expect($html)->not->toContain('class="meta byline"')->not->toContain('class="tags"')->not->toContain('class="related"');
});

it('draws a home page that is a page with its own template and the site title as the h1, and leads the posts page with the pins', function (): void {
    $this->app['env'] = 'local';
    $admin = User::factory()->create()->assignRole('administrator');
    $news = addrPublished($admin, 'News', 'page');
    addrChange($admin, ['frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $this->site['about']->id, 'postsPageId' => $news->id]);

    $response = addrGet('/');
    $home = dtDom($response->getContent());
    $posts = dtDom(addrGet('/news/')->getContent());

    $response->assertOk()->assertHeader('X-Teme-Template', 'theme::templates.single-page');
    expect($home->query('//h1')->length)->toBe(1)
        ->and($home->query('//header//h1')->length)->toBe(1)
        ->and($home->query('//main//h2')->item(0)->textContent)->toContain($this->site['about']->title)
        ->and($posts->query('//h1')->length)->toBe(1)
        ->and($posts->query('//h1')->item(0)->textContent)->toContain('News')
        ->and($posts->query('//*[contains(@class,"lead")]//h2/a')->item(0)->textContent)->toBe($this->site['pinnedNewest']->title)
        ->and($posts->query('//*[contains(@class,"featured")]//article')->length)->toBe(1);
});

it('carries its reduced-motion and forced-colours rules, and a visible focus style', function (): void {
    $css = file_get_contents(base_path('themes/default/assets/css/theme.css'));

    expect($css)->toContain('prefers-reduced-motion: reduce')->toContain('forced-colors: active')->toContain(':focus-visible');
});
