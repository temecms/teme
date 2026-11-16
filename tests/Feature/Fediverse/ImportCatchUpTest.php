<?php

declare(strict_types=1);

use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\Follower;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Fediverse\ImportFixtures;
use Tests\Fixtures\Fediverse\Actors;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->companion = importFake();
    ImportFixtures::api($this->companion, 3);
    ImportFixtures::steps();
    $this->source = importSource();
    $this->ada = ImportFixtures::person($this->source, 1);
});

/**
 * Who follows whom: each remote account's address with the usernames it follows.
 *
 * @return array<string, list<string>>
 */
function fiFollows(): array
{
    $follows = [];

    foreach (DB::table('fediverse_followers')->join('fediverse_remote_actors', 'fediverse_remote_actors.id', '=', 'fediverse_followers.remote_actor_id')
        ->join('fediverse_actors', 'fediverse_actors.id', '=', 'fediverse_followers.local_actor_id')
        ->orderBy('fediverse_remote_actors.uri')->orderBy('fediverse_actors.username')->get(['fediverse_remote_actors.uri', 'fediverse_actors.username']) as $row) {
        $follows[$row->uri][] = $row->username;
    }

    return $follows;
}

it('replaces the followers on a catch-up, keeping one who followed again through Teme', function (): void {
    $this->companion->fediverse(ImportFixtures::whole(), [
        ImportFixtures::follower(40, 'https://remote.example/users/bob', [0, 1]),
        ImportFixtures::follower(41, 'https://social.example/users/cy', [1]),
    ]);
    ImportFixtures::run($this->source);
    $blog = FediverseActor::query()->where('kind', FediverseActor::BLOG)->sole();
    $zed = Actors::remote('https://zed.example/users/zed');
    Follower::query()->insert(['local_actor_id' => $blog->id, 'remote_actor_id' => $zed->id, 'follow_uri' => 'https://zed.example/follows/1', 'since' => now()]);

    $this->companion->fediverse(ImportFixtures::whole(), [
        ImportFixtures::follower(40, 'https://remote.example/users/bob', [1]),
        ImportFixtures::follower(43, 'https://new.example/users/dan', [0]),
    ]);
    $catchUp = ImportFixtures::run($this->source, ImportRunKind::CatchUp);

    expect($catchUp->state)->toBe(ImportRunState::Finished)
        ->and(fiFollows())->toBe([
            'https://new.example/users/dan' => ['old.example'],
            'https://remote.example/users/bob' => ['ada'],
            'https://zed.example/users/zed' => ['old.example'],
        ])
        ->and(DB::table('import_map')->where('object_type', 'fediverse-follower')->orderBy('object_id')->pluck('object_id')->all())->toBe(['40', '43']);
});

it('never replaces an account or its keys once written, whatever WordPress says later', function (): void {
    $this->companion->fediverse(ImportFixtures::whole());
    ImportFixtures::run($this->source);
    $before = DB::table('fediverse_actors')->orderBy('id')->get()->all();

    $changed = ImportFixtures::actor(1, 'ada', Actors::keys('site'), changes: ['inbox' => 'https://old.example/elsewhere/inbox']);
    $this->companion->fediverse(ImportFixtures::whole('actor_blog', [ImportFixtures::actor(0, 'old.example', ['public' => '', 'private' => '']), $changed]));
    ImportFixtures::run($this->source, ImportRunKind::CatchUp);

    expect(DB::table('fediverse_actors')->orderBy('id')->get()->all())->toEqual($before);
});
