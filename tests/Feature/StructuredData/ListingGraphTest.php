<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->author->forceFill(['bio' => 'Covers the harbour board.'])->save();
    app(PathProjection::class)->syncUser($this->author);
});

it('describes a term\'s or a date\'s archive as a collection page at its own address, and lists none of its items (I-701, I-705)', function (string $archive): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00', 'UTC'));
    headPost($this->author, 'Harbour news', [], [headTerm('category', 'News', 'Stories from the harbour board.')]);
    [$uri, $description] = $archive === 'a category' ? ['/category/news/', 'Stories from the harbour board.'] : ['/2026/10/', null];
    $page = 'http://localhost'.$uri;

    $graph = sdGraph($uri);

    expect(array_column($graph, '@id'))->toBe(['http://localhost/#website', $page])
        ->and(sdNode($graph, $page))->toEqual(array_filter([
            '@id' => $page, '@type' => 'CollectionPage', 'url' => $page, 'name' => headValue(headDom($uri), '//title'),
            'description' => $description, 'isPartOf' => ['@id' => 'http://localhost/#website'], 'inLanguage' => 'en',
        ]))
        ->and((string) json_encode($graph))->not->toContain('ItemList');
})->with(['a category', 'a month']);

it('gives each page of an archive its own address as its @id (I-701)', function (): void {
    $news = headTerm('category', 'News');

    foreach (range(1, 11) as $n) {
        headPost($this->author, "Story {$n}", [], [$news]);
    }

    expect(sdNode(sdGraph('/category/news/page/2/'), 'http://localhost/category/news/page/2/'))
        ->toMatchArray(['@type' => 'CollectionPage', 'url' => 'http://localhost/category/news/page/2/']);
});

it('describes an author\'s archive as a profile page whose main entity is the person their articles name (I-696, I-701)', function (): void {
    headPost($this->author, 'Harbour news');
    $person = 'http://localhost/author/jane-doe/#person';

    $graph = sdGraph('/author/jane-doe/');

    expect(sdNode($graph, 'http://localhost/author/jane-doe/'))->toMatchArray(['@type' => 'ProfilePage', 'mainEntity' => ['@id' => $person]])
        ->and(sdNode($graph, $person))->toEqual(['@id' => $person, '@type' => 'Person', 'name' => 'Jane Doe', 'url' => 'http://localhost/author/jane-doe/', 'description' => 'Covers the harbour board.'])
        ->and(sdNode(sdGraph('/harbour-news/'), 'http://localhost/harbour-news/#article')['author'])->toBe([['@id' => $person]]);
});

it('describes search results as a collection page and a search results page, at the search\'s own address (I-701)', function (): void {
    headPost($this->author, 'Harbour news');

    expect(sdNode(sdGraph('/?s=harbour'), 'http://localhost/?s=harbour'))
        ->toMatchArray(['@type' => ['CollectionPage', 'SearchResultsPage'], 'url' => 'http://localhost/?s=harbour']);
});

it('describes the latest posts as a collection page, at the site\'s address or the posts page\'s (I-701)', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    headPost($this->author, 'Harbour news');
    $front = sdGraph('/');
    $news = addrPublished($editor, 'News', 'page');
    $about = addrPublished($editor, 'About us', 'page');
    addrChange(User::factory()->create()->assignRole('administrator'), ['frontShows' => 'page', 'frontPageId' => $about->id, 'postsPageId' => $news->id]);

    expect(sdNode($front, 'http://localhost/'))->toMatchArray(['@type' => 'CollectionPage', 'url' => 'http://localhost/'])
        ->and(sdNode(sdGraph('/news/'), 'http://localhost/news/'))->toMatchArray(['@type' => 'CollectionPage', 'url' => 'http://localhost/news/']);
});

it('gives a page not found and core\'s reply pages the site alone (I-701)', function (string $page): void {
    $comment = cmComment(cmItem($this->author, 'Harbour news'), attributes: ['author_name' => 'Bo Reader']);
    [$uri, $status] = $page === 'a page not found' ? ['/no-such-page/', 404] : ["/_teme/comment/{$comment->id}/reply", 200];

    expect(array_column(sdGraph($uri, $status), '@id'))->toBe(['http://localhost/#website']);
})->with(['a page not found', 'a comment\'s reply page']);
