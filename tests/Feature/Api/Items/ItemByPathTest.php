<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Api\Items\ItemQuery;
use App\Domain\Api\Live;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\CardMedia;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\Fixtures\Api\DeliverySite;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'https://example.com']);
    URL::forceRootUrl('https://example.com');
    URL::forceScheme('https');
    $this->site = DeliverySite::build();
    Switches::apiOn();
});

/**
 * The by-path address for this site address.
 */
function byPathAddress(string $path): string
{
    return '/api/v1/items/by-path?path='.rawurlencode($path);
}

it('answers the item at its current address, moved false', function (): void {
    $site = $this->site;
    $path = (string) Addresses::relativeUrl($site->public);

    $answer = $this->getJson(byPathAddress($path))->assertOk()->json();
    $byId = $this->getJson('/api/v1/items/'.$site->public->id)->json('data');

    expect(array_keys($answer))->toEqualCanonicalizing(['data', 'meta', 'links'])
        ->and($answer['data'])->toBe($byId)
        ->and($answer['meta'])->toBe(['requested_path' => $path, 'moved' => false])
        ->and($answer['links'])->toBe(['self' => 'https://example.com/api/v1/items/'.$site->public->id]);
});

it('answers the item at an old address, moved true, its path the new one', function (): void {
    $site = $this->site;
    $old = (string) Addresses::relativeUrl($site->public);
    addrRename($site->public, 'arrival-2016', $site->admin);
    $new = (string) Addresses::relativeUrl($site->public->refresh());

    $answer = $this->getJson(byPathAddress($old))->assertOk()->json();

    expect($answer['data']['id'])->toBe($site->public->id)
        ->and($answer['data']['path'])->toBe($new)->not->toBe($old)
        ->and($answer['meta'])->toBe(['requested_path' => $old, 'moved' => true]);
});

it('answers the page shown as the home page at /', function (): void {
    $site = $this->site;
    $settings = AddressSettings::current();
    // The public types' patterns only: the fixture's documentary is no longer public, so its pattern can't be saved.
    $patterns = array_intersect_key($settings->patterns, array_flip(Live::types()));
    addrChange($site->admin, ['patterns' => $patterns, 'bases' => $settings->bases, 'frontShows' => 'page', 'frontPageId' => $site->about->id]);

    $answer = $this->getJson(byPathAddress('/'))->assertOk()->json();

    expect($answer['data']['id'])->toBe($site->about->id)
        ->and($answer['data']['path'])->toBe('/')
        ->and($answer['meta'])->toBe(['requested_path' => '/', 'moved' => false]);
});

it('answers 404 for a term\'s, an author\'s, a date\'s or an archive\'s address', function (): void {
    $site = $this->site;
    $addresses = [
        (string) Addresses::relativeUrl($site->genre),
        // The author base's address for the person, whether or not their page has a row yet.
        '/author/'.DB::table('users')->where('id', $site->author->id)->value('slug').'/',
        '/2026/',
        '/movies/',
        '/nowhere/',
    ];

    foreach ($addresses as $address) {
        $this->getJson(byPathAddress($address))->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'not_found')
            ->assertJsonPath('detail', "No published item has the address {$address}.");
    }
});

it('refuses a path that doesn\'t start with /, holds ? or #, or is over 2,048 characters', function (): void {
    $words = [['parameter' => 'path', 'detail' => 'path must be an address on this site, starting with /, without ? or #, at most 2,048 characters.']];

    foreach (['movies/arrival/', '/movies/arrival/?x=1', '/movies/arrival/#cast', '/'.str_repeat('a', 2048), ''] as $path) {
        $this->getJson(byPathAddress($path))->assertStatus(400)->assertJsonPath('code', 'invalid_parameter')->assertJsonPath('errors', $words);
    }

    $this->getJson('/api/v1/items/by-path')->assertStatus(400)->assertJsonPath('errors', $words);
    $this->getJson(byPathAddress('/'.str_repeat('a', 2047)))->assertNotFound();
});

it('decodes the path once, so an encoded NUL leads nowhere, and refuses a control character', function (): void {
    $path = rtrim((string) Addresses::relativeUrl($this->site->public), '/');

    // %2500 is %00 once decoded: text, not a NUL, so no address cut short at it.
    $this->getJson('/api/v1/items/by-path?path='.$path.'%2500x')->assertNotFound()->assertJsonPath('code', 'not_found');

    foreach (['%00x', '%0Ax', '%7F'] as $control) {
        $this->getJson('/api/v1/items/by-path?path='.$path.$control)
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_parameter')
            ->assertJsonPath('errors.0.parameter', 'path');
    }

    $this->getJson('/api/v1/items/by-path?path='.$path.'/')->assertOk()->assertJsonPath('data.title', 'Arrival');
});

it('answers 404 at the old address of an item made private', function (): void {
    $site = $this->site;
    $old = (string) Addresses::relativeUrl($site->public);
    addrRename($site->public, 'arrival-2016', $site->admin);
    app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($site->public->id, 'private', null, (string) $site->admin->id));

    $this->getJson(byPathAddress($old))->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->getJson(byPathAddress('/movies/arrival-2016/'))->assertNotFound();
});

it('tags a by-path answer, 404 included, with the asked address, so a publication there purges it', function (): void {
    $site = $this->site;
    $path = (string) Addresses::relativeUrl($site->public);
    $record = app(RenderRecord::class);

    $record->start(0);
    $found = app(ItemQuery::class)->byPath($path);
    $foundTags = $record->tags();

    $record->start(0);
    $missing = app(ItemQuery::class)->byPath('/movies/not-yet/');
    $missingTags = $record->tags();

    expect($found)->not->toBeNull()
        ->and($foundTags)->toContain(PageTags::url($path), PageTags::content($site->public->id))
        ->and($missing)->toBeNull()
        ->and($missingTags)->toContain(PageTags::url('/movies/not-yet/'));
});

it('reads by path in at most 16 statements', function (): void {
    $address = byPathAddress((string) Addresses::relativeUrl($this->site->public));
    $this->getJson($address)->assertOk();
    // The files a request has read are kept for that request only (CardMedia); a drawn answer reads its own.
    app()->forgetInstance(CardMedia::class);

    $statements = planStatements(fn () => $this->getJson($address)->assertOk());

    expect(count($statements))->toBeLessThanOrEqual(16);
});
