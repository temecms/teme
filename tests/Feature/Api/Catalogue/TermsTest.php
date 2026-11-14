<?php

declare(strict_types=1);

use App\Domain\Api\ApiWords;
use App\Domain\Api\Live;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fixtures\Api\CatalogueProbe;
use Tests\Fixtures\Api\DeliverySite;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'http://localhost']);
    $this->site = DeliverySite::build();
    Switches::apiOn();
});

/**
 * Makes this many tags, named so they sort in order.
 *
 * @return list<string> their IDs
 */
function catalogueTags(int $many): array
{
    $ids = [];

    for ($number = 1; $number <= $many; $number++) {
        $ids[] = typesTerm(test()->site->admin, 'tag', 'Tag '.str_pad((string) $number, 3, '0', STR_PAD_LEFT))->id;
    }

    return $ids;
}

it('lists every public taxonomy\'s terms by name, 20 by default, with counts', function (): void {
    catalogueTags(22);

    $response = $this->get('/api/v1/terms')->assertOk()->assertHeader('Content-Type', 'application/json');
    $terms = $response->json('data');
    $names = array_column($terms, 'name');
    $sorted = $names;
    usort($sorted, fn (string $one, string $other): int => strcmp($one, $other));

    expect(array_keys((array) $response->json()))->toBe(['data', 'meta', 'links'])
        ->and($response->json('meta'))->toBe(['limit' => 20])
        ->and($terms)->toHaveCount(20)
        ->and($names)->toBe($sorted)
        ->and(array_keys($response->json('links')))->toEqualCanonicalizing(['self', 'next'])
        ->and($response->json('links.self'))->toBe('http://localhost/api/v1/terms')
        ->and($response->json('links.next'))->toStartWith('http://localhost/api/v1/terms?after=')
        ->and(array_diff(array_column($terms, 'taxonomy'), Live::taxonomies()))->toBe([]);

    foreach ($terms as $term) {
        expect(array_keys($term))->toEqualCanonicalizing(['id', 'taxonomy', 'name', 'slug', 'path', 'url', 'description', 'parent_id', 'count', 'links'])
            ->and(array_keys($term['links']))->toEqualCanonicalizing(['self', 'items'])
            ->and($term['count'])->toBeInt();
    }

    $genre = $this->get('/api/v1/terms?taxonomy=genre')->assertOk()->json('data.0');

    expect($genre)->toBe([
        'id' => $this->site->genre->id,
        'taxonomy' => 'genre',
        'name' => 'Science fiction',
        'slug' => 'science-fiction',
        'path' => '/genre/science-fiction/',
        'url' => 'http://localhost/genre/science-fiction/',
        'description' => 'Films about what might be.',
        'parent_id' => null,
        'count' => 1,
        'links' => [
            'self' => 'http://localhost/api/v1/terms/'.$this->site->genre->id,
            'items' => 'http://localhost/api/v1/items?term='.$this->site->genre->id,
        ],
    ]);
});

it('keeps only one taxonomy, one parent, the top level or one slug', function (): void {
    $child = typesTerm($this->site->admin, 'genre', 'Space opera', $this->site->genre->id);
    catalogueTags(2);

    $in = fn (string $query): array => $this->get('/api/v1/terms'.$query)->assertOk()->json('data');

    expect(array_unique(array_column($in('?taxonomy=tag'), 'taxonomy')))->toBe(['tag'])
        ->and(array_column($in('?taxonomy=genre'), 'name'))->toBe(['Science fiction', 'Space opera'])
        ->and(array_column($in('?taxonomy=genre&parent='.$this->site->genre->id), 'id'))->toBe([$child->id])
        ->and(array_column($in('?taxonomy=genre&parent=none'), 'id'))->toBe([$this->site->genre->id])
        ->and($in('?taxonomy=genre&parent='.$child->id))->toBe([])
        ->and(array_column($in('?slug=space-opera'), 'id'))->toBe([$child->id])
        ->and($in('?slug=no-such-term'))->toBe([])
        ->and($in('?taxonomy=genre&slug=space-opera')[0]['parent_id'])->toBe($this->site->genre->id);
});

