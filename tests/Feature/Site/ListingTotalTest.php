<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Site\LatestViews;
use App\Domain\Site\ListingViews;
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
    app(PathProjection::class)->syncUser($this->author);
});

it('gives a category\'s exact item count on every page, not pages times page size', function (): void {
    $news = taxonomyTerm('category', 'News');
    app(PathProjection::class)->syncTerm($news);

    foreach (range(1, 23) as $i) {
        $this->travel(1)->minutes();
        pageArticle($this->author, "Story {$i}", categories: [$news]);
    }

    pageArticle($this->author, 'Elsewhere');

    $listings = app(ListingViews::class);

    expect($listings->forTerm($news->refresh(), 1)->total)->toBe(23)
        ->and($listings->forTerm($news->refresh(), 3)->total)->toBe(23)
        ->and($listings->forTerm($news->refresh(), 3)->items)->toHaveCount(3);
});

it('counts only what readers can see', function (): void {
    $news = taxonomyTerm('category', 'News');
    app(PathProjection::class)->syncTerm($news);
    pageArticle($this->author, 'Shown', categories: [$news]);
    $hidden = pageArticle($this->author, 'Private', categories: [$news]);
    app(TrashContent::class)->execute(new ContentActionInput($hidden->id, (string) User::factory()->create()->assignRole('administrator')->id));

    expect(app(ListingViews::class)->forTerm($news->refresh(), 1)->total)->toBe(1);
});

it('gives the author\'s, a date\'s and a type\'s totals', function (): void {
    ltTypes();
    ltItem($this->author, 'film', 'Arrival');
    ltItem($this->author, 'film', 'Dune');
    pageArticle($this->author, 'A post');
    $listings = app(ListingViews::class);

    expect($listings->forType('film', 'films', 1)->total)->toBe(2)
        ->and($listings->forAuthor($this->author, 1)->total)->toBe(3)
        ->and($listings->forDate((int) now()->year, null, null, 1)->total)->toBe(3);
});

it('gives the latest posts\' total, the lead and pinned posts among it', function (): void {
    pageArticle($this->author, 'Pinned', sticky: true);
    $this->travel(1)->minutes();
    pageArticle($this->author, 'Plain one');
    pageArticle($this->author, 'Plain two');

    $latest = app(LatestViews::class)->latest(1);

    expect($latest->feed->total)->toBe(3)
        ->and(app(LatestViews::class)->latest(1)->feed->items)->toHaveCount(2);
});
