<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Feeds\FeedLinks;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    app(PathProjection::class)->syncUser($this->author);
    $this->news = typesTerm($this->editor, 'category', 'News');
    pageArticle($this->author, 'Harbour news', categories: [$this->news]);
    $this->site = pageSiteName();

    // The feed links of a page, as [address, title], read from its head.
    $this->alternates = function (string $uri): array {
        $dom = dtDom((string) addrGet($uri)->assertOk()->getContent());
        $links = '//head/link[@rel="alternate"][@type="application/rss+xml"]';
        $found = [];

        for ($i = 1; $i <= (int) $dom->evaluate("count({$links})"); $i++) {
            $found[] = [(string) $dom->evaluate("string({$links}[{$i}]/@href)"), (string) $dom->evaluate("string({$links}[{$i}]/@title)")];
        }

        return $found;
    };
});

it('links the site\'s feed from every page: the front page, an article and a search (I-299)', function (string $uri): void {
    expect(($this->alternates)($uri))->toContain([rtrim(url('/'), '/').'/feed/', "{$this->site} » Feed"]);
})->with(['/', '/harbour-news/', '/?s=harbour']);

it('links a category\'s feed on its archive and an author\'s on theirs, beside the site\'s, titled with their names', function (): void {
    $origin = rtrim(url('/'), '/');
    $category = ($this->alternates)('/category/news/');
    $person = ($this->alternates)('/author/jane-doe/');

    expect($category)->toContain([$origin.'/feed/', "{$this->site} » Feed"])->toContain([$origin.'/category/news/feed/', "{$this->site} » News Category Feed"])
        ->and($person)->toContain([$origin.'/feed/', "{$this->site} » Feed"])->toContain([$origin.'/author/jane-doe/feed/', "{$this->site} » Posts by Jane Doe Feed"]);
});

it('adds a term\'s or an author\'s feed only on their own archive, and a comment feed nowhere', function (): void {
    $links = ($this->alternates)('/harbour-news/');
    $feeds = array_values(array_filter($links, fn (array $link): bool => str_ends_with($link[0], '/feed/')));

    expect($feeds)->toHaveCount(1)
        ->and(array_filter($links, fn (array $link): bool => str_contains($link[0], 'comments')))->toBe([]);
});

it('escapes a name that holds markup in the title of its link', function (): void {
    $term = typesTerm($this->editor, 'category', 'Fish & "Chips" <b>');
    $path = (string) addrCurrent('term', $term->id);
    $html = (string) addrGet('/'.$path.'/')->assertOk()->getContent();

    expect(($this->alternates)('/'.$path.'/'))->toContain([rtrim(url('/'), '/').'/'.$path.'/feed/', "{$this->site} » Fish & \"Chips\" <b> Category Feed"])
        ->and($html)->not->toContain('<b> Category Feed');
});

it('finds an archive\'s own path from the request path, without its page number', function (): void {
    $cases = ['/category/news/' => 'category/news', '/category/news/page/2/' => 'category/news', '/author/jane-doe/page/12' => 'author/jane-doe', '/page/3/' => ''];
    $wrong = [];

    foreach ($cases as $request => $path) {
        if (FeedLinks::archivePath((string) $request) !== $path) {
            $wrong[] = $request;
        }
    }

    expect($wrong)->toBe([]);
});
