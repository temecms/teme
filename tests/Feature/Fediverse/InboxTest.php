<?php

declare(strict_types=1);

use App\Domain\Fediverse\Jobs\ProcessInboxActivity;
use App\Domain\Fediverse\Models\Activity;
use App\Domain\Fediverse\Models\BlockedHost;
use App\Domain\Fediverse\Models\Delivery;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\Follower;
use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Fediverse\Signatures\Signer;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Fediverse\Actors;
use Tests\Fixtures\Fediverse\Inbox;
use Tests\Fixtures\Fediverse\Requests;
use Tests\Fixtures\Fediverse\Rfc9421;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    importResolve(['remote.example' => ['93.184.216.34'], 'blocked.example' => ['93.184.216.36']]);
    Http::preventStrayRequests();
    Http::fake([Inbox::ALICE => Http::response(Actors::remoteDocument(), 200)]);
    $this->blog = Actors::blog();
    Inbox::switch(true);
});

it('refuses a blocked server with 403 before anything else, even an oversized unsigned body', function (): void {
    BlockedHost::query()->create(['host' => 'blocked.example', 'created_at' => now()]);
    Queue::fake();

    Inbox::post('/ap/inbox', [], from: 'https://blocked.example/users/eve', body: str_repeat('a', 300_000))->assertForbidden();
    Inbox::post('/ap/inbox', Inbox::follow(url('/ap/blog'), 'https://sub.blocked.example/users/eve'), from: 'https://sub.blocked.example/users/eve')->assertForbidden();

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('refuses a body over 256 KB with 413 before its signature is read', function (): void {
    Inbox::post('/ap/inbox', [], body: str_repeat('a', 262_145))->assertStatus(413);

    Http::assertNothingSent();
});

it('answers 429 to the 121st request in a minute from one address', function (): void {
    Queue::fake();

    foreach (range(1, 120) as $n) {
        Inbox::post('/ap/inbox', Inbox::follow(url('/ap/blog'), id: Inbox::ALICE.'#follows/'.$n))->assertStatus(202);
    }

    Inbox::post('/ap/inbox', Inbox::follow(url('/ap/blog'), id: Inbox::ALICE.'#follows/121'))->assertStatus(429);
});

it('answers 429 to the 121st request in a minute from one server, whatever the address', function (): void {
    Queue::fake();

    foreach (range(1, 120) as $n) {
        Inbox::post('/ap/inbox', Inbox::follow(url('/ap/blog'), id: Inbox::ALICE.'#follows/'.$n), address: '203.0.113.'.($n % 200 + 1))->assertStatus(202);
    }

    Inbox::post('/ap/inbox', Inbox::follow(url('/ap/blog'), id: Inbox::ALICE.'#follows/121'), address: '198.51.100.7')->assertStatus(429);
});

it('refuses a bad signature with 401 and no detail', function (): void {
    Queue::fake();

    $response = Inbox::post('/ap/inbox', Inbox::follow(url('/ap/blog')), headers: ['Digest' => 'SHA-256='.base64_encode(hash('sha256', 'other', true))])->assertUnauthorized();

    expect($response->getContent())->toBe('');
    Queue::assertNothingPushed();
});

it('refuses with 401 a request signed for another server, replayed here', function (): void {
    $body = json_encode(Inbox::follow(url('/ap/blog')), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $alice = new FediverseActor(['uri' => Inbox::ALICE, 'private_key' => Actors::keys('remote')['private']]);
    $signed = app(Signer::class)->sign('POST', 'https://other.example/ap/inbox', [], $body, $alice);
    $rfc9421 = Rfc9421::sign('POST', 'https://other.example/ap/inbox', ['host' => 'other.example'], $body, Inbox::ALICE.'#main-key');

    Requests::post('/ap/inbox', $body, [...$signed, 'Host' => 'other.example'])->assertStatus(401);
    Requests::post('/ap/inbox', $body, [...$rfc9421, 'Host' => 'other.example'])->assertStatus(401);

    expect(Follower::query()->count())->toBe(0);
});

it('answers 503 with Retry-After while the fediverse is paused', function (): void {
    Inbox::switch(false);
    Queue::fake();

    Inbox::post('/ap/inbox', Inbox::follow(url('/ap/blog')))->assertStatus(503)->assertHeader('Retry-After', '3600');

    Queue::assertNothingPushed();
});

it('takes a Follow at the shared inbox: the follower is kept and an Accept is queued for them', function (): void {
    Inbox::post('/ap/inbox', Inbox::follow(url('/ap/blog')))->assertStatus(202);

    $remote = RemoteActor::query()->where('uri', Inbox::ALICE)->sole();
    $follower = Follower::query()->where('local_actor_id', $this->blog->id)->sole();
    $accept = Activity::query()->where('type', 'Accept')->sole();
    $delivery = Delivery::query()->sole();

    expect($follower->remote_actor_id)->toBe($remote->id)
        ->and($follower->follow_uri)->toBe(Inbox::ALICE.'#follows/1')
        ->and($accept->payload)->toMatchArray(['type' => 'Accept', 'actor' => url('/ap/blog')])
        ->and($accept->payload['object']['id'])->toBe(Inbox::ALICE.'#follows/1')
        ->and([$delivery->activity_id, $delivery->inbox_url, $delivery->host, $delivery->state])->toBe([$accept->id, Inbox::ALICE.'/inbox', 'remote.example', 'queued']);
});

it('takes a Follow signed with RFC 9421, as Mastodon signs', function (): void {
    Inbox::postRfc9421('/ap/inbox', Inbox::follow(url('/ap/blog')))->assertStatus(202);

    expect(Follower::query()->where('local_actor_id', $this->blog->id)->count())->toBe(1);
});

it('refuses a blocked server’s RFC 9421 request with 403 by its key’s host, before anything is fetched', function (): void {
    BlockedHost::query()->create(['host' => 'blocked.example', 'created_at' => now()]);

    Inbox::postRfc9421('/ap/inbox', [], from: 'https://blocked.example/users/eve', body: str_repeat('a', 300_000))->assertForbidden();

    Http::assertNothingSent();
});

it('takes a Follow at the actor’s own inbox', function (): void {
    Inbox::post('/ap/blog/inbox', Inbox::follow(url('/ap/blog')))->assertStatus(202);

    expect(Follower::query()->count())->toBe(1);
});

it('answers 404 at an inbox the site does not have', function (): void {
    Inbox::post('/ap/people/01J00000000000000000000000/inbox', Inbox::follow(url('/ap/blog')))->assertNotFound();
});

it('removes the follower on Undo of the Follow', function (): void {
    Inbox::post('/ap/inbox', Inbox::follow(url('/ap/blog')))->assertStatus(202);

    Inbox::post('/ap/inbox', ['id' => Inbox::ALICE.'#undo/1', 'type' => 'Undo', 'actor' => Inbox::ALICE, 'object' => Inbox::follow(url('/ap/blog'))])->assertStatus(202);

    expect(Follower::query()->count())->toBe(0);
});

it('keeps the follower on Undo of a Follow of an account that isn’t this site’s', function (): void {
    Inbox::post('/ap/inbox', Inbox::follow(url('/ap/blog')))->assertStatus(202);

    Inbox::post('/ap/inbox', ['id' => Inbox::ALICE.'#undo/2', 'type' => 'Undo', 'actor' => Inbox::ALICE, 'object' => Inbox::follow('https://other.example/users/x', id: Inbox::ALICE.'#follows/9')])->assertStatus(202);

    expect(Follower::query()->count())->toBe(1);
});

it('marks a remote account gone and drops its follows when it deletes itself', function (): void {
    Inbox::post('/ap/inbox', Inbox::follow(url('/ap/blog')))->assertStatus(202);

    Inbox::post('/ap/inbox', ['id' => Inbox::ALICE.'#delete', 'type' => 'Delete', 'actor' => Inbox::ALICE, 'object' => Inbox::ALICE])->assertStatus(202);

    expect(Follower::query()->count())->toBe(0)
        ->and(RemoteActor::query()->where('uri', Inbox::ALICE)->value('gone_at'))->not->toBeNull();
});

it('accepts an activity id only once', function (): void {
    Queue::fake();

    Inbox::post('/ap/inbox', Inbox::follow(url('/ap/blog')))->assertStatus(202);
    Inbox::post('/ap/inbox', Inbox::follow(url('/ap/blog')))->assertStatus(202);

    Queue::assertPushed(ProcessInboxActivity::class, 1);
});

it('ignores a Like, keeping nothing', function (): void {
    Inbox::post('/ap/inbox', ['id' => Inbox::ALICE.'#likes/1', 'type' => 'Like', 'actor' => Inbox::ALICE, 'object' => url('/ap/objects/1')])->assertStatus(202);

    expect(Follower::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
});
