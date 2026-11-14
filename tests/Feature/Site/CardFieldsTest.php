<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Types\SelectField;
use App\Domain\Site\LatestViews;
use App\Domain\Site\ListingViews;
use App\Domain\Site\SearchViews;
use App\Domain\Site\SiteDates;
use App\Domain\Site\SiteLanguage;
use App\Domain\Site\ViewModels\ListingItem;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Site\Movies;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->admin = ctAdmin();
    lscrTimezone('Africa/Lagos');
    $this->language = app(SiteLanguage::class)->tag();
});

/**
 * Twelve published movies, each released on 11 November 2016, rated PG-13,
 * with its own poster and Arrival II as its sequel, and a featured image
 * when asked; their poster IDs by title.
 *
 * @return array<string, string>
 */
function cfTwelve(bool $featured = false): array
{
    Movies::type(supports: $featured ? ['featured_image'] : []);
    $sequel = Movies::movie(test()->admin, 'Arrival II');
    $posters = [];

    foreach (range(1, 12) as $n) {
        $poster = pageImage(test()->admin, 600, 900)->id;
        $fields = [Movies::RELEASE => '2016-11-11', Movies::RATING => 'pg-13', Movies::POSTER => Movies::image($poster), Movies::SEQUEL => $sequel->id];

        if ($featured) {
            $fields[BuiltInFields::FEATURED_IMAGE] = Movies::image(pageImage(test()->admin, 1200, 800)->id);
        }

        Movies::movie(test()->admin, "Movie {$n}", $fields);
        $posters["Movie {$n}"] = $poster;
    }

    return $posters;
}

/**
 * The SQL of every statement building the Movies listing's first page
 * runs, counted afresh, as a request starts with nothing remembered.
 *
 * @return list<string>
 */
function cfStatements(): array
{
    app(ListingViews::class)->forType('movie', 'movies', 1);
    app()->forgetScopedInstances();
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });
    app(ListingViews::class)->forType('movie', 'movies', 1);

    return $statements;
}

/**
 * Removes these fields from every published movie, as if they had never
 * been filled.
 *
 * @param  list<string>  $keys
 */
function cfClear(array $keys): void
{
    foreach ($keys as $key) {
        DB::table('contents')->where('type', 'movie')->update(['fields' => DB::raw("fields - '{$key}'")]);
    }
}

it('gives each card its type and its fields, by handle', function (): void {
    $posters = cfTwelve();

    $items = app(ListingViews::class)->forType('movie', 'movies', 1)->items;

    expect($items[0]->type)->toBe('movie')
        ->and($items[0]->field('release_date')->text)->toBe(SiteDates::show(new DateTimeImmutable('2016-11-11', new DateTimeZone('Africa/Lagos')), $this->language, 'LL'))
        ->and($items[0]->field('rating')->text)->toBe('PG-13')
        ->and($items[0]->field('poster')->image?->id)->toBe($posters[$items[0]->title])
        ->and($items[0]->field('sequel')->url)->toBe('/movies/arrival-ii/')
        ->and($items[0]->field('no_such_field'))->toBeNull();
});

it('reads a page of cards’ fields with one statement more, never one a card', function (): void {
    cfTwelve();

    $withItems = cfStatements();
    cfClear([Movies::SEQUEL]);
    $withoutItems = cfStatements();
    cfClear([Movies::POSTER]);
    $plain = cfStatements();
    cfClear([Movies::RELEASE, Movies::RATING]);
    $none = cfStatements();

    expect(count($withItems))->toBe(count($withoutItems) + 1)
        // No item and no image among the values: no statement at all for them.
        ->and(count($plain))->toBe(count($none));
});

it('shares the featured images’ one read with the cards’ media fields', function (): void {
    cfTwelve(featured: true);

    $media = array_filter(cfStatements(), fn (string $sql): bool => str_contains($sql, 'from "media"'));

    expect($media)->toHaveCount(1);
});

it('gives a protected item’s card no field values', function (): void {
    Movies::type();
    $movie = Movies::movie($this->admin, 'Sealed', [Movies::RATING => 'pg-13']);
    app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($movie->id, 'protected', 'open sesame', (string) $this->admin->id));

    $card = app(ListingViews::class)->forType('movie', 'movies', 1)->items[0];

    expect($card->title)->toBe('Sealed')
        ->and($card->fieldViews)->toBe([])
        ->and($card->field('rating'))->toBeNull();
});

it('gives cards on every listing their fields: a term’s, an author’s, a date’s, a search’s and the latest posts', function (string $listing): void {
    app(FieldRegistry::class)->register(new Field('01KA41B000000000000000CF01', 'rating', 'Rating', 'A rating.', new SelectField, ['post'], '0.2.0', options: ['' => 'None', 'pg-13' => 'PG-13']));
    $category = taxonomyTerm('category', 'Cinema');
    pagePublish($this->admin, 'Zanzibar nights', [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A review from Zanzibar.']]]], ['01KA41B000000000000000CF01' => 'pg-13'], [$category->id], null);

    $model = match ($listing) {
        'term' => app(ListingViews::class)->forTerm($category->refresh(), 1),
        'author' => app(ListingViews::class)->forAuthor($this->admin, 1),
        'date' => app(ListingViews::class)->forDate((int) SiteDates::forView('now')->format('Y'), null, null, 1),
        'search' => app(SearchViews::class)->for('Zanzibar', 1),
        'latest' => app(LatestViews::class)->latest(1),
    };
    $items = $listing === 'latest' ? array_filter([$model->lead, ...$model->featured, ...$model->feed->items]) : $model->items;
    $card = array_values(array_filter($items, fn (ListingItem $item): bool => $item->title === 'Zanzibar nights'))[0] ?? null;

    expect($card?->field('rating')?->text)->toBe('PG-13');
})->with(['term', 'author', 'date', 'search', 'latest']);
