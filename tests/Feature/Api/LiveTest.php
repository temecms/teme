<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Api\Live;
use App\Domain\Contents\Models\Content;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Api\DeliverySite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'http://localhost']);
    $this->site = DeliverySite::build();
});

/**
 * The IDs Live keeps, of these types or of every public type.
 *
 * @param  list<string>|null  $types
 *
 * @return list<string>
 */
function liveIds(?array $types = null): array
{
    return Live::items($types)->pluck('contents.id')->map(fn (mixed $id): string => (string) $id)->all();
}

it('keeps published, live, not private items of public types, and nothing else', function (): void {
    $site = $this->site;

    $states = [
        'published, public' => [$site->public, true],
        'published, another public' => [$site->sequel, true],
        'published, protected' => [$site->protected, true],
        'a page, published' => [$site->about, true],
        'published, with a newer draft' => [$site->newerDraft, true],
        'private' => [$site->private, false],
        'a draft' => [$site->draft, false],
        'scheduled' => [$site->scheduled, false],
        'published, dated ahead' => [$site->ahead, false],
        'published, naming a draft in a field' => [$site->pointer, true],
        'published in a type no longer public' => [$site->retired, false],
        'in the trash' => [$site->trashed, false],
        'a region, published' => [$site->region, false],
    ];

    $kept = liveIds();

    foreach ($states as $state => [$item, $live]) {
        /** @var Content $item */
        expect(in_array($item->id, $kept, true))->toBe($live, $state);

        // Held to the site's own answer: an address that answers 200 for every live item, and none for the rest.
        // Not for a type made not public behind UpdateType's back: the API adds the type's public flag (D19).
        if ($item->is($site->retired)) {
            continue;
        }

        $path = Addresses::relativeUrl($item);
        $answered = $path === null ? null : addrGet($path)->getStatusCode();

        expect($answered === 200)->toBe($live, $state);
    }

    expect($kept)->toEqualCanonicalizing([$site->public->id, $site->sequel->id, $site->protected->id, $site->about->id, $site->newerDraft->id, $site->pointer->id]);
});

it('keeps only the public types asked for, and none for a type that isn\'t public', function (): void {
    $site = $this->site;
    $movies = [$site->public->id, $site->sequel->id, $site->protected->id, $site->newerDraft->id, $site->pointer->id];

    expect(liveIds(['movie']))->toEqualCanonicalizing($movies)
        ->and(liveIds(['movie', 'region']))->toEqualCanonicalizing($movies)
        ->and(liveIds(['region']))->toBe([])
        ->and(liveIds(['documentary']))->toBe([])
        ->and(liveIds(['nothing']))->toBe([])
        ->and(liveIds([]))->toBe([])
        ->and(Live::types())->toContain('movie')->not->toContain('region')->not->toContain('documentary');
});

it('lists the public taxonomies, not shelf', function (): void {
    expect(Live::taxonomies())->toContain('genre', 'category', 'tag')
        ->not->toContain('shelf');
});
