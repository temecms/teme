<?php

declare(strict_types=1);

use App\Domain\Fediverse\Signatures\KeyOwner;
use App\Domain\Fediverse\Signatures\KeyOwners;
use App\Domain\Fediverse\Signatures\SignatureRefused;
use App\Domain\Fediverse\Signatures\Verifier;
use Teme\Contracts\Routes\PublicRequest;
use Tests\Fixtures\Fediverse\Actors;
use Tests\Fixtures\Fediverse\FakeKeyOwners;
use Tests\Fixtures\Fediverse\Rfc9421;

const RFC_ALICE = 'https://remote.example/users/alice';

beforeEach(function (): void {
    $this->owners = (new FakeKeyOwners)->serve(new KeyOwner(RFC_ALICE.'#main-key', RFC_ALICE, Actors::keys('remote')['public']));
    app()->instance(KeyOwners::class, $this->owners);

    $this->body = json_encode(['id' => RFC_ALICE.'#follows/1', 'type' => 'Follow', 'actor' => RFC_ALICE, 'object' => 'https://site.example/ap/blog'], JSON_THROW_ON_ERROR);

    // A POST to the site's shared inbox, signed by Alice with RFC 9421; $change alters it after signing.
    $this->signed = function (array $change = [], ?string $body = null, array $components = ['@method', '@target-uri', 'content-digest'], array $params = [], string $path = '/ap/inbox'): PublicRequest {
        $headers = ['host' => 'site.example', 'content-type' => 'application/activity+json'];
        $headers = [...$headers, ...Rfc9421::sign('POST', 'https://site.example/ap/inbox', $headers, $this->body, RFC_ALICE.'#main-key', $components, $params)];

        return new PublicRequest('POST', $path, [], [], [...$headers, ...$change], $body ?? $this->body, '203.0.113.9');
    };
});

it('verifies an RFC 9421 request as Mastodon signs it, and names its sender', function (): void {
    $sender = app(Verifier::class)->verify(($this->signed)());

    expect($sender->actorUri)->toBe(RFC_ALICE)
        ->and($sender->keyId)->toBe(RFC_ALICE.'#main-key');
});

it('verifies one that covers the authority, path and a header instead of the target, and checks the base as sent', function (): void {
    $request = ($this->signed)(components: ['@method', '@authority', '@path', 'content-digest', 'content-type'], params: ['alg' => 'rsa-v1_5-sha256']);

    expect(app(Verifier::class)->verify($request)->actorUri)->toBe(RFC_ALICE);

    $headers = ['host' => 'site.example'];
    $headers = [...$headers, ...Rfc9421::sign('POST', 'https://site.example/ap/inbox', $headers, $this->body, RFC_ALICE.'#main-key', ['@method', '@target-uri', 'content-digest'])];
    $headers['signature-input'] = preg_replace('/;alg="[^"]*"/', '', $headers['signature-input']);

    // Without alg the signed base changes too, so this one no longer opens: the base is checked as sent.
    expect(fn () => app(Verifier::class)->verify(new PublicRequest('POST', '/ap/inbox', [], [], $headers, $this->body, '203.0.113.9')))
        ->toThrow(SignatureRefused::class, 'signature');
});

it('refuses an RFC 9421 request whose body was changed after signing', function (): void {
    app(Verifier::class)->verify(($this->signed)(body: str_replace('Follow', 'Undo', $this->body)));
})->throws(SignatureRefused::class, 'digest');

it('refuses an RFC 9421 request sent to another address than the one signed', function (): void {
    app(Verifier::class)->verify(($this->signed)(path: '/ap/blog/inbox'));
})->throws(SignatureRefused::class, 'signature');

it('refuses an RFC 9421 request created outside the window', function (): void {
    app(Verifier::class)->verify(($this->signed)(params: ['created' => time() - 13 * 3600]));
})->throws(SignatureRefused::class, 'date');

it('refuses an RFC 9421 request past its expiry', function (): void {
    app(Verifier::class)->verify(($this->signed)(params: ['expires' => time() - 60]));
})->throws(SignatureRefused::class, 'date');

it('refuses an RFC 9421 POST whose signature does not cover the content digest', function (): void {
    app(Verifier::class)->verify(($this->signed)(components: ['@method', '@target-uri']));
})->throws(SignatureRefused::class, 'covered');

it('refuses an RFC 9421 request that covers neither the target nor the authority and path', function (): void {
    app(Verifier::class)->verify(($this->signed)(components: ['@method', 'content-digest']));
})->throws(SignatureRefused::class, 'covered');

it('refuses an RFC 9421 request whose key belongs to someone other than the activity’s actor', function (): void {
    $this->owners->serve(new KeyOwner('https://remote.example/users/bob#main-key', 'https://remote.example/users/bob', Actors::keys('remote')['public']));

    app(Verifier::class)->verify(($this->signed)(params: ['keyid' => 'https://remote.example/users/bob#main-key']));
})->throws(SignatureRefused::class, 'actor');

it('refuses an RFC 9421 algorithm Teme does not verify', function (): void {
    app(Verifier::class)->verify(($this->signed)(params: ['alg' => 'ed25519']));
})->throws(SignatureRefused::class, 'algorithm');

it('refuses a Signature-Input that does not parse, or a Signature with no matching label', function (array $change): void {
    app(Verifier::class)->verify(($this->signed)($change));
})->throws(SignatureRefused::class, 'parse')->with([
    'not a dictionary' => [['signature-input' => 'sig1=("@method"']],
    'another label' => [['signature' => 'sig2=:AAAA:']],
]);
