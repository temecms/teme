<?php

declare(strict_types=1);

use App\Domain\Users\Operations\Input\UpgradePasswordHashInput;
use App\Domain\Users\Operations\UpgradePasswordHash;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * A person whose stored hash is exactly this one, written past the model's
 * cast, which would hash it again.
 */
function uphUser(string $hash): User
{
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['password' => $hash]);

    return $user->refresh();
}

function uphUpgrade(User $user, string $password, ?string $actorId = null): mixed
{
    return app(UpgradePasswordHash::class)->execute(new UpgradePasswordHashInput(
        userId: (string) $user->id,
        password: $password,
        actorId: $actorId ?? (string) $user->id,
    ));
}

function uphStored(User $user): string
{
    return (string) DB::table('users')->where('id', $user->id)->value('password');
}

/**
 * @return list<array{user_id: int|null, context: mixed}>
 */
function uphLogged(): array
{
    return DB::table('security_events')->where('type', 'password.hash_upgraded')->orderBy('id')->get()
        ->map(fn (object $row): array => ['user_id' => $row->user_id, 'context' => json_decode((string) $row->context, true)])
        ->values()
        ->all();
}

it('replaces an older hash with Argon2id when the password matches', function (string $hash, string $from): void {
    $user = uphUser($hash);

    expect(uphUpgrade($user, 'correct horse battery staple'))->toBeTrue();

    $stored = uphStored($user);

    expect($stored)->toStartWith('$argon2id$')
        ->and(Hash::needsRehash($stored))->toBeFalse()
        ->and(Hash::check('correct horse battery staple', $stored))->toBeTrue()
        ->and(uphLogged())->toBe([['user_id' => $user->id, 'context' => ['from' => $from]]]);
})->with([
    'phpass' => ['$P$BTeme1234mc6/ctael236960/qKEKM1', 'phpass'],
    'WordPress bcrypt' => ['$wp$2y$04$5aAxxvIskoUury1rVRrTPeh8TStPa0/V0C4uO5YAdVIvZCIbKRN5C', 'wordpress-bcrypt'],
    'MD5' => ['9cc2ae8a1ba7a93da39b46fc1019c481', 'md5'],
    'bcrypt' => [password_hash('correct horse battery staple', PASSWORD_BCRYPT, ['cost' => 4]), 'bcrypt'],
    'Argon2id at other costs' => [password_hash('correct horse battery staple', PASSWORD_ARGON2ID, ['memory_cost' => 512, 'time_cost' => 1, 'threads' => 1]), 'argon2id'],
]);

it('writes nothing when someone else asks', function (): void {
    $user = uphUser('$P$BTeme1234mc6/ctael236960/qKEKM1');
    $stranger = User::factory()->create();

    expect(fn () => uphUpgrade($user, 'correct horse battery staple', (string) $stranger->id))
        ->toThrow(AuthorizationException::class);

    expect(uphStored($user))->toBe('$P$BTeme1234mc6/ctael236960/qKEKM1')
        ->and(uphLogged())->toBe([]);
});

it('writes nothing when no one is signed in', function (): void {
    $user = uphUser('$P$BTeme1234mc6/ctael236960/qKEKM1');

    expect(fn () => app(UpgradePasswordHash::class)->execute(new UpgradePasswordHashInput(
        userId: (string) $user->id,
        password: 'correct horse battery staple',
        actorId: null,
    )))->toThrow(AuthorizationException::class);

    expect(uphStored($user))->toBe('$P$BTeme1234mc6/ctael236960/qKEKM1')
        ->and(uphLogged())->toBe([]);
});

it('writes nothing, and never sets the password it is given, when that password does not match', function (): void {
    $user = uphUser('$P$BTeme1234mc6/ctael236960/qKEKM1');

    expect(uphUpgrade($user, 'a password nobody signed in with'))->toBeFalse()
        ->and(uphStored($user))->toBe('$P$BTeme1234mc6/ctael236960/qKEKM1')
        ->and(uphLogged())->toBe([]);
});

it('writes nothing when the hash is already Argon2id at the configured costs', function (): void {
    $current = Hash::make('correct horse battery staple');
    $user = uphUser($current);

    expect(uphUpgrade($user, 'correct horse battery staple'))->toBeFalse()
        ->and(uphStored($user))->toBe($current)
        ->and(uphLogged())->toBe([]);
});

it('writes nothing for a hash it does not recognise, even with the right password', function (): void {
    $md5Crypt = crypt('correct horse battery staple', '$1$teme1234$');
    $user = uphUser($md5Crypt);

    expect(uphUpgrade($user, 'correct horse battery staple'))->toBeFalse()
        ->and(uphStored($user))->toBe($md5Crypt)
        ->and(uphLogged())->toBe([]);
});

it('keeps the remember token and sends no notice', function (): void {
    Notification::fake();
    $user = uphUser('$P$BTeme1234mc6/ctael236960/qKEKM1');
    $token = $user->remember_token;

    expect(uphUpgrade($user, 'correct horse battery staple'))->toBeTrue()
        ->and($user->refresh()->remember_token)->toBe($token);

    Notification::assertNothingSent();
});

it('hashes a password that itself looks like a hash', function (): void {
    $password = '$2y$04$abcdefghijklmnopqrstuu7EJV7kdjBBQxyb0HjTh9KS7.Lah/6CG';
    $user = uphUser(md5($password));

    expect(uphUpgrade($user, $password))->toBeTrue();

    $stored = uphStored($user);

    expect($stored)->toStartWith('$argon2id$')
        ->and(Hash::check($password, $stored))->toBeTrue();
});

it('keeps a WordPress password as WordPress kept it, without the spaces around it, and its own bcrypt one as typed', function (): void {
    $wordpress = uphUser('$P$BTeme1234mc6/ctael236960/qKEKM1');
    $bcrypt = uphUser(password_hash(' correct horse battery staple ', PASSWORD_BCRYPT, ['cost' => 4]));

    expect(uphUpgrade($wordpress, ' correct horse battery staple '))->toBeTrue()
        ->and(uphUpgrade($bcrypt, ' correct horse battery staple '))->toBeTrue();

    expect(Hash::check('correct horse battery staple', uphStored($wordpress)))->toBeTrue()
        ->and(Hash::check(' correct horse battery staple ', uphStored($wordpress)))->toBeFalse()
        ->and(Hash::check(' correct horse battery staple ', uphStored($bcrypt)))->toBeTrue()
        ->and(Hash::check('correct horse battery staple', uphStored($bcrypt)))->toBeFalse();
});

it('refuses an empty password and writes nothing', function (): void {
    $user = uphUser('9cc2ae8a1ba7a93da39b46fc1019c481');

    expect(fn () => uphUpgrade($user, ''))->toThrow(ValidationException::class);

    expect(uphStored($user))->toBe('9cc2ae8a1ba7a93da39b46fc1019c481');
});
