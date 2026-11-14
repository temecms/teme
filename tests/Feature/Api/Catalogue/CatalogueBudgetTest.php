<?php

declare(strict_types=1);

use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Api\CatalogueProbe;
use Tests\Fixtures\Api\DeliverySite;
use Tests\Fixtures\Api\Switches;
use Tests\Fixtures\Fields\StoredFieldRows;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'http://localhost']);
    $this->site = DeliverySite::build();
    Switches::apiOn();
});

/**
 * The statements one request makes.
 */
function catalogueStatements(string $uri): int
{
    // One request to fill the caches a site keeps between requests (settings, stored types and fields), then
    // the one measured, as a request of its own: what the application remembers for one request starts empty.
    test()->get($uri)->assertOk();
    app()->forgetScopedInstances();

    return CatalogueProbe::statements(fn () => test()->get($uri)->assertOk());
}

it('answers the site, the types and the taxonomies in at most 5 statements each, the same for 1 type and 6', function (): void {
    $addresses = ['/api/v1', '/api/v1/types', '/api/v1/taxonomies'];
    $before = array_map(catalogueStatements(...), $addresses);

    // Three more public types, each with two fields, so six hold fields.
    foreach (['book', 'album', 'recipe'] as $position => $key) {
        ctStoreRow(ctDefinition(['key' => $key, 'singular' => ucfirst($key), 'plural' => ucfirst($key).'s', 'segment' => $key.'s', 'taxonomies' => ['category']]));
        app(ContentTypeRegistry::class)->refreshStored();
        StoredFieldRows::store(StoredFieldRows::entry('01KA0DE1S1TE00000000000B0'.$position, $key.'_one', ['type' => $key]), 20 + $position * 2);
        StoredFieldRows::store(StoredFieldRows::entry('01KA0DE1S1TE00000000000C0'.$position, $key.'_two', ['type' => $key, 'fieldType' => 'number']), 21 + $position * 2);
    }

    $after = array_map(catalogueStatements(...), $addresses);

    expect(max($before))->toBeLessThanOrEqual(5)
        ->and(max($after))->toBeLessThanOrEqual(5)
        ->and($after)->toBe($before);

    $types = $this->get('/api/v1/types')->json('data');

    expect(collect($types)->firstWhere('key', 'recipe')['fields'])->toHaveCount(2);

});

it('pins the cold answers: what a site reads again only when the cache it keeps between requests is empty', function (): void {
    // Paid once each time that cache is filled (after a change to a type, field or taxonomy, or a flush), by whichever
    // request comes first, and never per request: the warm figures above are what every other request costs.
    $cold = function (string $uri): int {
        Cache::flush();
        app()->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($uri)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // The site: the three settings groups. Types: the stored types, taxonomies and fields (three reads and the two
    // checks that their tables exist) and two settings groups. Taxonomies: the stored types and taxonomies and one group.
    expect([$cold('/api/v1'), $cold('/api/v1/types'), $cold('/api/v1/taxonomies')])->toBe([3, 7, 4]);
});
