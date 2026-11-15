<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Fediverse\Models\Activity;
use App\Domain\Fediverse\Models\BlockedHost;
use App\Domain\Fediverse\Models\Delivery;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Models\Follower;
use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Fediverse\Publishing\ContentActivities;
use App\Domain\Fediverse\Publishing\Operations\Input\QueueActivityInput;
use App\Domain\Fediverse\Publishing\Operations\QueueActivity;
use App\Domain\Fediverse\Publishing\SiteWork;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Import\ImportMode;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Support\Security\Operator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Content\ContentTrashed;
use Teme\Contracts\Fediverse\ActivitySent;
use Teme\Contracts\Fediverse\ObjectBuilt;
use Tests\Fixtures\Fediverse\Actors;
use Tests\Fixtures\Fediverse\Inbox;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('editor');
    $this->blog = Actors::blog();
    Inbox::switch(true);
    ContentActivities::listen(app(HookDispatcher::class));

    // Two followers on one server, which has a shared inbox, and one on a server without.
    foreach (['https://remote.example/users/alice', 'https://remote.example/users/bob'] as $uri) {
        fpubFollow($this->blog, Actors::remote($uri));
    }

    fpubFollow($this->blog, Actors::remote('https://solo.example/users/carol', ['shared_inbox_url' => null]));
});

/**
 * Makes the remote account a follower of the site's actor.
 */
function fpubFollow(FediverseActor $local, RemoteActor $remote): void
{
    Follower::query()->insert(['local_actor_id' => $local->id, 'remote_actor_id' => $remote->id, 'follow_uri' => $remote->uri.'#follows/1', 'since' => now()]);
}

/**
 * The activities stored, oldest first, as type => object address.
 *
 * @return list<array{0: string, 1: string}>
 */
function fpubActivities(): array
{
    return Activity::query()->orderBy('created_at')->orderBy('id')->get()->map(fn (Activity $activity): array => [$activity->type, $activity->object_uri])->all();
}

it('sends a Create to each distinct inbox when an item is first published, and an Update after', function (): void {
    $item = addrPublished($this->author, 'Harbour news');
    $object = FediverseObject::query()->where('content_id', $item->id)->sole();

    expect($object->kind)->toBe('post')
        ->and($object->uri)->toStartWith(url('/ap/objects/'))
        ->and(fpubActivities())->toBe([['Create', $object->uri]])
        ->and(Delivery::query()->orderBy('inbox_url')->pluck('inbox_url')->all())->toBe(['https://remote.example/inbox', 'https://solo.example/users/carol/inbox'])
        ->and(Delivery::query()->pluck('state')->unique()->all())->toBe(['queued']);

    $create = Activity::query()->sole();
    expect($create->local_actor_id)->toBe($this->blog->id)
        ->and($create->payload['actor'])->toBe($this->blog->uri)
        ->and($create->payload['object']['id'])->toBe($object->uri);

    docSave($item, $this->author, docWith(docText('The ferry is late.')));
    listsPublish($item, $this->author);

    expect(fpubActivities())->toBe([['Create', $object->uri], ['Update', $object->uri]])
        ->and(Delivery::query()->count())->toBe(4);
});

it('sends a Delete when an item is unpublished, trashed or made private, once', function (string $how): void {
    $item = addrPublished($this->author, 'Harbour news');
    $uri = FediverseObject::query()->where('content_id', $item->id)->value('uri');
    $input = new ContentActionInput($item->id, (string) $this->author->id);

    match ($how) {
        'unpublished' => app(UnpublishContent::class)->execute($input),
        'trashed' => app(TrashContent::class)->execute($input),
        'private' => app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($item->id, 'private', null, (string) $this->author->id)),
    };

    // Trashing what is already gone sends nothing more.
    app(TrashContent::class)->execute($input);

    $delete = Activity::query()->where('type', 'Delete')->sole();
    expect(fpubActivities())->toBe([['Create', $uri], ['Delete', $uri]])
        ->and($delete->payload['object'])->toBe(['id' => $uri, 'type' => 'Tombstone'])
        ->and(FediverseObject::query()->whereKey($uri)->value('deleted_at'))->not->toBeNull()
        ->and(Delivery::query()->where('activity_id', $delete->id)->count())->toBe(2);
})->with(['unpublished', 'trashed', 'private']);

it('sends a Create again when an unpublished item is published again', function (): void {
    $item = addrPublished($this->author, 'Harbour news');
    $uri = FediverseObject::query()->where('content_id', $item->id)->value('uri');

    app(UnpublishContent::class)->execute(new ContentActionInput($item->id, (string) $this->author->id));
    listsPublish($item, $this->author);

    expect(fpubActivities())->toBe([['Create', $uri], ['Delete', $uri], ['Create', $uri]])
        ->and(FediverseObject::query()->whereKey($uri)->value('deleted_at'))->toBeNull();
});

