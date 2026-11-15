<?php

declare(strict_types=1);

use App\Domain\Fediverse\Jobs\ProcessInboxActivity;
use App\Domain\Fediverse\Models\Activity;
use App\Domain\Fediverse\Models\Delivery;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Models\Follower;
use App\Domain\Fediverse\Publishing\ContentActivities;
use App\Domain\Fediverse\Publishing\Deliveries;
use App\Domain\Fediverse\Publishing\DeliveryPost;
use App\Domain\Fediverse\Publishing\Jobs\DeliverActivity;
use App\Domain\Fediverse\Queries\FediverseDocuments;
use App\Domain\Fediverse\Remote\RemoteFetcher;
use App\Domain\Fediverse\Sockets\Operations\Input\SendPluginActivityInput;
use App\Domain\Fediverse\Sockets\Operations\SendPluginActivity;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\People\Models\Person;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Fediverse\ActivityReceived;
use Teme\Contracts\Fediverse\ActivitySent;
use Teme\Contracts\Fediverse\CollectObjectBuilders;
use Teme\Contracts\Fediverse\ObjectBuilder;
use Teme\Contracts\Fediverse\ObjectBuilt;
use Teme\Contracts\Fediverse\SendRefused;
use Teme\Contracts\Hooks\HookRefused;
use Tests\Fixtures\Fediverse\Actors;
use Tests\Fixtures\Fediverse\Inbox;
use Tests\Fixtures\Fediverse\Requests;
use Tests\Fixtures\Plugins\Fediverse\FediversePlugin;
use Tests\Fixtures\Plugins\Fediverse\LikeHandler;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    importResolve(['solo.example' => ['93.184.216.36']]);
    config(['teme.fediverse.deliver_outside_production' => true]);
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->author = User::factory()->create()->assignRole('editor');
    $this->blog = Actors::blog();
    Inbox::switch(true);
    ContentActivities::listen(app(HookDispatcher::class));
    $this->alice = Actors::remote('https://solo.example/users/alice', ['shared_inbox_url' => null]);
    Follower::query()->insert(['local_actor_id' => $this->blog->id, 'remote_actor_id' => $this->alice->id, 'since' => now()]);
});

/**
 * Enables and starts the fixture plugin, granted what its manifest asks
 * for by an administrator (its consent's author), as the operator does.
 */
