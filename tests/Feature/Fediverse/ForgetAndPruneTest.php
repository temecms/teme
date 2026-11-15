<?php

declare(strict_types=1);

use App\Domain\Comments\Models\Comment;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Models\Follower;
use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Fediverse\Operations\ForgetRemoteActor;
use App\Domain\Fediverse\Operations\Input\ForgetRemoteActorInput;
use App\Domain\Fediverse\Operations\Input\PruneFediverseInput;
use App\Domain\Fediverse\Operations\PruneFediverse;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Fediverse\Actors;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->blog = Actors::blog();
    $this->alice = Actors::remote();
    Follower::query()->create(['local_actor_id' => $this->blog->id, 'remote_actor_id' => $this->alice->id, 'since' => now()]);

    $item = lifecycleDraft($this->admin, 'Harbour notes');
    $this->reply = cmComment($item, 'approved', attributes: ['author_name' => 'Alice', 'author_url' => 'https://remote.example/@alice']);
    FediverseObject::query()->create(['uri' => 'https://remote.example/users/alice/statuses/1', 'kind' => FediverseObject::REPLY, 'comment_id' => $this->reply->id, 'remote_actor_id' => $this->alice->id]);

    $this->rows = fn (): array => [RemoteActor::query()->count(), Follower::query()->count(), Comment::query()->whereKey($this->reply->id)->value('author_name')];
});

it('forgets a remote account: its follows, its profile and the author details of its replies', function (): void {
    app(ForgetRemoteActor::class)->execute(new ForgetRemoteActorInput($this->alice->uri, (string) $this->admin->id));

    $reply = Comment::query()->findOrFail($this->reply->id);

    expect(RemoteActor::query()->where('uri', $this->alice->uri)->exists())->toBeFalse()
        ->and(Follower::query()->count())->toBe(0)
        ->and([$reply->author_name, $reply->author_url])->toBe([null, null])
        ->and(FediverseObject::query()->whereKey('https://remote.example/users/alice/statuses/1')->value('remote_actor_id'))->toBeNull();
});

it('forgets nothing for someone who does not manage the fediverse', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $before = ($this->rows)();

    foreach ([(string) $editor->id, null] as $actor) {
        expect(fn () => app(ForgetRemoteActor::class)->execute(new ForgetRemoteActorInput($this->alice->uri, $actor)))
            ->toThrow(AuthorizationException::class);
    }

    expect(($this->rows)())->toBe($before);
});

it('prunes remote accounts unseen for 30 days that follow nothing and wrote no reply', function (): void {
    $old = now()->subDays(31);
    $quiet = Actors::remote('https://remote.example/users/quiet', ['fetched_at' => $old]);
    $recent = Actors::remote('https://remote.example/users/recent', ['fetched_at' => now()->subDays(29)]);
    $following = Actors::remote('https://remote.example/users/following', ['fetched_at' => $old]);
    Follower::query()->create(['local_actor_id' => $this->blog->id, 'remote_actor_id' => $following->id, 'since' => $old]);
    $this->alice->update(['fetched_at' => $old]);
    Follower::query()->where('remote_actor_id', $this->alice->id)->delete();

    expect(app(PruneFediverse::class)->execute(new PruneFediverseInput((string) $this->admin->id)))->toBe(1)
        ->and(RemoteActor::query()->pluck('uri')->sort()->values()->all())->toBe([$this->alice->uri, $following->uri, $recent->uri]);

    expect(RemoteActor::query()->whereKey($quiet->id)->exists())->toBeFalse();
});

it('lets the operator prune, and prunes nothing for anyone else', function (): void {
    Actors::remote('https://remote.example/users/quiet', ['fetched_at' => now()->subDays(31)]);
    $editor = User::factory()->create()->assignRole('editor');
    $count = RemoteActor::query()->count();

    foreach ([(string) $editor->id, null] as $actor) {
        expect(fn () => app(PruneFediverse::class)->execute(new PruneFediverseInput($actor)))->toThrow(AuthorizationException::class);
    }

    expect(RemoteActor::query()->count())->toBe($count);

    app()->instance(Elevation::class, new OperatorElevation);
    expect(app(PruneFediverse::class)->execute(new PruneFediverseInput))->toBe(1);
});
