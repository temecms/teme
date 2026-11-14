<?php

declare(strict_types=1);

use App\Domain\Addresses\ListingTypes;
use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\RenderRecord;
use App\Domain\Feeds\FeedBuilder;
use App\Domain\Feeds\FeedItem;
use App\Domain\Feeds\FeedSubject;
use App\Domain\People\Credit;
use App\Domain\Taxonomy\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    app(PathProjection::class)->syncUser($this->author);
    ltTypes();
    $this->news = typesTerm($this->admin, 'category', 'News');
    $this->quick = typesTerm($this->admin, 'tag', 'Quick');
    $this->titles = fn (FeedSubject $subject): array => array_map(fn (FeedItem $item): string => $item->title, app(FeedBuilder::class)->build($subject)->items);
});

it('carries posts alone in the main feed until Reading ticks Films, each item at its own type’s address (I-3298)', function (): void {
    addrChange($this->admin, ['patterns' => ['film' => '/films/{slug}']]);
    ltItem($this->author, 'post', 'Harbour story');
    $this->travel(1)->minutes();
    ltItem($this->author, 'film', 'Arrival');
    $root = rtrim(url('/'), '/');
    $site = fn (): array => array_map(fn (FeedItem $item): array => [$item->title, $item->url], app(FeedBuilder::class)->build(FeedSubject::site())->items);

    expect($site())->toBe([['Harbour story', $root.'/harbour-story/']]);

    cmSettings([ListingTypes::SETTING => ['post', 'film']], 'reading');

    expect($site())->toBe([['Arrival', $root.'/films/arrival'], ['Harbour story', $root.'/harbour-story/']]);
});

it('carries every public type filed under a term in its feed: posts and films under a category, posts and recipes under a tag', function (): void {
    ltItem($this->author, 'post', 'Harbour story', [$this->news, $this->quick]);
    $this->travel(1)->minutes();
    ltItem($this->author, 'film', 'Arrival', [$this->news]);
    $this->travel(1)->minutes();
    ltItem($this->author, 'recipe', 'Fish stew', [$this->quick]);
    $term = fn (Term $term): FeedSubject => new FeedSubject(FeedSubject::TERM, (string) $term->name, (string) addrCurrent('term', (string) $term->id), null, (string) $term->taxonomy, (string) $term->id, null);

    expect(($this->titles)($term($this->news)))->toBe(['Arrival', 'Harbour story'])
        ->and(($this->titles)($term($this->quick)))->toBe(['Fish stew', 'Harbour story']);
});

it('carries an author’s posts and films in their feed, never their recipes', function (): void {
    ltItem($this->author, 'post', 'Harbour story');
    $this->travel(1)->minutes();
    ltItem($this->author, 'film', 'Arrival');
    $this->travel(1)->minutes();
    ltItem($this->author, 'recipe', 'Fish stew');

    expect(($this->titles)(new FeedSubject(FeedSubject::AUTHOR, 'Jane Doe', (string) addrCurrent('user', (string) $this->author->id), null, null, null, Credit::account((int) $this->author->id))))
        ->toBe(['Arrival', 'Harbour story']);
});

it('records each ticked type’s list for the page cache on the main feed (I-263)', function (): void {
    cmSettings([ListingTypes::SETTING => ['post', 'film']], 'reading');
    app(RenderRecord::class)->start(0);

    app(FeedBuilder::class)->build(FeedSubject::site());

    expect(app(RenderRecord::class)->tags())->toContain('feed', 'list:post', 'list:film')
        ->not->toContain('list:recipe');
});

it('reads as many statements for a main feed of three types as for one of posts alone', function (): void {
    ltDocumentaries();

    foreach (range(1, 3) as $n) {
        $this->travel(1)->minutes();
        ltItem($this->author, 'post', "Story {$n}");
    }

    $count = function (): int {
        app(FeedBuilder::class)->build(FeedSubject::site());
        app()->forgetScopedInstances();

        return count(dashQueries(fn () => app(FeedBuilder::class)->build(FeedSubject::site())));
    };
    $alone = $count();

    foreach (['film', 'documentary'] as $type) {
        $this->travel(1)->minutes();
        ltItem($this->author, $type, "Newest {$type}");
    }

    cmSettings([ListingTypes::SETTING => ['post', 'film', 'documentary']], 'reading');

    expect($count())->toBe($alone)
        ->and(($this->titles)(FeedSubject::site()))->toContain('Newest film', 'Newest documentary');
});
