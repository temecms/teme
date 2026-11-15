<?php

declare(strict_types=1);

use App\Domain\Fediverse\Models\Activity;
use App\Domain\Fediverse\Models\Delivery;
use App\Domain\Fediverse\Models\Follower;
use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Fediverse\Operations\AcceptFollow;
use App\Domain\Fediverse\Operations\Input\AcceptFollowInput;
use App\Domain\Fediverse\Operations\Input\RecordRemoteActorInput;
use App\Domain\Fediverse\Operations\Input\RemoveFollowerInput;
use App\Domain\Fediverse\Operations\RecordRemoteActor;
use App\Domain\Fediverse\Operations\RemoveFollower;
use App\Domain\Fediverse\Signatures\VerifiedSender;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Fixtures\Fediverse\Actors;
use Tests\Fixtures\Fediverse\Inbox;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->blog = Actors::blog();
    $this->alice = new VerifiedSender(Inbox::ALICE.'#main-key', Inbox::ALICE);
    $this->mallory = new VerifiedSender('https://remote.example/users/mallory#main-key', 'https://remote.example/users/mallory');
    $this->rows = fn (): array => [
        RemoteActor::query()->count(), Follower::query()->count(), Activity::query()->count(), Delivery::query()->count(),
        RemoteActor::query()->whereNotNull('gone_at')->count(),
    ];
});

it('records a remote account only for the account itself, writing nothing otherwise', function (): void {
    $before = ($this->rows)();
    $admin = User::factory()->create()->assignRole('administrator');

    foreach ([new RecordRemoteActorInput(Actors::remoteDocument(), $this->mallory), new RecordRemoteActorInput(Actors::remoteDocument()), new RecordRemoteActorInput(Actors::remoteDocument(), actorId: (string) $admin->id)] as $input) {
        expect(fn () => app(RecordRemoteActor::class)->execute($input))->toThrow(AuthorizationException::class);
    }

    expect(($this->rows)())->toBe($before);

    $remote = app(RecordRemoteActor::class)->execute(new RecordRemoteActorInput(Actors::remoteDocument(), $this->alice));
    expect([$remote->uri, $remote->host, $remote->inbox_url, $remote->shared_inbox_url, $remote->handle])
        ->toBe([Inbox::ALICE, 'remote.example', Inbox::ALICE.'/inbox', 'https://remote.example/inbox', 'alice@remote.example']);
});

it('accepts a Follow only from the follower itself, writing nothing otherwise', function (): void {
    $remote = Actors::remote();
    $before = ($this->rows)();
    $admin = User::factory()->create()->assignRole('administrator');

    foreach ([$this->mallory, null] as $sender) {
        expect(fn () => app(AcceptFollow::class)->execute(new AcceptFollowInput($this->blog->id, $remote->id, Inbox::follow(url('/ap/blog')), $sender)))
            ->toThrow(AuthorizationException::class);
    }

    expect(fn () => app(AcceptFollow::class)->execute(new AcceptFollowInput($this->blog->id, $remote->id, Inbox::follow(url('/ap/blog')), $this->alice, (string) $admin->id)))
        ->toThrow(AuthorizationException::class)
        ->and(($this->rows)())->toBe($before);
});

it('removes a follower only for the account itself or someone who manages the fediverse, writing nothing otherwise', function (): void {
    $remote = Actors::remote();
    Follower::query()->create(['local_actor_id' => $this->blog->id, 'remote_actor_id' => $remote->id, 'since' => now()]);
    $before = ($this->rows)();
    $editor = User::factory()->create()->assignRole('editor');

    foreach ([new RemoveFollowerInput(Inbox::ALICE, gone: true, sender: $this->mallory), new RemoveFollowerInput(Inbox::ALICE, gone: true), new RemoveFollowerInput(Inbox::ALICE, gone: true, actorId: (string) $editor->id)] as $input) {
        expect(fn () => app(RemoveFollower::class)->execute($input))->toThrow(AuthorizationException::class);
    }

    expect(($this->rows)())->toBe($before);

    $admin = User::factory()->create()->assignRole('administrator');
    expect(app(RemoveFollower::class)->execute(new RemoveFollowerInput(Inbox::ALICE, actorId: (string) $admin->id)))->toBe(1);
});

it('gives administrators settings.fediverse.manage on an existing site, adding only', function (): void {
    Permission::query()->where('name', 'settings.fediverse.manage')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $migrate = function (): void {
        (require database_path('migrations/2027_07_15_100100_add_fediverse_permission.php'))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    };

    $migrate();
    $migrate();

    expect(Role::findByName('administrator')->hasPermissionTo('settings.fediverse.manage'))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo('settings.fediverse.manage'))->toBeFalse()
        ->and(DB::table('permissions')->where('name', 'settings.fediverse.manage')->count())->toBe(1)
        ->and(RoleSeeder::PERMISSIONS)->toContain('settings.fediverse.manage');
});
