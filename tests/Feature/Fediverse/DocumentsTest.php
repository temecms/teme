<?php

declare(strict_types=1);

use App\Domain\Addresses\Paths;
use App\Domain\Fediverse\Models\Activity;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\Follower;
use App\Domain\People\Models\Person;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Fediverse\Actors;
use Tests\Fixtures\Fediverse\Requests;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->blog = Actors::blog();
    // A new registry, as the next request gets, now that the site has an account.
    app()->forgetScopedInstances();
    $this->host = (string) parse_url(url('/'), PHP_URL_HOST);
});

it('answers the blog actor at /ap/blog as activity JSON that no cache keeps', function (): void {
    $response = Requests::get('/ap/blog')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/activity+json')
        ->assertJson([
            'id' => url('/ap/blog'),
            'type' => 'Group',
            'preferredUsername' => 'blog',
            'inbox' => url('/ap/blog/inbox'),
            'outbox' => url('/ap/blog/outbox'),
            'endpoints' => ['sharedInbox' => url('/ap/inbox')],
            'publicKey' => ['id' => url('/ap/blog').'#main-key', 'owner' => url('/ap/blog'), 'publicKeyPem' => Actors::keys()['public']],
        ]);

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->not->toContain('PRIVATE KEY');
});

it('answers the same actor for the ld+json Accept', function (): void {
    Requests::get('/ap/blog', Requests::LD)->assertOk()->assertJson(['id' => url('/ap/blog')]);
});

it('answers 404 at an /ap/ address the site has no actor for, and for a disabled actor', function (): void {
    Requests::get('/ap/people/01J00000000000000000000000')->assertNotFound();

    $this->blog->update(['enabled' => false]);
    Requests::get('/ap/blog')->assertNotFound();
});

it('answers WebFinger for acct:name@host with the actor’s address and page', function (): void {
    Requests::get('/.well-known/webfinger?resource=acct:blog@'.$this->host, 'application/jrd+json')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/jrd+json')
        ->assertJson([
            'subject' => 'acct:blog@'.$this->host,
            'aliases' => [url('/ap/blog')],
            'links' => [['rel' => 'self', 'type' => 'application/activity+json', 'href' => url('/ap/blog')]],
        ]);
});

it('answers WebFinger whatever the case of the name, and for the actor’s address', function (): void {
    Requests::get('/.well-known/webfinger?resource=acct:BLOG@'.$this->host, 'application/jrd+json')->assertOk();
    Requests::get('/.well-known/webfinger?resource='.urlencode(url('/ap/blog')), 'application/jrd+json')->assertOk()->assertJsonPath('subject', 'acct:blog@'.$this->host);
});

it('answers 404 to WebFinger for another host or an unknown name', function (): void {
    Requests::get('/.well-known/webfinger?resource=acct:blog@elsewhere.example', 'application/jrd+json')->assertNotFound();
    Requests::get('/.well-known/webfinger?resource=acct:nobody@'.$this->host, 'application/jrd+json')->assertNotFound();
});

it('answers NodeInfo’s link and document', function (): void {
    Requests::get('/.well-known/nodeinfo', 'application/json')
        ->assertOk()
        ->assertJsonPath('links.0.href', url('/nodeinfo/2.1'));

    Requests::get('/nodeinfo/2.1', 'application/json')
        ->assertOk()
        ->assertJsonPath('software.name', 'teme')
        ->assertJsonPath('protocols', ['activitypub'])
        ->assertJsonPath('usage.users.total', 1);
});

it('shows the latest twenty activities in the outbox, newest first', function (): void {
    foreach (range(1, 22) as $n) {
        Activity::query()->create([
            'local_actor_id' => $this->blog->id, 'type' => 'Create', 'object_uri' => url('/ap/objects/'.$n),
            'payload' => ['id' => url('/ap/activities/'.$n), 'type' => 'Create'], 'created_at' => now()->addSeconds($n),
        ]);
    }

    $outbox = Requests::get('/ap/blog/outbox')->assertOk()->json();

    expect($outbox['type'])->toBe('OrderedCollection')
        ->and($outbox['totalItems'])->toBe(22)
        ->and($outbox['orderedItems'])->toHaveCount(20)
        ->and($outbox['orderedItems'][0]['id'])->toBe(url('/ap/activities/22'));
});

it('shows only how many follow, and an empty following', function (): void {
    $remote = Actors::remote();
    Follower::query()->create(['local_actor_id' => $this->blog->id, 'remote_actor_id' => $remote->id, 'since' => now()]);

    $followers = Requests::get('/ap/blog/followers')->assertOk()->json();
    $following = Requests::get('/ap/blog/following')->assertOk()->json();

    expect($followers['totalItems'])->toBe(1)
        ->and($followers)->not->toHaveKey('orderedItems')
        ->and($following['totalItems'])->toBe(0)
        ->and($following['orderedItems'])->toBe([]);
});

it('answers an imported actor at its WordPress address, by its Accept', function (): void {
    $person = Person::query()->create(['name' => 'Ada', 'slug' => 'ada']);
    Actors::local(FediverseActor::PERSON, 'ada', Requests::url('/?author=3'), personId: $person->id);
    Actors::local(FediverseActor::BLOG, 'site', Requests::url('/?author=0'), aliases: [Requests::url('/@site')]);

    Requests::get('/?author=3')->assertOk()->assertJson(['id' => Requests::url('/?author=3'), 'type' => 'Person', 'name' => 'Ada']);
    Requests::get('/@site')->assertOk()->assertJson(['id' => Requests::url('/?author=0')]);
});

it('answers JSON at /?author=0 with the activity Accept and the home page without it, even with the home page stored', function (): void {
    $folder = cacheOn();
    Actors::local(FediverseActor::BLOG, 'site', Requests::url('/?author=0'));

    addrGet('/')->assertOk();
    addrGet('/')->assertOk()->assertHeader('X-Teme-Cache', 'HIT');

    $json = Requests::get('/?author=0')->assertOk()->assertHeader('Content-Type', 'application/activity+json');
    $page = Requests::get('/?author=0', 'text/html')->assertOk();

    expect($json->json('id'))->toBe(Requests::url('/?author=0'))
        ->and((string) $page->headers->get('Content-Type'))->toContain('text/html');

    cacheOff($folder);
});

it('sends a browser at an old author address the site has no actor for to the home page', function (): void {
    Requests::get('/?author=7', 'text/html')->assertRedirect(url('/'))->assertStatus(301);
});

it('leaves the home page’s old fediverse addresses alone while the site has no account', function (): void {
    $this->blog->delete();
    app()->forgetScopedInstances();

    Requests::get('/?author=7', 'text/html')->assertOk();
    expect((string) Requests::get('/?author=0')->headers->get('Content-Type'))->toContain('text/html');
});

it('keeps /ap/ and /wp-json/ from every item and address setting', function (): void {
    expect(Paths::taken('ap'))->toBeTrue()
        ->and(Paths::taken('wp-json'))->toBeTrue();
});
