<?php

declare(strict_types=1);

use App\Domain\Site\ArticleViews;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
});

it('builds an article from the published revision only, with a written standfirst, kicker, byline and reading time', function (): void {
    $news = taxonomyTerm('category', 'News');
    $post = pageArticle($this->author, 'Big story', words: 476, excerpt: 'A written summary', categories: [$news]);
    pageSaveDraft($post, 'A newer working title');

    $view = app(ArticleViews::class)->published($post->refresh());

    expect($view->title)->toBe('Big story')
        ->and($view->standfirst)->toBe('A written summary')
        ->and($view->kicker->name)->toBe('News')
        ->and($view->authors[0]->name)->toBe('Jane Doe')
        ->and($view->readingMinutes)->toBe(2);
});

it('leaves the standfirst empty when nobody wrote an excerpt', function (): void {
    $post = pageArticle($this->author, 'No summary');

    expect(app(ArticleViews::class)->published($post->refresh())->standfirst)->toBeNull();
});

it('makes the featured image the lead', function (): void {
    $image = pageImage($this->author, 1600, 900);
    $post = pageArticle($this->author, 'Pictured', featured: $image);

    $view = app(ArticleViews::class)->published($post->refresh());

    expect($view->lead?->id)->toBe($image->id)->and($view->leadIsFeatured)->toBeTrue();
});

it('makes an image card placed before any text the lead when there is no featured image', function (): void {
    $image = pageImage($this->author, 1600, 900);
    $nodeId = (string) Str::ulid();
    $post = pageArticleFromDocument($this->author, 'Opens on a picture', [
        pageImageNode($image, $nodeId),
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Then words.']]],
    ]);

    $view = app(ArticleViews::class)->published($post->refresh());

    expect($view->lead?->id)->toBe($image->id)->and($view->leadIsFeatured)->toBeFalse();
});

it('has no lead when the first image comes after the text', function (): void {
    $image = pageImage($this->author, 1600, 900);
    $post = pageArticleFromDocument($this->author, 'Words first', [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Words first.']]],
        pageImageNode($image, (string) Str::ulid()),
    ]);

    expect(app(ArticleViews::class)->published($post->refresh())->lead)->toBeNull();
});

it('shows Updated only when the published revision is at least a day newer', function (): void {
    $post = pageArticle($this->author, 'Evolving');
    expect(app(ArticleViews::class)->published($post->refresh())->updated)->toBeNull();

    $this->travel(25)->hours();
    pageSaveDraft($post, 'Evolving');
    listsPublish($post, $this->author);

    expect(app(ArticleViews::class)->published($post->refresh())->updated)->not->toBeNull();
});
