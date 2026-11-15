<?php

declare(strict_types=1);

use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Security\EncryptedValues;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Fediverse\Actors;
use Tests\Fixtures\Security\OldKey;
use Tests\Fixtures\Security\Reencrypt;
use Tests\Fixtures\Security\SealedSecrets;

uses(RefreshDatabase::class);

it('refuses an actor of a kind it does not know', function (): void {
    expect(fn () => DB::table('fediverse_actors')->insert([
        'id' => (string) str()->ulid(),
        'kind' => 'group',
        'uri' => 'https://example.com/ap/group',
        'username' => 'group',
        'urls' => '{}',
        'public_key_pem' => 'pem',
        'private_key' => 'secret',
    ]))->toThrow(QueryException::class, 'fediverse_actors_kind');
});

it('refuses an object of a kind it does not know', function (): void {
    expect(fn () => DB::table('fediverse_objects')->insert(['uri' => 'https://example.com/note', 'kind' => 'note']))
        ->toThrow(QueryException::class, 'fediverse_objects_kind');
});

it('refuses a delivery in a state it does not know', function (): void {
    $actor = Actors::blog();
    $activity = (string) str()->ulid();
    DB::table('fediverse_activities')->insert([
        'id' => $activity, 'local_actor_id' => $actor->id, 'type' => 'Create',
        'object_uri' => 'https://example.com/ap/objects/1', 'payload' => '{}', 'created_at' => now(),
    ]);

    expect(fn () => DB::table('fediverse_deliveries')->insert([
        'id' => (string) str()->ulid(), 'activity_id' => $activity, 'inbox_url' => 'https://remote.example/inbox',
        'host' => 'remote.example', 'state' => 'lost', 'next_attempt_at' => now(),
    ]))->toThrow(QueryException::class, 'fediverse_deliveries_state');
});

it('refuses a second actor with the same user name in another case', function (): void {
    Actors::blog(username: 'Blog');

    expect(fn () => Actors::blog(username: 'blog', uri: 'https://example.com/ap/other'))
        ->toThrow(QueryException::class, 'fediverse_actors_username_unique');
});

it('keeps an actor’s private key unreadable in the raw row', function (): void {
    $actor = Actors::blog();
    $raw = (string) DB::table('fediverse_actors')->where('id', $actor->id)->value('private_key');

    expect($raw)->not->toContain('PRIVATE KEY')
        ->and(Crypt::decryptString($raw))->toContain('PRIVATE KEY')
        ->and(FediverseActor::query()->findOrFail($actor->id)->toArray())->not->toHaveKey('private_key');
});

it('registers the private key with the encrypted values', function (): void {
    $names = array_map(fn ($column): string => $column->name(), app(EncryptedValues::class)->all());

    expect($names)->toContain('fediverse_actors.private_key');
});

it('re-encrypts an actor’s private key under this server’s key', function (): void {
    SealedSecrets::serverPreviousKeys([]);
    $old = OldKey::make();
    $actor = Actors::blog();
    $pem = FediverseActor::query()->findOrFail($actor->id)->private_key;
    DB::table('fediverse_actors')->where('id', $actor->id)->update(['private_key' => $old->encrypter->encryptString($pem)]);

    Reencrypt::asOperator($old->keys());

    expect(Crypt::decryptString((string) DB::table('fediverse_actors')->where('id', $actor->id)->value('private_key')))->toBe($pem);
});
