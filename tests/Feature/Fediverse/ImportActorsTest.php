<?php

declare(strict_types=1);

use App\Domain\Fediverse\FediverseSettingsGroup;
use App\Domain\Fediverse\FediverseSwitch;
use App\Domain\Fediverse\Import\Operations\ImportFediverse;
use App\Domain\Fediverse\Import\Operations\Input\ImportFediverseInput;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Models\Follower;
use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Fediverse\ImportFixtures;
use Tests\Fixtures\Fediverse\Actors;
use Tests\Fixtures\Import\FakeCompanion;
use Tests\Fixtures\Import\ImportRecords;

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
 * @return list<string>
 */
function fiCodes(ImportRun $run): array
{
    return ImportMessageRecord::query()->where('run_id', $run->id)->orderBy('code')->pluck('code')->all();
}

it('brings each account at its old address, as each of the plugin’s modes had it', function (string $mode, array $users, array $enabled, string $followable): void {
    $actors = array_map(fn (int $user): array => ImportFixtures::actor($user, $user === 0 ? 'old.example' : 'ada'), $users);
    $this->companion->fediverse(ImportFixtures::whole($mode, $actors));

    $run = ImportFixtures::run($this->source);
    $imported = FediverseActor::query()->whereIn('kind', [FediverseActor::BLOG, FediverseActor::PERSON])->where('uri', 'like', ImportFixtures::HOME.'%')->orderBy('uri')->get();

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(fiCodes($run))->toBe([])
        ->and($imported->pluck('uri')->all())->toBe(array_map(fn (int $user): string => ImportFixtures::HOME.'/?author='.$user, $users))
        ->and($imported->pluck('enabled', 'uri')->values()->all())->toBe($enabled)
        ->and(FediverseSettingsGroup::followable())->toBe($followable);

    foreach ($imported as $actor) {
        $user = (int) substr($actor->uri, strlen(ImportFixtures::HOME.'/?author='));
        $rest = ImportFixtures::REST.'/actors/'.$user;

        expect($actor->urls)->toBe([
            'inbox' => $rest.'/inbox', 'outbox' => $rest.'/outbox', 'followers' => $rest.'/followers', 'following' => $rest.'/following',
            'sharedInbox' => ImportFixtures::REST.'/inbox',
        ])
            ->and($actor->person_id)->toBe($user === 0 ? null : $this->ada->id)
            ->and($actor->kind)->toBe($user === 0 ? FediverseActor::BLOG : FediverseActor::PERSON)
            ->and($actor->aliases)->toBe([ImportFixtures::HOME.'/@'.($user === 0 ? 'old.example' : 'ada')])
            ->and($actor->public_key_pem)->toBe(Actors::keys($user === 0 ? 'site' : 'remote')['public']);
    }
})->with([
    'each author' => ['actor', [1], [true], FediverseSettingsGroup::AUTHORS],
    'the blog' => ['blog', [0], [true], FediverseSettingsGroup::BLOG],
    'both' => ['actor_blog', [0, 1], [true, true], FediverseSettingsGroup::BOTH],
]);

it('keeps the blog’s permalink address when WordPress used it as the account’s address', function (): void {
    $blog = ImportFixtures::actor(0, 'old.example', id: ImportFixtures::HOME.'/@old.example');
    $this->companion->fediverse(ImportFixtures::whole('blog', [$blog], ['activitypub_use_permalink_as_id_for_blog' => true]));

    ImportFixtures::run($this->source);
    $actor = FediverseActor::query()->where('kind', FediverseActor::BLOG)->sole();

    expect($actor->uri)->toBe(ImportFixtures::HOME.'/@old.example')
        ->and($actor->keyId())->toBe(ImportFixtures::HOME.'/@old.example#main-key')
        ->and($actor->aliases)->toBe([ImportFixtures::HOME.'/?author=0']);
});