it('sends nothing for a type that is not federated, or while the fediverse is paused', function (): void {
    addrPublished($this->author, 'About us', 'page');
    Inbox::switch(false);
    $post = addrPublished($this->author, 'Harbour news');
    app(TrashContent::class)->execute(new ContentActionInput($post->id, (string) $this->author->id));

    expect(Activity::query()->count())->toBe(0)
        ->and(Delivery::query()->count())->toBe(0)
        ->and(FediverseObject::query()->count())->toBe(0);
});

it('sends nothing for what an import run publishes', function (): void {
    $source = ImportSource::query()->create([
        'address' => 'https://old.example', 'api_root' => 'https://old.example/wp-json/', 'user_name' => 'ada',
        'credential' => 'abcd EFGH 1234 ijkl MNOP 5678', 'plugin_version' => '0.2.0', 'api_version' => 1, 'probed_at' => now(),
    ]);
    $run = ImportRun::query()->create([
        'source_id' => $source->id, 'kind' => ImportRunKind::Full, 'state' => ImportRunState::Running, 'phase' => 'users',
        'phase_token' => strtolower((string) Str::ulid()), 'high_water_at' => now(), 'started_at' => now(),
    ]);

    app(ImportMode::class)->during($run->id, fn (): Content => addrPublished($this->author, 'Harbour news'));

    expect(Activity::query()->count())->toBe(0)->and(FediverseObject::query()->count())->toBe(0);
});

it('delivers to no blocked server and no follower that is gone', function (): void {
    BlockedHost::query()->create(['host' => 'solo.example']);
    Actors::remote('https://gone.example/users/dan')->forceFill(['gone_at' => now()])->save();
    fpubFollow($this->blog, RemoteActor::query()->where('host', 'gone.example')->sole());

    addrPublished($this->author, 'Harbour news');

    expect(Delivery::query()->pluck('inbox_url')->all())->toBe(['https://remote.example/inbox']);
});

it('queues nothing for a person without the fediverse permission, writing nothing', function (): void {
    $item = addrPublished($this->author, 'Harbour news');
    $before = [Activity::query()->count(), Delivery::query()->count(), FediverseObject::query()->count()];
    $admin = User::factory()->create()->assignRole('administrator');

    expect(fn () => app(QueueActivity::class)->execute(new QueueActivityInput($item->id, QueueActivityInput::PUBLISHED, (string) $this->author->id)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(QueueActivity::class)->execute(new QueueActivityInput($item->id, QueueActivityInput::PUBLISHED)))->toThrow(AuthorizationException::class)
        ->and([Activity::query()->count(), Delivery::query()->count(), FediverseObject::query()->count()])->toBe($before);

    app(QueueActivity::class)->execute(new QueueActivityInput($item->id, QueueActivityInput::PUBLISHED, (string) $admin->id));

    expect(fpubActivities()[1][0])->toBe('Update');
});

it('listens to content only once the fediverse is on, from the next queued job', function (): void {
    $dispatcher = new HookDispatcher;
    Inbox::switch(false);

    ContentActivities::listen($dispatcher);
    $off = $dispatcher->wants(ContentPublished::class);

    Inbox::switch(true);
    app()->instance(HookDispatcher::class, $dispatcher);
    event(new JobProcessing('database', Mockery::mock(Job::class)->shouldIgnoreMissing()));

    expect($off)->toBeFalse()
        ->and($dispatcher->wants(ContentPublished::class))->toBeTrue()
        ->and($dispatcher->wants(ContentTrashed::class))->toBeTrue();
});

it('runs plugins’ object and sent listeners without the operator’s power', function (): void {
    $seen = [];
    $hooks = app(HookDispatcher::class)->subscriber('acme/watch', ['fediverse.read']);
    $hooks->on(ObjectBuilt::class, function (array $object) use (&$seen): array {
        $seen[] = 'built '.(Operator::isPresent() ? 'as operator' : 'as nobody');

        return $object;
    });
    $hooks->on(ActivitySent::class, function () use (&$seen): void {
        $seen[] = 'sent '.(Operator::isPresent() ? 'as operator' : 'as nobody');
    });

    addrPublished($this->author, 'Harbour news');

    expect($seen)->toBe(['built as nobody', 'sent as nobody'])
        ->and(Operator::isPresent())->toBeFalse();
});

it('refuses a forged token for the site’s own work, writing nothing', function (): void {
    Inbox::switch(false);
    $item = addrPublished($this->author, 'Harbour news');
    Inbox::switch(true);
    $forged = (new ReflectionClass(SiteWork::class))->newInstanceWithoutConstructor();

    expect(fn () => app(QueueActivity::class)->execute(new QueueActivityInput($item->id, QueueActivityInput::PUBLISHED, siteWork: $forged)))->toThrow(AuthorizationException::class)
        ->and([Activity::query()->count(), Delivery::query()->count(), FediverseObject::query()->count()])->toBe([0, 0, 0]);
});

it('issues the site’s own work only to core’s content listener', function (): void {
    expect(fn () => SiteWork::issue())->toThrow(LogicException::class);
});