it('follows links.next to the end, each term once', function (): void {
    $made = catalogueTags(12);
    $seen = [];
    $next = 'http://localhost/api/v1/terms?limit=5&taxonomy=tag';
    $pages = 0;

    while ($next !== null) {
        $response = $this->get($next)->assertOk();
        $pages++;

        expect($response->json('meta'))->toBe(['limit' => 5]);

        foreach ($response->json('data') as $term) {
            $seen[] = $term['id'];
        }

        $next = $response->json('links.next');
    }

    expect($pages)->toBeGreaterThanOrEqual(3)
        ->and($seen)->toBe($made)
        ->and(array_unique($seen))->toBe($seen);
});

it('counts a term\'s published public items only', function (): void {
    $drama = typesTerm($this->site->admin, 'genre', 'Drama');
    $admin = $this->site->admin;
    $author = $this->site->author;
    $count = fn (): int => $this->get('/api/v1/terms?taxonomy=genre&slug=drama')->assertOk()->json('data.0.count');

    expect($count())->toBe(0);

    CatalogueProbe::publishedMovie($admin, $author, 'Drama one', [], CatalogueProbe::plainBody(), [$drama->id]);
    $private = CatalogueProbe::publishedMovie($admin, $author, 'Drama two', [], CatalogueProbe::plainBody(), [$drama->id]);
    app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($private->id, 'private', null, (string) $admin->id));
    CatalogueProbe::publishedMovie($admin, $author, 'Drama three', [], CatalogueProbe::plainBody(), [$drama->id], publish: false);
    $protected = CatalogueProbe::publishedMovie($admin, $author, 'Drama four', [], CatalogueProbe::plainBody(), [$drama->id]);
    app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($protected->id, 'protected', 'hunter2', (string) $admin->id));

    // One public, one protected (counted as the site counts it); the private item and the draft are not.
    expect($count())->toBe(2);
});

it('answers one term, and 404 for a term of a taxonomy that isn\'t public, as for none', function (): void {
    $genre = $this->site->genre;

    $response = $this->get('/api/v1/terms/'.$genre->id)->assertOk()->assertHeader('Content-Type', 'application/json');

    expect(array_keys((array) $response->json()))->toBe(['data', 'links'])
        ->and($response->json('links'))->toBe(['self' => 'http://localhost/api/v1/terms/'.$genre->id])
        ->and(array_keys($response->json('data')))->toEqualCanonicalizing(['id', 'taxonomy', 'name', 'slug', 'path', 'url', 'description', 'parent_id', 'count', 'links'])
        ->and($response->json('data.id'))->toBe($genre->id)
        ->and($response->json('data.count'))->toBe(1);

    $shelf = $this->site->shelf->id;
    $never = strtolower((string) Str::ulid());
    $none = $this->get('/api/v1/terms/'.$never)->assertNotFound();
    $hidden = $this->get('/api/v1/terms/'.$shelf)->assertNotFound();

    expect($none->json('code'))->toBe('not_found')
        ->and($none->json('detail'))->toBe(ApiWords::noTerm($never))
        ->and($hidden->json('detail'))->toBe(ApiWords::noTerm($shelf))
        ->and(str_replace($shelf, '<id>', (string) $hidden->getContent()))->toBe(str_replace($never, '<id>', (string) $none->getContent()))
        ->and($hidden->headers->get('Content-Type'))->toBe($none->headers->get('Content-Type'));
});