it('keeps each private key encrypted, and never in the map, a message or a log', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });
    $broken = ImportFixtures::actor(1, 'ada', ['public' => Actors::keys('remote')['public'], 'private' => Actors::keys('site')['private']]);
    $this->companion->fediverse(ImportFixtures::whole('actor_blog', [ImportFixtures::actor(0, 'old.example'), $broken]));

    $run = ImportFixtures::run($this->source);
    $blog = FediverseActor::query()->where('kind', FediverseActor::BLOG)->sole();
    $raw = (string) DB::table('fediverse_actors')->where('id', $blog->id)->value('private_key');
    $everything = json_encode([
        DB::table('import_map')->get()->all(),
        DB::table('import_messages')->get()->all(),
        DB::table('import_runs')->get()->all(),
        $logged,
    ]);
    $body = fn (string $pem): string => (string) preg_replace('/-----[^-]+-----|\s+/', '', $pem);

    expect($blog->private_key)->toBe(Actors::keys('site')['private'])
        ->and($raw)->not->toContain('PRIVATE KEY')
        ->and($everything)->not->toContain(substr($body(Actors::keys('site')['private']), 0, 64))
        ->and($everything)->not->toContain(substr($body(Actors::keys('remote')['private']), 40, 64))
        ->and($everything)->not->toContain('PRIVATE KEY')
        ->and(fiCodes($run))->toBe(['fediverse.key-made']);
});

it('makes a new key pair for an account WordPress had no key for, and says so', function (): void {
    $keyless = ImportFixtures::actor(1, 'ada', ['public' => '', 'private' => '']);
    $this->companion->fediverse(ImportFixtures::whole('actor', [$keyless]));

    $run = ImportFixtures::run($this->source);
    $actor = FediverseActor::query()->where('person_id', $this->ada->id)->sole();
    $details = openssl_pkey_get_details(openssl_pkey_get_private($actor->private_key));

    expect(fiCodes($run))->toBe(['fediverse.key-made'])
        ->and(ImportMessageRecord::query()->where('run_id', $run->id)->value('message'))->toContain('made a new one')
        ->and($actor->public_key_pem)->toStartWith('-----BEGIN PUBLIC KEY-----')
        ->and($details['key'])->toBe($actor->public_key_pem);
});

it('brings an author’s account only with the author, and says when it can’t', function (): void {
    $this->companion->fediverse(ImportFixtures::whole('actor', [ImportFixtures::actor(1, 'ada'), ImportFixtures::actor(7, 'nobody')]));

    $run = ImportFixtures::run($this->source);

    expect(FediverseActor::query()->where('kind', FediverseActor::PERSON)->pluck('username')->all())->toBe(['ada'])
        ->and(fiCodes($run))->toBe(['fediverse.author-missing']);
});

it('brings each follower of an imported account, following just the accounts WordPress says', function (): void {
    $this->companion->fediverse(ImportFixtures::whole(), [
        ImportFixtures::follower(40, 'https://remote.example/users/bob', [0, 1]),
        ImportFixtures::follower(41, 'https://social.example/users/cy', [1, 9]),
        ['id' => 42, 'guid' => 'https://elsewhere.example/users/eve', 'actor' => [...ImportFixtures::remoteDocument('https://elsewhere.example/users/eve'), 'inbox' => 'https://attacker.example/inbox'], 'following' => [0]],
    ]);

    $run = ImportFixtures::run($this->source);
    $blog = FediverseActor::query()->where('kind', FediverseActor::BLOG)->value('id');
    $ada = FediverseActor::query()->where('person_id', $this->ada->id)->value('id');
    $pairs = Follower::query()->join('fediverse_remote_actors', 'fediverse_remote_actors.id', '=', 'fediverse_followers.remote_actor_id')
        ->orderBy('uri')->orderBy('local_actor_id')->get(['uri', 'local_actor_id', 'follow_uri', 'since'])
        ->map(fn (object $row): array => [$row->uri, $row->local_actor_id === $blog ? 'blog' : ($row->local_actor_id === $ada ? 'ada' : '?'), $row->follow_uri])->all();
    $bob = RemoteActor::query()->where('uri', 'https://remote.example/users/bob')->sole();

    expect($pairs)->toBe([
        ['https://remote.example/users/bob', ...(strcmp($blog, $ada) < 0 ? ['blog'] : ['ada']), null],
        ['https://remote.example/users/bob', ...(strcmp($blog, $ada) < 0 ? ['ada'] : ['blog']), null],
        ['https://social.example/users/cy', 'ada', null],
    ])
        ->and($bob->inbox_url)->toBe('https://remote.example/users/bob/inbox')
        ->and($bob->shared_inbox_url)->toBe('https://remote.example/inbox')
        ->and($bob->key_id)->toBe('https://remote.example/users/bob#main-key')
        ->and(RemoteActor::query()->where('host', 'elsewhere.example')->exists())->toBeFalse()
        ->and(fiCodes($run))->toBe(['fediverse.follower-unreadable']);
});

