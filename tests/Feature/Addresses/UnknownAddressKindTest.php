<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    app(PathProjection::class)->syncUser($this->author);
    pageArticle($this->author, 'Harbour news');
    // An ID of another kind, as a later release writes it: a ULID whose
    // leading digits are this account's, so an integer cast finds Jane.
    $this->ulid = substr($this->author->id.'kv7m3q9x2c5r8t1w4y6z0b3d5f', 0, 26);
});

/**
 * One row of the path table, written as a later release would.
 */
function unknownKindRow(string $path, string $type, string $id, bool $current): void
{
    DB::table('paths')->insert(['path' => $path, 'target_type' => $type, 'target_id' => $id, 'is_current' => $current, 'created_at' => now(), 'replaced_at' => $current ? null : now()]);
}

it('answers an address of a kind it does not know with 404, and its feed too', function (string $kind): void {
    unknownKindRow('people/jane', $kind, $this->ulid, true);

    addrGet('/people/jane/')->assertNotFound()->assertDontSee('Jane Doe')->assertDontSee('Harbour news');
    addrGet('/people/jane/page/2/')->assertNotFound();
    addrGet('/people/jane')->assertNotFound();
    addrGet('/people/jane/feed/')->assertNotFound()->assertDontSee('Jane Doe');
})->with(['gadget', 'widget']);

it('neither redirects nor draws an old address of a kind it does not know', function (string $kind): void {
    unknownKindRow('people/jane', $kind, $this->ulid, true);
    unknownKindRow('people/old-jane', $kind, $this->ulid, false);

    addrGet('/people/old-jane/')->assertNotFound()->assertDontSee('Jane Doe');
    addrGet('/people/old-jane/feed/')->assertNotFound()->assertDontSee('Jane Doe');
})->with(['gadget', 'widget']);

it('answers an account\'s address whose ID is not an account\'s with 404, never another account\'s page', function (): void {
    unknownKindRow('writers/odd', 'user', $this->ulid, true);
    unknownKindRow('writers/old-odd', 'user', $this->ulid, false);

    addrGet('/writers/odd/')->assertNotFound()->assertDontSee('Jane Doe');
    addrGet('/writers/odd/feed/')->assertNotFound()->assertDontSee('Jane Doe');
    addrGet('/writers/old-odd/')->assertNotFound();
    addrGet('/writers/old-odd/feed/')->assertNotFound();
});

it('still serves an author\'s page and feed beside an address of an unknown kind', function (): void {
    unknownKindRow('people/jane', 'gadget', $this->ulid, true);

    addrGet('/author/jane-doe/')->assertOk()->assertSee('Harbour news');
    addrGet('/author/jane-doe/feed/')->assertOk()->assertSee('Jane Doe');
});

it('stores no author page at an address of an unknown kind', function (): void {
    $folder = cacheOn();

    try {
        unknownKindRow('people/jane', 'gadget', $this->ulid, true);

        addrGet('/people/jane/')->assertNotFound();
        $stored = cacheEntry('/people/jane/');

        expect($stored === null || ($stored->status === 404 && ! str_contains($stored->body, 'Harbour news')))->toBeTrue();
        addrGet('/people/jane/')->assertNotFound()->assertDontSee('Harbour news');
    } finally {
        cacheOff($folder);
    }
});