function fsockPlugin(): FediversePlugin
{
    $previous = app(Elevation::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $admin = User::query()->whereHas('roles', fn ($roles) => $roles->where('name', 'administrator'))->firstOrFail();

    PluginFixtures::use('fediverse');
    PluginFixtures::installed(['tests/fediverse' => 'fediverse']);
    $manifest = app(PluginRegistry::class)->find('tests/fediverse')?->manifest;

    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/fediverse', '1.0.0', $manifest?->capabilities() ?? [], [], $manifest?->hooks() ?? [], (string) $admin->id));
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    app(PluginLoader::class)->load();
    app()->instance(Elevation::class, $previous);

    $plugin = app(LoadedPlugins::class)->running()['tests/fediverse'] ?? null;
    FediversePlugin::$heard = [];
    LikeHandler::$handled = [];

    return $plugin instanceof FediversePlugin ? $plugin : throw new RuntimeException('The fediverse plugin did not start.');
}

/**
 * Runs an activity from the account through the inbox's job, once its signature is verified.
 *
 * @param  array<string, mixed>  $activity
 */
function fsockRun(array $activity, string $from): void
{
    (new ProcessInboxActivity($activity, $from.'#main-key', $from))->handle(app(FediverseDocuments::class), app(RemoteFetcher::class));
}

it('tells a plugin granted fediverse.read of a Like, and runs its handler for Like but never for Follow', function (): void {
    fsockPlugin();
    $like = ['id' => $this->alice->uri.'#likes/1', 'type' => 'Like', 'actor' => $this->alice->uri, 'object' => url('/ap/objects/x')];

    fsockRun($like, $this->alice->uri);
    fsockRun(Inbox::follow($this->blog->uri, $this->alice->uri, $this->alice->uri.'#follows/2'), $this->alice->uri);

    expect(FediversePlugin::$heard)->toBe(['Like from '.$this->alice->uri, 'Follow from '.$this->alice->uri])
        ->and(LikeHandler::$handled)->toBe(['Like from '.$this->alice->uri])
        ->and(Follower::query()->count())->toBe(1);
});

it('tells nothing to a plugin without fediverse.read', function (): void {
    $heard = [];
    $quiet = app(HookDispatcher::class)->subscriber('acme/quiet', ['content.read']);

    expect(fn () => $quiet->on(ActivityReceived::class, function () use (&$heard): void {
        $heard[] = 'heard';
    }))->toThrow(HookRefused::class);

    fsockRun(['id' => $this->alice->uri.'#likes/1', 'type' => 'Like', 'actor' => $this->alice->uri, 'object' => url('/ap/objects/x')], $this->alice->uri);

    expect($heard)->toBe([]);
});

it('keeps an account’s address and key when a plugin changes its document', function (): void {
    fsockPlugin();

    $document = Requests::get('/ap/blog')->assertOk()->json();

    expect($document['id'])->toBe($this->blog->uri)
        ->and($document['publicKey'])->toBe(['id' => $this->blog->keyId(), 'owner' => $this->blog->uri, 'publicKeyPem' => $this->blog->public_key_pem])
        ->and($document['summary'])->toBe('Changed by a plugin.');
});

it('builds and changes an item’s object through plugins, keeping its address and who sent it to whom', function (): void {
    $hooks = app(HookDispatcher::class)->subscriber('acme/objects', ['fediverse.read']);
    $hooks->on(CollectObjectBuilders::class, fn (): array => [new class implements ObjectBuilder
    {
        public function contentTypes(): array
        {
            return ['post'];
        }

        public function build(ItemRef $item, array $object): array
        {
            return [...$object, 'type' => 'Event', 'id' => 'https://elsewhere.example/1', 'name' => $item->title.' (event)'];
        }
    }]);
    $hooks->on(ObjectBuilt::class, fn (array $object): array => [...$object, 'tag' => [['type' => 'Hashtag', 'name' => '#harbour']], 'cc' => [], 'attributedTo' => 'https://elsewhere.example/me']);

    $item = addrPublished($this->author, 'Harbour news');
    $uri = FediverseObject::query()->where('content_id', $item->id)->value('uri');
    $object = Activity::query()->where('type', 'Create')->sole()->payload['object'];

    expect([$object['id'], $object['type'], $object['name'], $object['attributedTo'], $object['cc']])->toBe([$uri, 'Event', 'Harbour news (event)', $this->blog->uri, [$this->blog->urls['followers']]])
        ->and($object['tag'])->toEqual([['type' => 'Hashtag', 'name' => '#harbour']]);
});

it('tells a plugin granted fediverse.read what the site sent', function (): void {
    $sent = [];
    app(HookDispatcher::class)->subscriber('acme/sent', ['fediverse.read'])->on(ActivitySent::class, function (ActivitySent $hook) use (&$sent): void {
        $sent[] = $hook->type.' by '.$hook->actorUri;
    });

    addrPublished($this->author, 'Harbour news');

    expect($sent)->toBe(['Create by '.$this->blog->uri]);
});

it('sends through FediverseSender signed by the site, the plugin never holding a key', function (): void {
    $sender = fsockPlugin()->sender;
    Http::fake(['https://solo.example/users/alice/inbox' => Http::response('', 202)]);

    $id = $sender->send('Like', 'https://solo.example/statuses/9');

    $activity = Activity::query()->sole();
    $delivery = Delivery::query()->sole();
    Queue::assertPushed(DeliverActivity::class, fn (DeliverActivity $job): bool => $job->deliveryId === $delivery->id);
    (new DeliverActivity($delivery->id, $delivery->host))->handle(app(DeliveryPost::class), app(Deliveries::class));

    expect($id)->toBe($activity->payload['id'])->toStartWith($this->blog->uri.'#activities/')
        ->and([$activity->type, $activity->local_actor_id, $activity->payload['object']])->toBe(['Like', $this->blog->id, 'https://solo.example/statuses/9'])
        ->and($delivery->refresh()->state)->toBe('sent')
        ->and(array_map(fn (ReflectionProperty $property): string => $property->getName(), (new ReflectionClass($sender))->getProperties()))->toBe(['plugin'])
        ->and(print_r($sender, true))->not->toContain('PRIVATE KEY');
    Http::assertSent(fn (Request $request): bool => str_contains($request->header('Signature')[0] ?? '', 'keyId="'.$this->blog->keyId().'"') && $request['type'] === 'Like');
});

it('refuses to send a type the site sends itself, or about an object the site owns', function (): void {
    $sender = fsockPlugin()->sender;
    $item = addrPublished($this->author, 'Harbour news');
    $uri = FediverseObject::query()->where('content_id', $item->id)->value('uri');
    $before = [Activity::query()->count(), Delivery::query()->count()];

    expect(fn () => $sender->send('Follow', 'https://solo.example/users/alice'))->toThrow(SendRefused::class)
        ->and(fn () => $sender->send('Announce', ['id' => $uri, 'type' => 'Article']))->toThrow(SendRefused::class)
        ->and(fn () => $sender->send('Like', 'https://solo.example/statuses/9', 'https://elsewhere.example/actor'))->toThrow(SendRefused::class)
        ->and([Activity::query()->count(), Delivery::query()->count()])->toBe($before);
});

it('tells a plugin who follows the site’s accounts', function (): void {
    $followers = fsockPlugin()->followers;

    expect($followers->actors())->toBe([$this->blog->uri])
        ->and($followers->count($this->blog->uri))->toBe(1)
        ->and($followers->followers($this->blog->uri))->toBe([$this->alice->uri])
        ->and($followers->count('https://elsewhere.example/actor'))->toBe(0);
});

it('sends for the person who started the work: one who manages the fediverse, or an author as their own account', function (): void {
    $sender = fsockPlugin()->sender;
    $person = Person::query()->create(['name' => 'Ada Writer', 'slug' => 'ada-writer', 'user_id' => $this->author->id]);
    $ada = Actors::local(FediverseActor::PERSON, 'ada', url('/ap/people/ada'), personId: $person->id);

    // Work the server starts acts for the administrator who enabled the plugin.
    $sender->send('Like', 'https://solo.example/statuses/1');

    $this->actingAs($this->author);
    $sender->send('Like', 'https://solo.example/statuses/2', $ada->uri);

    expect(fn () => $sender->send('Like', 'https://solo.example/statuses/3'))->toThrow(SendRefused::class)
        ->and(Activity::query()->orderBy('created_at')->orderBy('id')->get()->map(fn (Activity $activity): string => $activity->object_uri.' by '.$activity->local_actor_id)->all())
        ->toBe(['https://solo.example/statuses/1 by '.$this->blog->id, 'https://solo.example/statuses/2 by '.$ada->id]);
});

it('sends nothing for a visitor who is not signed in', function (): void {
    $sender = fsockPlugin()->sender;

    PluginFixtures::serving('/fediverse/ping', fn () => expect(fn () => $sender->send('Like', 'https://solo.example/statuses/9'))->toThrow(SendRefused::class));

    expect(Activity::query()->count())->toBe(0);
});

it('sends only for a plugin granted fediverse.send, for a person allowed to, writing nothing otherwise', function (): void {
    fsockPlugin();

    foreach ([
        new SendPluginActivityInput('tests/other', 'Like', 'https://solo.example/statuses/9', actorId: (string) $this->admin->id),
        new SendPluginActivityInput('tests/fediverse', 'Like', 'https://solo.example/statuses/9'),
        new SendPluginActivityInput('tests/fediverse', 'Like', 'https://solo.example/statuses/9', actorId: (string) $this->author->id),
    ] as $input) {
        expect(fn () => app(SendPluginActivity::class)->execute($input))->toThrow(AuthorizationException::class);
    }

    expect([Activity::query()->count(), Delivery::query()->count()])->toBe([0, 0]);
});
