<?php

declare(strict_types=1);

use App\Domain\Fediverse\Models\Activity;
use App\Domain\Fediverse\Models\BlockedHost;
use App\Domain\Fediverse\Models\Delivery;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Models\Follower;
use App\Domain\Fediverse\Operations\AcceptFollow;
use App\Domain\Fediverse\Operations\Input\AcceptFollowInput;
use App\Domain\Fediverse\Publishing\ContentActivities;
use App\Domain\Fediverse\Publishing\Deliveries;
use App\Domain\Fediverse\Publishing\DeliveryOutcome;
use App\Domain\Fediverse\Publishing\DeliveryPost;
use App\Domain\Fediverse\Publishing\DeliverySchedule;
use App\Domain\Fediverse\Publishing\Jobs\DeliverActivity;
use App\Domain\Fediverse\Publishing\Operations\Input\RecordDeliveryInput;
use App\Domain\Fediverse\Publishing\Operations\RecordDelivery;
use App\Domain\Fediverse\Signatures\VerifiedSender;
use App\Domain\Hooks\HookDispatcher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fixtures\Fediverse\Actors;
use Tests\Fixtures\Fediverse\Inbox;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    Queue::fake();
    importResolve(['remote.example' => ['93.184.216.34'], 'solo.example' => ['93.184.216.36'], 'inside.example' => ['10.0.0.5']]);
    Http::preventStrayRequests();
    config(['teme.fediverse.deliver_outside_production' => true]);
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('editor');
    $this->blog = Actors::blog();
    Inbox::switch(true);
    ContentActivities::listen(app(HookDispatcher::class));
    $this->alice = Actors::remote('https://solo.example/users/alice', ['shared_inbox_url' => null]);
    Follower::query()->insert(['local_actor_id' => $this->blog->id, 'remote_actor_id' => $this->alice->id, 'since' => now()]);
});

/**
 * Publishes an item, and returns the one delivery it queued.
 */
function fpubDelivery(User $author): Delivery
{
    $item = addrPublished($author, 'Harbour news');
    $uri = FediverseObject::query()->where('content_id', $item->id)->value('uri');
    $activity = Activity::query()->where('object_uri', $uri)->where('type', 'Create')->sole();

    return Delivery::query()->where('activity_id', $activity->id)->firstOrFail();
}

/**
 * Runs the delivery's job as a worker would.
 */
function fpubRun(Delivery $delivery): void
{
    (new DeliverActivity($delivery->id, $delivery->host))->handle(app(DeliveryPost::class), app(Deliveries::class));
}

it('queues a job for each delivery once the change commits', function (): void {
    $delivery = fpubDelivery($this->author);

    Queue::assertPushed(DeliverActivity::class, fn (DeliverActivity $job): bool => $job->deliveryId === $delivery->id && $job->host === 'solo.example');
});

it('posts the activity signed by its actor, and marks it sent on a 2xx', function (): void {
    Http::fake(['https://solo.example/users/alice/inbox' => Http::response('', 202)]);
    $delivery = fpubDelivery($this->author);

    fpubRun($delivery);

    $delivery->refresh();
    expect([$delivery->state, $delivery->attempts, $delivery->last_status])->toBe(['sent', 1, 202])
        ->and($delivery->sent_at)->not->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->header('Signature')[0] ?? '', 'keyId="'.$this->blog->uri.'#main-key"')
        && str_starts_with($request->header('Digest')[0] ?? '', 'SHA-256=')
        && $request['type'] === 'Create'
        && $request['actor'] === $this->blog->uri);
});

it('ends the follow of an account whose inbox answers 410', function (): void {
    Http::fake(['https://solo.example/users/alice/inbox' => Http::response('', 410)]);
    $delivery = fpubDelivery($this->author);

    fpubRun($delivery);

    expect($delivery->refresh()->state)->toBe('failed')
        ->and($this->alice->refresh()->gone_at)->not->toBeNull()
        ->and(Follower::query()->count())->toBe(0);
    Queue::assertPushed(DeliverActivity::class, 1);
});

it('fails for good on another 4xx, with no retry', function (): void {
    Http::fake(['https://solo.example/users/alice/inbox' => Http::response('', 401)]);
    $delivery = fpubDelivery($this->author);

    fpubRun($delivery);

    expect([$delivery->refresh()->state, $delivery->attempts, $delivery->last_status])->toBe(['failed', 1, 401]);
    Queue::assertPushed(DeliverActivity::class, 1);
});

it('retries a 5xx or a timeout on the schedule, then fails', function (string $failure): void {
    Http::fake(['https://solo.example/users/alice/inbox' => $failure === 'timeout'
        ? fn () => throw new ConnectionException('Timed out.')
        : Http::response('', 503)]);
    $delivery = fpubDelivery($this->author);
    $waits = [];

    for ($try = 1; $try <= DeliverySchedule::tries(); $try++) {
        fpubRun($delivery);
        $delivery->refresh();

        if ($delivery->state === 'queued') {
            $waits[] = (int) now()->diffInSeconds($delivery->next_attempt_at);
            $this->travelTo($delivery->next_attempt_at);
        }
    }

    expect($waits)->toBe(DeliverySchedule::DELAYS)
        ->and([$delivery->state, $delivery->attempts])->toBe(['failed', 9])
        ->and($this->alice->refresh()->failing_since)->not->toBeNull();
    Queue::assertPushed(DeliverActivity::class, 9);
})->with(['5xx', 'timeout']);