it('keeps each imported post’s WordPress address, the permalink for posts the plugin knew by it', function (): void {
    $this->companion->fediverse(
        ImportFixtures::whole(settings: ['activitypub_last_post_with_permalink_as_id' => 20]),
        permalinks: [12 => 'https://old.example/2019/05/Harbour-News/', 20 => 'https://old.example/?p=20'],
    );
    $old = ImportFixtures::post($this->source, 12);
    $edge = ImportFixtures::post($this->source, 20);
    $new = ImportFixtures::post($this->source, 21);
    ImportFixtures::post($this->source, 22, 'draft');
    ImportFixtures::post($this->source, 23, type: 'page');

    ImportFixtures::run($this->source);

    expect(FediverseObject::query()->orderBy('uri')->get(['uri', 'kind', 'content_id'])->map(fn (FediverseObject $object): array => [$object->uri, $object->kind, $object->content_id])->all())->toBe([
        ['https://old.example/2019/05/Harbour-News/', 'post', $old->id],
        ['https://old.example/?p=20', 'post', $edge->id],
        ['https://old.example/?p=21', 'post', $new->id],
    ])
        ->and(array_column(array_column($this->companion->requestsTo('fediverse'), 'query'), 'ids'))->toBe(['12,20']);
});

it('copies who can be followed, says what Teme shares differently, and switches the fediverse on only when the plugin was active', function (bool $active): void {
    $this->companion->fediverse(ImportFixtures::whole('blog', [ImportFixtures::actor(0, 'old.example')], [
        'activitypub_object_type' => 'note',
        'activitypub_support_post_types' => ['post', 'page'],
    ], active: $active, followedBy: 3));

    $run = ImportFixtures::run($this->source);

    expect(FediverseSettingsGroup::followable())->toBe(FediverseSettingsGroup::BLOG)
        ->and(FediverseSwitch::on())->toBe($active)
        ->and(FediverseActor::query()->where('kind', FediverseActor::APPLICATION)->exists())->toBe($active)
        ->and(fiCodes($run))->toBe(['fediverse.notes', 'fediverse.site-follows', 'fediverse.types']);
})->with(['active' => true, 'inactive' => false]);

it('lets no one without imports.run write the plugin’s accounts, and writes nothing for them', function (): void {
    $runId = ImportRecords::run($this->source->id);
    $editor = User::factory()->create();
    $editor->assignRole('editor');
    $before = [FediverseActor::query()->count(), DB::table('import_map')->count(), DB::table('settings')->count()];

    expect(fn () => app(ImportFediverse::class)->execute(new ImportFediverseInput($this->source->id, $runId, ImportFediverseInput::ACTORS, [
        'active' => true, 'home' => FakeCompanion::SITE, 'actors' => [ImportFixtures::actor(0, 'old.example')], 'follows' => 0,
        'settings' => ['mode' => 'blog', 'blog_identifier' => 'old.example', 'object_type' => '', 'post_types' => ['post'], 'last_permalink_id' => 0, 'blog_also_known_as' => [], 'blog_moved_to' => null],
    ], (string) $editor->id)))->toThrow(AuthorizationException::class)
        ->and([FediverseActor::query()->count(), DB::table('import_map')->count(), DB::table('settings')->count()])->toBe($before);
});
