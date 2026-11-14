<?php

declare(strict_types=1);

use App\Domain\Site\ArticleViews;
use App\Domain\Site\LatestViews;
use App\Domain\Site\ListingViews;
use App\Domain\Site\SearchViews;
use App\Domain\Site\ViewModels\Subject;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Site\Movies;
use Tests\Fixtures\Site\PlainValues;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    lscrTimezone('Africa/Lagos');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
    Movies::type(['runtime' => 'before', 'rating' => 'after', 'notes' => 'after', 'sequel' => 'after']);
    $this->site = dtSite($this->author);
    $this->sequel = Movies::movie($this->admin, 'Arrival II');
    $this->movie = Movies::movie($this->admin, 'Arrival', [
        Movies::RELEASE => '2016-11-11', Movies::PREMIERE => '2016-09-01T18:30:00+00:00', Movies::RUNTIME => 116,
        Movies::RATING => 'pg-13', Movies::CINEMAS => true, Movies::TRAILER => 'https://example.com/t', Movies::TAGLINE => 'Why are they here?',
        Movies::NOTES => "Line one\nLine two", Movies::POSTER => Movies::image(pageImage($this->admin, 800, 1200)->id, 'The poster'),
        Movies::SEQUEL => $this->sequel->id,
    ]);
});

it('hands a template no Carbon, Laravel or Symfony object on any kind of page', function (string $kind): void {
    $model = match ($kind) {
        'an article' => app(ArticleViews::class)->published($this->site['longRead']->refresh()),
        'a movie with every kind of field' => app(ArticleViews::class)->published($this->movie),
        'a type’s listing' => app(ListingViews::class)->forType('movie', 'movies', 1),
        'a term’s listing' => app(ListingViews::class)->forTerm($this->site['news'], 1),
        'an author’s listing' => app(ListingViews::class)->forAuthor($this->author, 1),
        'a date’s listing' => app(ListingViews::class)->forDate((int) now()->format('Y'), null, null, 1),
        'the latest posts' => app(LatestViews::class)->latest(1),
        'a search' => app(SearchViews::class)->for('lifeboat', 1),
    };

    expect(PlainValues::offenders($model))->toBe([]);
})->with(['an article', 'a movie with every kind of field', 'a type’s listing', 'a term’s listing', 'an author’s listing', 'a date’s listing', 'the latest posts', 'a search']);

it('refuses Teme’s own class when it extends a framework one, as a person extends Laravel’s user', function (): void {
    $planted = new Subject('term', 'Africa', children: [$this->author]);

    expect(PlainValues::offenders($planted))->toBe(['Subject::$children[0]: '.User::class]);
});

it('walks every field of the movie, long text and a linked item among them', function (): void {
    $views = app(ArticleViews::class)->published($this->movie)->fieldViews;

    expect($views['notes']->value)->toBe("Line one\nLine two")
        ->and([$views['sequel']->text, $views['sequel']->url, $views['sequel']->value])->toBe(['Arrival II', '/movies/arrival-ii/', $this->sequel->id])
        ->and($views['release_date']->value)->toBeInstanceOf(DateTimeInterface::class);
});

it('gives dates as PHP’s own DateTimeImmutable in the site’s zone', function (): void {
    $article = app(ArticleViews::class)->published($this->site['longRead']->refresh());
    $card = app(ListingViews::class)->forTerm($this->site['news'], 1)->items[0];

    expect(get_class($article->published))->toBe(DateTimeImmutable::class)
        ->and($article->published->getTimezone()->getName())->toBe('Africa/Lagos')
        ->and(get_class($article->revised))->toBe(DateTimeImmutable::class)
        ->and(get_class($card->published))->toBe(DateTimeImmutable::class)
        ->and($card->published->getTimezone()->getName())->toBe('Africa/Lagos');
});