it('skips a server failing for 7 days, tries it once a day, and resumes when it answers', function (): void {
    $this->alice->forceFill(['failing_since' => now()->subDays(8)])->save();
    Http::fake(['https://solo.example/users/alice/inbox' => Http::sequence()->push('', 503)->push('', 202)->push('', 202)]);

    $probe = fpubDelivery($this->author);
    fpubRun($probe);
    $skipped = fpubDelivery($this->author);
    fpubRun($skipped);

    expect($probe->refresh()->state)->toBe('queued')
        ->and([$skipped->refresh()->state, $skipped->attempts])->toBe(['skipped', 0]);
    Http::assertSentCount(1);

    $this->travel(1)->days();
    fpubRun($probe->refresh());
    $after = fpubDelivery($this->author);
    fpubRun($after);

    expect($probe->refresh()->state)->toBe('sent')
        ->and($this->alice->refresh()->failing_since)->toBeNull()
        ->and($after->refresh()->state)->toBe('sent');
});

it('sends nothing from a copy of the site at another address, or outside production unless allowed', function (string $case): void {
    Http::fake();

    if ($case === 'host') {
        FediverseActor::query()->whereKey($this->blog->id)->update(['uri' => 'https://live.example/ap/blog']);
    } else {
        config(['teme.fediverse.deliver_outside_production' => false]);
    }

    $delivery = fpubDelivery($this->author);
    fpubRun($delivery);

    expect([$delivery->refresh()->state, $delivery->attempts])->toBe(['queued', 0]);
    Http::assertNothingSent();

    if ($case === 'environment') {
        Queue::assertNotPushed(DeliverActivity::class);
    }
})->with(['host', 'environment']);

it('delivers nothing to a blocked server, or to an inbox at a private address', function (): void {
    Http::fake();
    $delivery = fpubDelivery($this->author);
    BlockedHost::query()->create(['host' => 'solo.example']);
    fpubRun($delivery);

    $inside = Actors::remote('https://inside.example/users/eve', ['shared_inbox_url' => null]);
    Follower::query()->insert(['local_actor_id' => $this->blog->id, 'remote_actor_id' => $inside->id, 'since' => now()]);
    fpubDelivery($this->author);
    $private = Delivery::query()->where('host', 'inside.example')->sole();
    fpubRun($private);

    expect($delivery->refresh()->state)->toBe('skipped')
        ->and($private->refresh()->state)->toBe('failed');
    Http::assertNothingSent();
});

it('lets at most four deliveries reach one server at a time', function (): void {
    Http::fake();
    $keys = collect(range(1, 40))->map(fn (): string => (new DeliverActivity(strtolower((string) Str::ulid()), 'solo.example'))->middleware()[0]->getLockKey(null))->unique();
    $delivery = fpubDelivery($this->author);
    $job = new DeliverActivity($delivery->id, $delivery->host);
    $lock = Cache::lock($job->middleware()[0]->getLockKey($job), 60);
    $lock->get();
    $released = new class
    {
        public ?int $after = null;

        public function release(int $seconds): void
        {
            $this->after = $seconds;
        }
    };

    $job->middleware()[0]->handle($released, fn () => fpubRun($delivery));

    expect($keys)->toHaveCount(DeliverActivity::PER_HOST)
        ->and($released->after)->toBe(5)
        ->and($delivery->refresh()->state)->toBe('queued');
    Http::assertNothingSent();
    $lock->release();
});

it('records a delivery only for the site’s own delivery work, writing nothing otherwise', function (): void {
    $delivery = fpubDelivery($this->author);
    $admin = User::factory()->create()->assignRole('administrator');

    foreach ([null, (string) $admin->id] as $actorId) {
        expect(fn () => app(RecordDelivery::class)->execute(new RecordDeliveryInput($delivery->id, new DeliveryOutcome(DeliveryOutcome::SENT, 202), $actorId)))
            ->toThrow(AuthorizationException::class);
    }

    expect([$delivery->refresh()->state, $delivery->attempts])->toBe(['queued', 0]);
});

it('sends the Accept of a Follow', function (): void {
    $bob = Actors::remote('https://remote.example/users/bob');

    app(AcceptFollow::class)->execute(new AcceptFollowInput($this->blog->id, $bob->id, Inbox::follow($this->blog->uri, $bob->uri, $bob->uri.'#follows/1'), new VerifiedSender($bob->uri.'#main-key', $bob->uri)));

    $accept = Delivery::query()->where('host', 'remote.example')->sole();
    Queue::assertPushed(DeliverActivity::class, fn (DeliverActivity $job): bool => $job->deliveryId === $accept->id);
});
