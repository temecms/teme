<?php

declare(strict_types=1);

use App\Domain\Fediverse\Remote\FetchRefused;
use App\Domain\Fediverse\Remote\RemoteFetcher;
use App\Domain\Fediverse\Signatures\KeyOwners;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Fediverse\Actors;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    importResolve(['remote.example' => ['93.184.216.34'], 'other.example' => ['93.184.216.35'], 'inside.example' => ['10.0.0.5']]);
    Http::preventStrayRequests();
});

it('reads a good actor document, asking for activity JSON and signing as the application', function (): void {
    Actors::application();
    Http::fake(['https://remote.example/users/alice' => Http::response(Actors::remoteDocument(), 200, ['Content-Type' => 'application/activity+json'])]);

    $document = app(RemoteFetcher::class)->get('https://remote.example/users/alice');

    expect($document['id'])->toBe('https://remote.example/users/alice');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Accept', 'application/activity+json')
        && str_contains($request->header('Signature')[0] ?? '', '/ap/application#main-key'));
});

it('refuses an address that resolves to a private network', function (): void {
    Http::fake();

    expect(fn () => app(RemoteFetcher::class)->get('https://inside.example/users/bob'))->toThrow(FetchRefused::class);
    Http::assertNothingSent();
});

it('refuses a redirect to a private network', function (): void {
    Http::fake(['https://remote.example/users/alice' => Http::response('', 302, ['Location' => 'https://inside.example/users/alice'])]);

    expect(fn () => app(RemoteFetcher::class)->get('https://remote.example/users/alice'))->toThrow(FetchRefused::class);
    Http::assertSentCount(1);
});

it('refuses plain http', function (): void {
    Http::fake();

    expect(fn () => app(RemoteFetcher::class)->get('http://remote.example/users/alice'))->toThrow(FetchRefused::class);
    Http::assertNothingSent();
});

it('refuses an answer over a megabyte', function (): void {
    Http::fake(['https://remote.example/users/alice' => Http::response(json_encode([...Actors::remoteDocument(), 'summary' => str_repeat('a', 1_048_577)]), 200)]);

    expect(fn () => app(RemoteFetcher::class)->get('https://remote.example/users/alice'))->toThrow(FetchRefused::class);
});

it('refuses a document whose id is on another host', function (): void {
    Http::fake(['https://remote.example/users/alice' => Http::response(Actors::remoteDocument('https://other.example/users/alice'), 200)]);

    expect(fn () => app(RemoteFetcher::class)->get('https://remote.example/users/alice'))->toThrow(FetchRefused::class);
});

it('refuses more than three redirects', function (): void {
    Http::fake(['https://remote.example/*' => Http::response('', 302, ['Location' => 'https://remote.example/again'])]);

    expect(fn () => app(RemoteFetcher::class)->get('https://remote.example/users/alice'))->toThrow(FetchRefused::class);
    Http::assertSentCount(4);
});

it('finds a key’s owner by fetching the key’s actor', function (): void {
    Http::fake(['https://remote.example/users/alice' => Http::response(Actors::remoteDocument(), 200)]);

    $owner = app(KeyOwners::class)->owner('https://remote.example/users/alice#main-key');

    expect($owner?->ownerUri)->toBe('https://remote.example/users/alice')
        ->and($owner?->publicKeyPem)->toBe(Actors::keys('remote')['public']);
});

it('takes a key’s owner from a remote account it already holds, without fetching', function (): void {
    Actors::remote();
    Http::fake();

    expect(app(KeyOwners::class)->owner('https://remote.example/users/alice#main-key')?->ownerUri)->toBe('https://remote.example/users/alice');
    Http::assertNothingSent();
});

it('refuses a key whose actor does not name it', function (): void {
    Http::fake(['https://remote.example/users/alice' => Http::response([...Actors::remoteDocument(), 'publicKey' => ['id' => 'https://remote.example/users/alice#other-key', 'owner' => 'https://remote.example/users/alice', 'publicKeyPem' => 'x']], 200)]);

    expect(app(KeyOwners::class)->owner('https://remote.example/users/alice#main-key'))->toBeNull();
});

it('refuses a key kept on another server than its actor’s, even when the actor names it', function (): void {
    $key = ['id' => 'https://other.example/keys/alice', 'owner' => 'https://remote.example/users/alice', 'publicKeyPem' => Actors::keys('remote')['public']];
    Http::fake([
        'https://other.example/keys/alice' => Http::response($key, 200),
        'https://remote.example/users/alice' => Http::response([...Actors::remoteDocument(), 'publicKey' => $key], 200),
    ]);

    expect(app(KeyOwners::class)->owner('https://other.example/keys/alice'))->toBeNull();
});
