<?php

declare(strict_types=1);

use App\Domain\Api\Cursor;
use App\Domain\Api\Live;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\Api\DeliverySite;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'https://example.com']);
    URL::forceRootUrl('https://example.com');
    URL::forceScheme('https');
});

/**
 * The IDs this list address gives, on one page of 100.
 *
 * @return list<string>
 */
function itemsFilterIds(string $address): array
{
    return array_column(test()->getJson($address)->assertOk()->json('data'), 'id');
}

/**
 * The errors of a 400 answer, by parameter.
 *
 * @return array<string, string>
 */
function itemsFilterErrors(TestResponse $response): array
{
    $response->assertStatus(400)->assertHeader('Content-Type', 'application/problem+json');

    return collect($response->json('errors'))->mapWithKeys(fn (array $error): array => [$error['parameter'] => $error['detail']])->all();
}

it('keeps only the types named, comma-separated', function (): void {
    $site = DeliverySite::build();
    Switches::apiOn();
    $movies = [$site->public->id, $site->sequel->id, $site->protected->id, $site->newerDraft->id, $site->pointer->id];

    expect(itemsFilterIds('/api/v1/items?limit=100&type=movie'))->toEqualCanonicalizing($movies)
        ->and(itemsFilterIds('/api/v1/items?limit=100&type=page'))->toBe([$site->about->id])
        ->and(itemsFilterIds('/api/v1/items?limit=100&type=movie,page'))->toEqualCanonicalizing([...$movies, $site->about->id])
        ->and(itemsFilterIds('/api/v1/items?limit=100&type=post'))->toBe([]);
});

it('refuses a type that isn\'t public with the words it gives a typo', function (): void {
    DeliverySite::build();
    Switches::apiOn();
    $words = 'type must name public types of this site, separated by commas, at most 10: '.implode(', ', Live::types()).'.';

    expect(itemsFilterErrors($this->getJson('/api/v1/items?type=region')))->toBe(['type' => $words])
        ->and(itemsFilterErrors($this->getJson('/api/v1/items?type=documentary')))->toBe(['type' => $words])
        ->and(itemsFilterErrors($this->getJson('/api/v1/items?type=shelfz')))->toBe(['type' => $words])
        ->and(itemsFilterErrors($this->getJson('/api/v1/items?type=movie,region')))->toBe(['type' => $words])
        ->and(itemsFilterErrors($this->getJson('/api/v1/items?type=')))->toBe(['type' => $words])
        ->and(itemsFilterErrors($this->getJson('/api/v1/items?type=movie,page,a,b,c,d,e,f,g,h,i')))->toBe(['type' => $words])
        ->and($words)->not->toContain('region')->not->toContain('documentary');
});

it('keeps only items filed under a term, not under its children', function (): void {
    $site = DeliverySite::build();
    Switches::apiOn();
    $child = typesTerm($site->admin, 'genre', 'Space opera', $site->genre->id);
    $filedUnderChild = itemsFilterMovie($site, 'Dune', [$child->id]);

    expect(itemsFilterIds('/api/v1/items?limit=100&term='.$site->genre->id))->toBe([$site->public->id])
        ->and(itemsFilterIds('/api/v1/items?limit=100&term='.$child->id))->toBe([$filedUnderChild->id]);
});

it('refuses a term of a taxonomy that isn\'t public with the words it gives an unknown ID', function (): void {
    $site = DeliverySite::build();
    Switches::apiOn();
    $words = ['term' => 'term must be the ID of a term in a public taxonomy.'];

    expect(itemsFilterErrors($this->getJson('/api/v1/items?term='.$site->shelf->id)))->toBe($words)
        ->and(itemsFilterErrors($this->getJson('/api/v1/items?term=01jcaa0b1c2d3e4f5g6h7j8k9m')))->toBe($words)
        ->and(itemsFilterErrors($this->getJson('/api/v1/items?term='.strtoupper($site->genre->id))))->toBe($words)
        ->and(itemsFilterErrors($this->getJson('/api/v1/items?term=science-fiction')))->toBe($words);
});

it('keeps only a person\'s published items, by their slug', function (): void {
    $site = DeliverySite::build();
    Switches::apiOn();
    $slug = (string) DB::table('users')->where('id', $site->author->id)->value('slug');
    $mine = [$site->public->id, $site->sequel->id, $site->protected->id, $site->newerDraft->id, $site->pointer->id];

    expect(itemsFilterIds('/api/v1/items?limit=100&author='.$slug))->toEqualCanonicalizing($mine)
        ->and(itemsFilterIds('/api/v1/items?limit=100&type=page&author='.$slug))->toBe([]);
});

it('answers an empty list for a person with nothing published, as for no such person', function (): void {
    $site = DeliverySite::build();
    Switches::apiOn();
    $slug = (string) DB::table('users')->where('id', $site->author2->id)->value('slug');

    $unpublished = $this->getJson('/api/v1/items?author='.$slug)->assertOk()->json();
    $nobody = $this->getJson('/api/v1/items?author=nobody')->assertOk()->json();

    expect($unpublished['data'])->toBe([])
        ->and($nobody['data'])->toBe([])
        ->and($unpublished['links']['next'])->toBeNull()
        ->and($nobody['links']['next'])->toBeNull();
});