it('refuses taxonomy=shelf with the words for a typo', function (): void {
    $shelf = $this->get('/api/v1/terms?taxonomy=shelf')->assertStatus(400);
    $typo = $this->get('/api/v1/terms?taxonomy=shlef')->assertStatus(400);

    expect($shelf->json('code'))->toBe('invalid_parameter')
        ->and($shelf->json('errors'))->toBe([['parameter' => 'taxonomy', 'detail' => ApiWords::badTaxonomy(Live::taxonomies())]])
        ->and((array) $shelf->json())->toBe((array) $typo->json())
        ->and($shelf->json('errors.0.detail'))->not->toContain('shelf');
});

it('refuses a parent, a slug, a limit and a cursor that don\'t fit', function (string $query, string $parameter, string $detail): void {
    $this->get('/api/v1/terms?'.$query)->assertStatus(400)
        ->assertJsonPath('code', 'invalid_parameter')
        ->assertJsonPath('errors.0.parameter', $parameter)
        ->assertJsonPath('errors.0.detail', $detail);
})->with([
    'a parent that is no ID' => ['parent=nonsense', 'parent', 'parent must be the ID of a term, or none.'],
    'a parent in capitals' => ['parent=01KA0DE1S1TE0000000000RD01', 'parent', 'parent must be the ID of a term, or none.'],
    'a slug too long' => ['slug='.str_repeat('a', 201), 'slug', 'slug must be at most 200 characters.'],
    'a limit of nothing' => ['limit=0', 'limit', 'limit must be a whole number from 1 to 100.'],
    'a limit too big' => ['limit=101', 'limit', 'limit must be a whole number from 1 to 100.'],
    'a cursor that isn\'t one' => ['after=garbage', 'after', 'after must be a cursor this list gave, from links.next.'],
    'an unknown parameter' => ['colour=red', 'colour', 'colour isn’t a parameter this address takes.'],
]);

it('refuses a cursor from another list and one whose key is no name', function (): void {
    $cursor = fn (array $parts): string => rtrim(strtr(base64_encode(json_encode($parts, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $id = strtolower((string) Str::ulid());

    foreach ([
        $cursor(['-published_at', '2026-11-09T08:00:00Z', $id]),
        $cursor(['name', "bad\0name", $id]),
        $cursor(['name', str_repeat('n', 256), $id]),
    ] as $after) {
        $this->get('/api/v1/terms?after='.$after)->assertStatus(400)->assertJsonPath('errors.0.parameter', 'after');
    }

    $this->get('/api/v1/terms?after='.$cursor(['name', 'Drama', $id]))->assertOk();
});

it('reads a page of terms in at most 6 statements, the same for 1 and 100', function (): void {
    // A first request fills what a site keeps between requests; the measured one is a request of its own.
    $statements = function (): int {
        $this->get('/api/v1/terms?taxonomy=genre&limit=100')->assertOk();
        app()->forgetScopedInstances();

        return CatalogueProbe::statements(fn () => $this->get('/api/v1/terms?taxonomy=genre&limit=100')->assertOk());
    };

    $one = $statements();

    foreach (range(1, 100) as $number) {
        typesTerm($this->site->admin, 'genre', 'Bulk '.str_pad((string) $number, 3, '0', STR_PAD_LEFT));
    }

    $hundred = $statements();

    expect($this->get('/api/v1/terms?taxonomy=genre&limit=100')->json('data'))->toHaveCount(100)
        ->and($one)->toBeLessThanOrEqual(6)
        ->and($hundred)->toBeLessThanOrEqual(6)
        ->and($hundred)->toBe($one);
});

it('reads one term in at most 5 statements', function (): void {
    $this->get('/api/v1/terms/'.$this->site->genre->id)->assertOk();
    app()->forgetScopedInstances();

    expect(CatalogueProbe::statements(fn () => $this->get('/api/v1/terms/'.$this->site->genre->id)->assertOk()))->toBeLessThanOrEqual(5);
});

it('refuses any parameter on one term', function (): void {
    $this->get('/api/v1/terms/'.$this->site->genre->id.'?limit=1')->assertStatus(400)->assertJsonPath('errors.0.parameter', 'limit');
});
