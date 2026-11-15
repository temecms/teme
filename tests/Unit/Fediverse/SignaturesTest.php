<?php

declare(strict_types=1);

use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Signatures\KeyOwner;
use App\Domain\Fediverse\Signatures\KeyOwners;
use App\Domain\Fediverse\Signatures\SignatureRefused;
use App\Domain\Fediverse\Signatures\Signer;
use App\Domain\Fediverse\Signatures\Verifier;
use Teme\Contracts\Routes\PublicRequest;
use Tests\Fixtures\Fediverse\Actors;
use Tests\Fixtures\Fediverse\FakeKeyOwners;

const FEDI_ALICE = 'https://remote.example/users/alice';

beforeEach(function (): void {
    // Alice signs with the remote key pair, as a remote server would.
    $this->alice = new FediverseActor(['uri' => FEDI_ALICE, 'private_key' => Actors::keys('remote')['private']]);
    $this->owners = (new FakeKeyOwners)->serve(new KeyOwner(FEDI_ALICE.'#main-key', FEDI_ALICE, Actors::keys('remote')['public']));
    app()->instance(KeyOwners::class, $this->owners);

    $this->body = json_encode(['id' => FEDI_ALICE.'#follows/1', 'type' => 'Follow', 'actor' => FEDI_ALICE, 'object' => 'https://site.example/ap/blog'], JSON_THROW_ON_ERROR);

    // A request to the site's shared inbox, signed by Alice; $change alters it after signing.
    $this->signed = function (array $change = [], ?string $body = null, string $method = 'POST'): PublicRequest {
        $headers = ['host' => 'site.example', 'content-type' => 'application/activity+json'];

        foreach (app(Signer::class)->sign($method, 'https://site.example/ap/inbox', $headers, $this->body, $this->alice) as $name => $value) {
            $headers[strtolower($name)] = $value;
        }

        return new PublicRequest($method, '/ap/inbox', [], [], [...$headers, ...$change], $body ?? $this->body, '203.0.113.9');
    };
});

it('signs a POST with a digest, a date and a draft-cavage signature over the four headers', function (): void {
    $headers = app(Signer::class)->sign('POST', 'https://site.example/ap/inbox?x=1', ['host' => 'site.example'], 'body', $this->alice);

    expect($headers['Digest'])->toBe('SHA-256='.base64_encode(hash('sha256', 'body', true)))
        ->and(strtotime($headers['Date']))->toBeGreaterThan(time() - 5)
        ->and($headers['Signature'])->toStartWith('keyId="'.FEDI_ALICE.'#main-key",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="');

    preg_match('/signature="([^"]+)"/', $headers['Signature'], $match);
    $string = "(request-target): post /ap/inbox?x=1\nhost: site.example\ndate: {$headers['Date']}\ndigest: {$headers['Digest']}";

    expect(openssl_verify($string, base64_decode($match[1]), Actors::keys('remote')['public'], OPENSSL_ALGO_SHA256))->toBe(1);
});

it('signs a GET without a digest', function (): void {
    $headers = app(Signer::class)->sign('GET', 'https://remote.example/users/bob', [], '', $this->alice);

    expect($headers)->not->toHaveKey('Digest')
        ->and($headers['Signature'])->toContain('headers="(request-target) host date"');
});

it('verifies a signed request and names its sender', function (): void {
    $sender = app(Verifier::class)->verify(($this->signed)());

    expect($sender->actorUri)->toBe(FEDI_ALICE)
        ->and($sender->keyId)->toBe(FEDI_ALICE.'#main-key')
        ->and($this->owners->fetches)->toBe(1);
});

it('refuses a request whose body was changed after signing', function (): void {
    app(Verifier::class)->verify(($this->signed)(body: str_replace('Follow', 'Undo', $this->body)));
})->throws(SignatureRefused::class, 'digest');

it('refuses a request whose covered header was changed after signing', function (): void {
    app(Verifier::class)->verify(($this->signed)(['host' => 'other.example']));
})->throws(SignatureRefused::class, 'signature');

it('refuses a request whose date is outside the window', function (): void {
    $this->travel(-13)->hours();
    $old = ($this->signed)();
    $this->travelBack();

    app(Verifier::class)->verify($old);
})->throws(SignatureRefused::class, 'date');

it('refuses a request whose key belongs to someone other than the activity’s actor', function (): void {
    $this->owners->serve(new KeyOwner(FEDI_ALICE.'#main-key', 'https://remote.example/users/mallory', Actors::keys('remote')['public']));

    app(Verifier::class)->verify(($this->signed)());
})->throws(SignatureRefused::class, 'actor');

it('refuses a POST whose signature does not cover the digest', function (): void {
    $request = ($this->signed)();
    $headers = $request->headers;
    $string = "(request-target): post /ap/inbox\nhost: site.example\ndate: {$headers['date']}";
    openssl_sign($string, $signature, Actors::keys('remote')['private'], OPENSSL_ALGO_SHA256);
    $headers['signature'] = 'keyId="'.FEDI_ALICE.'#main-key",algorithm="rsa-sha256",headers="(request-target) host date",signature="'.base64_encode($signature).'"';

    app(Verifier::class)->verify(new PublicRequest('POST', '/ap/inbox', [], [], $headers, $this->body, '203.0.113.9'));
})->throws(SignatureRefused::class, 'covered');

it('refuses a request with no signature at all', function (): void {
    $request = ($this->signed)();
    $headers = $request->headers;
    unset($headers['signature']);

    app(Verifier::class)->verify(new PublicRequest('POST', '/ap/inbox', [], [], $headers, $this->body, '203.0.113.9'));
})->throws(SignatureRefused::class, 'signature');

it('fetches a changed key exactly once and then verifies', function (): void {
    // The cache holds an old key; the remote server now answers the current one.
    $this->owners->cache(new KeyOwner(FEDI_ALICE.'#main-key', FEDI_ALICE, Actors::keys('site')['public']));

    $sender = app(Verifier::class)->verify(($this->signed)());

    expect($sender->actorUri)->toBe(FEDI_ALICE)->and($this->owners->fetches)->toBe(1);
});

it('fetches a key again at most once in five minutes', function (): void {
    $wrong = new KeyOwner(FEDI_ALICE.'#main-key', FEDI_ALICE, Actors::keys('site')['public']);
    $this->owners->cache($wrong)->serve($wrong);

    foreach ([1, 2] as $try) {
        try {
            app(Verifier::class)->verify(($this->signed)());
        } catch (SignatureRefused) {
            // Refused both times: the key never matches.
        }
    }

    expect($this->owners->fetches)->toBe(1);
});
