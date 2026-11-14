<?php

declare(strict_types=1);

use App\Domain\Site\ArticleViews;
use App\Domain\Site\RelatedPosts;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
});

it('finds posts through the rarest shared term first, and says why', function (): void {
    $everything = taxonomyTerm('category', 'Everything');
    $herons = taxonomyTerm('tag', 'Herons');
    foreach (range(1, 5) as $i) {
        $this->travel(1)->minutes();
        pageArticle($this->author, "General {$i}", categories: [$everything]);
    }
    $this->travel(1)->minutes();
    pageArticle($this->author, 'Heron spotted', categories: [$everything], tags: [$herons]);
    $this->travel(1)->minutes();
    $article = pageArticle($this->author, 'Herons at dawn', categories: [$everything], tags: [$herons]);

    $related = app(RelatedPosts::class)->for($article->refresh());

    expect($related)->toHaveCount(3)
        ->and($related[0]['item']->title)->toBe('Heron spotted')
        ->and($related[0]['reason'])->toBe('Also tagged Herons')
        ->and($related[1]['reason'])->toBe('Also in Everything')
        ->and(array_map(fn ($r) => $r['item']->title, $related))->not->toContain('Herons at dawn');
});

it('reaches the article as its template sees it', function (): void {
    $herons = taxonomyTerm('tag', 'Herons');
    pageArticle($this->author, 'Heron spotted', tags: [$herons]);
    $article = pageArticle($this->author, 'Herons at dawn', tags: [$herons]);

    $related = app(ArticleViews::class)->published($article->refresh())->related;

    expect($related)->toHaveCount(1)
        ->and($related[0]['item']->title)->toBe('Heron spotted')
        ->and($related[0]['reason'])->toBe('Also tagged Herons');
});

it('returns nothing rather than the latest posts when nothing is shared', function (): void {
    pageArticle($this->author, 'Unrelated', categories: [taxonomyTerm('category', 'Elsewhere')]);
    $lonely = pageArticle($this->author, 'Lonely', categories: [taxonomyTerm('category', 'Alone')]);

    expect(app(RelatedPosts::class)->for($lonely->refresh()))->toBe([]);
});

it('never lists a draft or a trashed post', function (): void {
    $topic = taxonomyTerm('category', 'Topic');
    $trashed = pageArticle($this->author, 'Trashed', categories: [$topic]);
    pageTrash($trashed, $this->author);
    // Filed under the topic in its working revision, but never published.
    typesDraft($this->author, 'post', 'Only a draft', [$topic->id]);
    $article = pageArticle($this->author, 'The article', categories: [$topic]);

    expect(app(RelatedPosts::class)->for($article->refresh()))->toBe([]);
});

it('asks the same number of queries for an article with one term as for one with twenty (1.6h)', function (): void {
    $harbours = taxonomyTerm('tag', 'Harbours');
    foreach (range(1, 3) as $i) {
        $this->travel(1)->minutes();
        pageArticle($this->author, "Harbour {$i}", tags: [$harbours]);
    }
    // Nineteen tags no other post carries, so they come first, rarest, and
    // share nothing: a query per term would ask twenty before finding three.
    $rare = array_map(fn (int $i) => taxonomyTerm('tag', "Rare {$i}"), range(1, 19));
    $this->travel(1)->minutes();
    $one = pageArticle($this->author, 'One term', tags: [$harbours]);
    $this->travel(1)->minutes();
    $twenty = pageArticle($this->author, 'Twenty terms', tags: [...$rare, $harbours]);

    $queries = fn ($article): int => count(planStatements(fn () => app(RelatedPosts::class)->for($article->refresh())));

    expect(app(RelatedPosts::class)->for($twenty->refresh()))->toHaveCount(3)
        ->and(app(RelatedPosts::class)->for($one->refresh()))->toHaveCount(3)
        ->and($queries($twenty))->toBe($queries($one));
});
