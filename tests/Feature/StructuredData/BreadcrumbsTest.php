<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\ViewModels\Crumb;
use App\Domain\Themes\ManifestValidator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    app(PathProjection::class)->syncUser($this->author);
    $this->news = sdCategory('News');
    $this->local = sdCategory('Local', $this->news);
    $this->page = 'http://localhost/harbour-news/';
});

it('builds no trail and writes no BreadcrumbList on the default theme, which draws none (I-870)', function (): void {
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $post = headPost($this->author, 'Harbour news', [BuiltInFields::PRIMARY_CATEGORY => $this->local->id], [$this->local]);

    $graph = sdGraph('/harbour-news/');

    expect(app(ArticleViews::class)->published($post)->breadcrumbs)->toBeNull()
        ->and(sdNode($graph, $this->page.'#breadcrumb'))->toBeNull()
        ->and(sdNode($graph, $this->page))->not->toHaveKey('breadcrumb');
});

it('gives a theme that draws breadcrumbs the trail to an item: home, its primary category\'s parents and the category, then the item (I-295)', function (): void {
    sdTrailTheme();
    $post = headPost($this->author, 'Harbour news', [BuiltInFields::PRIMARY_CATEGORY => $this->local->id], [$this->local]);

    $trail = app(ArticleViews::class)->published($post)->breadcrumbs ?? [];

    expect(array_map(fn (Crumb $crumb): array => [$crumb->name, $crumb->url], $trail))
        ->toBe([['Home', '/'], ['News', '/category/news/'], ['Local', '/category/news/local/'], ['Harbour news', '/harbour-news/']])
        ->and(headValue(headDom('/harbour-news/'), '//nav[@aria-label="Breadcrumb"]'))->toBe('HomeNewsLocalHarbour news');
});

it('writes BreadcrumbList from the same trail, the last step without its address, and points the page at it (I-702)', function (): void {
    sdTrailTheme();
    headPost($this->author, 'Harbour news', [BuiltInFields::PRIMARY_CATEGORY => $this->local->id], [$this->local]);

    $graph = sdGraph('/harbour-news/');

    expect(sdNode($graph, $this->page.'#breadcrumb'))->toEqual(['@id' => $this->page.'#breadcrumb', '@type' => 'BreadcrumbList', 'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'http://localhost/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'News', 'item' => 'http://localhost/category/news/'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Local', 'item' => 'http://localhost/category/news/local/'],
        ['@type' => 'ListItem', 'position' => 4, 'name' => 'Harbour news'],
    ]])
        ->and(sdNode($graph, $this->page)['breadcrumb'])->toBe(['@id' => $this->page.'#breadcrumb']);
});

it('gives archives, authors, dates and the home page their trails, and writes no list for the home page\'s single step (I-295, I-702)', function (string $page, array $names): void {
    sdTrailTheme();
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00', 'UTC'));
    headPost($this->author, 'Harbour news', [], [$this->local]);
    $uri = match ($page) {
        'a category' => '/category/news/local/',
        'an author' => '/author/jane-doe/',
        'a month' => '/2026/10/',
        default => '/',
    };

    expect(headValue(headDom($uri), '//nav[@aria-label="Breadcrumb"]'))->toBe(implode('', $names))
        ->and(sdNode(sdGraph($uri), 'http://localhost'.$uri.'#breadcrumb') !== null)->toBe(count($names) > 1);
})->with([
    'a category' => ['a category', ['Home', 'News', 'Local']],
    'an author' => ['an author', ['Home', 'Jane Doe']],
    'a month' => ['a month', ['Home', 'October 2026']],
    'the home page' => ['the home page', ['Home']],
]);

it('refuses a theme manifest whose breadcrumbs isn\'t true or false (I-702)', function (): void {
    $folder = base_path('tests/Fixtures/themes/trail');
    $validator = app(ManifestValidator::class);

    expect($validator->errors(pageManifest(['code' => 'trail', 'breadcrumbs' => 'yes']), $folder))->toContain('breadcrumbs must be true or false.')
        ->and($validator->errors(pageManifest(['code' => 'trail', 'breadcrumbs' => true]), $folder))->not->toContain('breadcrumbs must be true or false.');
});