it('keeps items published from published_since and before published_before', function (): void {
    $admin = ctAdmin();
    Switches::apiOn();
    $this->travelTo('2026-11-10T08:00:00Z');
    $first = addrPublished($admin, 'First');
    $this->travelTo('2026-11-10T09:00:00Z');
    $second = addrPublished($admin, 'Second');
    $this->travelTo('2026-11-10T10:00:00Z');
    $third = addrPublished($admin, 'Third');
    $this->travelTo('2026-11-11T10:00:00Z');

    expect(itemsFilterIds('/api/v1/items?published_since=2026-11-10T09:00:00Z'))->toBe([$third->id, $second->id])
        ->and(itemsFilterIds('/api/v1/items?published_before=2026-11-10T09:00:00Z'))->toBe([$first->id])
        ->and(itemsFilterIds('/api/v1/items?published_since=2026-11-10T09:00:00Z&published_before=2026-11-10T10:00:00Z'))->toBe([$second->id])
        // The same moment with an offset.
        ->and(itemsFilterIds('/api/v1/items?published_since=2026-11-10T10:00:00%2B01:00'))->toBe([$third->id, $second->id]);
});

it('refuses a date with no offset, a cursor of the other sort, every bad value at once, and an unknown name first', function (): void {
    $admin = ctAdmin();
    Switches::apiOn();
    $newest = Cursor::after('-published_at', '2026-11-10T09:00:00Z', '01jcb7y1r9n3w4m5p6q7s8t9v0');
    $notADate = Cursor::after('-published_at', 'yesterday', '01jcb7y1r9n3w4m5p6q7s8t9v0');
    addrPublished($admin, 'One');

    expect(itemsFilterErrors($this->getJson('/api/v1/items?published_since=2026-11-10T09:00:00')))
        ->toBe(['published_since' => 'published_since must be a date and time with its offset, such as 2026-11-10T09:30:00Z.'])
        ->and(itemsFilterErrors($this->getJson('/api/v1/items?published_before=2026-02-30T09:00:00Z')))
        ->toBe(['published_before' => 'published_before must be a date and time with its offset, such as 2026-11-10T09:30:00Z.'])
        ->and(itemsFilterErrors($this->getJson('/api/v1/items?sort=published_at&after='.$newest)))
        ->toBe(['after' => 'after must be a cursor this list gave, from links.next.'])
        ->and(itemsFilterErrors($this->getJson('/api/v1/items?after='.$notADate)))
        ->toBe(['after' => 'after must be a cursor this list gave, from links.next.'])
        ->and($this->getJson('/api/v1/items?after='.$newest)->assertOk()->json('data'))->toBe([])
        ->and(itemsFilterErrors($this->getJson('/api/v1/items?limit=0&sort=newest&type=nope&term=x&published_since=today&published_before=2026')))
        ->toEqualCanonicalizing([
            'limit' => 'limit must be a whole number from 1 to 100.',
            'sort' => 'sort must be published_at, -published_at, or position with parent.',
            'type' => 'type must name public types of this site, separated by commas, at most 10: '.implode(', ', Live::types()).'.',
            'term' => 'term must be the ID of a term in a public taxonomy.',
            'published_since' => 'published_since must be a date and time with its offset, such as 2026-11-10T09:30:00Z.',
            'published_before' => 'published_before must be a date and time with its offset, such as 2026-11-10T09:30:00Z.',
        ])
        // A name the list doesn't take is refused before any value is read (ApiQuery), so it comes alone.
        ->and(itemsFilterErrors($this->getJson('/api/v1/items?colour=red&limit=0&sort=newest')))
        ->toBe(['colour' => 'colour isn’t a parameter this address takes.']);
});

it('refuses a cursor or a date before the year 1, in UTC, as a bad parameter, and reports nothing', function (): void {
    Switches::apiOn();
    Exceptions::fake();

    // The cursor's key is 0000-01-01T00:00:00Z; the date is the year 1 an hour ahead of UTC, so the year 0 in UTC.
    $cursor = $this->getJson('/api/v1/items?after=WyItcHVibGlzaGVkX2F0IiwiMDAwMC0wMS0wMVQwMDowMDowMFoiLCIwMWpjYjd5MXI5bjN3NG01cDZxN3M4dDl2MCJd');
    $date = $this->getJson('/api/v1/items?published_since=0001-01-01T00:00:00%2B01:00');

    $cursor->assertStatus(400)->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('code', 'invalid_parameter');
    $date->assertStatus(400)->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('code', 'invalid_parameter');

    expect(array_keys(itemsFilterErrors($cursor)))->toBe(['after'])
        ->and(array_keys(itemsFilterErrors($date)))->toBe(['published_since']);

    Exceptions::assertNothingReported();
});

/**
 * A published movie of the fixture's, filed under these terms.
 *
 * @param  list<string>  $terms
 */
function itemsFilterMovie(DeliverySite $site, string $title, array $terms): Content
{
    $movie = lifecycleDraft($site->author, $title, 'movie');
    $movie->refresh();
    app(SaveDraft::class)->execute(new SaveDraftInput(
        $movie->id, $title, ['type' => 'doc', 'content' => []], [], $movie->working_revision_id, (string) $site->author->id, $terms,
    ));
    listsPublish($movie, $site->admin);

    return $movie->refresh();
}
