<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\ViewModels\Crumb;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    sdTrailTheme();
    ltTypes();
    $this->admin = ctAdmin();
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    app(PathProjection::class)->syncUser($this->author);
    $this->news = typesTerm($this->admin, 'category', 'News');
});

/** @return list<array{string, string}> */
function typeTrailOf(object $item): array
{
    return array_map(fn (Crumb $crumb): array => [$crumb->name, $crumb->url], app(ArticleViews::class)->published($item)->breadcrumbs ?? []);
}

it('goes through the archive page for an item of a type that has one: home, the archive, the item', function (): void {
    $film = ltItem($this->author, 'film', 'Arrival', [$this->news]);

    expect(typeTrailOf($film))->toBe([['Home', '/'], ['Films', '/films/'], ['Arrival', '/films/arrival/']]);
});

it('writes the same steps into the BreadcrumbList', function (): void {
    ltItem($this->author, 'film', 'Arrival', [$this->news]);

    $list = collect(sdGraph('/films/arrival/'))->first(fn (mixed $node): bool => is_array($node) && ($node['@type'] ?? null) === 'BreadcrumbList');

    expect(array_column($list['itemListElement'], 'name'))->toBe(['Home', 'Films', 'Arrival']);
});

it('keeps the category trail for the posts, whose archive is the latest list', function (): void {
    $post = ltItem($this->author, 'post', 'Harbour story', [$this->news]);

    expect(array_column(typeTrailOf($post), 0))->toBe(['Home', 'News', 'Harbour story']);
});

it('keeps the category trail for a type with no archive page of its own', function (): void {
    $tag = typesTerm($this->admin, 'tag', 'Fish');
    $recipe = ltItem($this->author, 'recipe', 'Fish stew', [$tag]);

    expect(array_column(typeTrailOf($recipe), 0))->toBe(['Home', 'Fish stew']);
});
