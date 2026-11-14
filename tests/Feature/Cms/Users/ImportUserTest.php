<?php

declare(strict_types=1);

use App\Domain\Addresses\Models\Path;
use App\Domain\Users\Operations\ImportUser;
use App\Domain\Users\Operations\Input\ImportUserInput;
use App\Domain\Users\Operations\Input\UpdateImportedUserInput;
use App\Domain\Users\Operations\UpdateImportedUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->importer = User::factory()->create();
    $this->importer->assignRole('administrator');
});

/**
 * WordPress's phpass hash of "correct horse battery staple", as WordPress
 * 2.5 to 6.7 wrote it.
 */
function iusPhpass(): string
{
    return '$P$BTeme1234mc6/ctael236960/qKEKM1';
}

/**
 * The same password's bcrypt hash as WordPress 6.8 writes it.
 */
function iusWordPressBcrypt(): string
{
    return '$wp$2y$04$5aAxxvIskoUury1rVRrTPeh8TStPa0/V0C4uO5YAdVIvZCIbKRN5C';
}

/**
 * Imports one person as $actor (the operator when null), with these
 * fields over a contributor's.
 *
 * @param  array<string, mixed>  $fields
 *
 * @return array{userId: string}
 */
function iusImport(array $fields, ?User $actor): array
{
    return app(ImportUser::class)->execute(new ImportUserInput(...[
        'name' => 'Grace Hopper',
        'email' => 'grace@old.example',
        'slug' => 'grace',
        'bio' => 'Writes about ships.',
        'role' => 'contributor',
        'passwordHash' => iusPhpass(),
        'registeredAt' => CarbonImmutable::parse('2020-06-11 14:30:00', 'UTC'),
        'actorId' => $actor === null ? null : (string) $actor->id,
        ...$fields,
    ]));
}

/**
 * A person as an earlier run left them: WordPress's phpass hash, not yet
 * signed in here.
 */
function iusImported(User $actor): User
{
    return User::query()->findOrFail(iusImport([], $actor)['userId']);
}

/**
 * A later run's update of $person, as $actor, with these fields over what
 * the earlier run wrote.
 *
 * @param  array<string, mixed>  $fields
 */
function iusUpdate(User $person, array $fields, ?User $actor): bool
{
    return app(UpdateImportedUser::class)->execute(new UpdateImportedUserInput(...[
        'userId' => (string) $person->id,
        'name' => 'Grace Hopper',
        'bio' => 'Writes about ships.',
        'passwordHash' => iusPhpass(),
        'role' => 'contributor',
        'actorId' => $actor === null ? null : (string) $actor->id,
        ...$fields,
    ]));
}

/**
 * @return list<string>
 */
function iusRoles(User $person): array
{
    return User::query()->findOrFail($person->id)->getRoleNames()->all();
}

function iusStored(int|string $userId): string
{
    return (string) DB::table('users')->where('id', $userId)->value('password');
}

it('creates a person with their role, address and joining date, keeping the hash so their WordPress password signs them in', function (): void {
    $result = iusImport(['role' => 'editor'], $this->importer);
    $user = User::query()->findOrFail($result['userId']);

    expect(iusStored($user->id))->toBe(iusPhpass())
        ->and(Hash::check('correct horse battery staple', iusStored($user->id)))->toBeTrue()
        ->and($user->getRoleNames()->all())->toBe(['editor'])
        ->and([$user->name, $user->email, $user->slug, $user->bio])->toBe(['Grace Hopper', 'grace@old.example', 'grace', 'Writes about ships.'])
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(DB::table('users')->where('id', $user->id)->value('created_at'))->toBe('2020-06-11 14:30:00')
        ->and(Path::query()->where('target_type', 'user')->where('target_id', (string) $user->id)->where('is_current', true)->value('path'))->toBe('author/grace');
});

it('keeps the bcrypt hash WordPress 6.8 writes as it is, never hashed a second time', function (): void {
    $result = iusImport(['passwordHash' => iusWordPressBcrypt()], $this->importer);

    expect(iusStored($result['userId']))->toBe(iusWordPressBcrypt())
        ->and(Hash::check('correct horse battery staple', iusStored($result['userId'])))->toBeTrue();
});

it('gives a person whose hash Teme can not check a random password of its own', function (): void {
    $result = iusImport(['passwordHash' => null], $this->importer);

    expect(iusStored($result['userId']))->toStartWith('$argon2id$')
        ->and(Hash::check('correct horse battery staple', iusStored($result['userId'])))->toBeFalse();
});

it('lets the operator import a person', function (): void {
    $result = scElevated(fn (): array => iusImport([], null));

    expect(User::query()->whereKey($result['userId'])->value('email'))->toBe('grace@old.example');
});

it('writes nothing when the actor may not import', function (string $who): void {
    $editor = User::factory()->create();
    $editor->assignRole('editor');
    $before = User::query()->count();

    expect(fn () => iusImport([], $who === 'an editor' ? $editor : null))->toThrow(AuthorizationException::class)
        ->and(User::query()->count())->toBe($before)
        ->and(Path::query()->where('path', 'author/grace')->exists())->toBeFalse();
})->with(['an editor', 'no one, away from the operator\'s shell']);

it('refuses what Teme can not keep, writing nothing', function (string $case, string $key): void {
    User::factory()->create(['name' => 'Taken', 'email' => 'taken@old.example']);
    $before = User::query()->count();

    $fields = match ($case) {
        'email' => ['email' => 'Taken@Old.Example'],
        'slug' => ['slug' => 'taken'],
        'slug rule' => ['slug' => 'night_owl'],
        'role' => ['role' => 'owner'],
        'hash' => ['passwordHash' => '$1$Teme1234$abcdefghijklmnopqrstuv'],
        'argon2id without its version' => ['passwordHash' => '$argon2id$m=1024,t=100000,p=1$c2FsdHNhbHQ$aGFzaGhhc2hoYXNoaGFzaA'],
        default => ['bio' => str_repeat('a', 1001)],
    };

    try {
        iusImport($fields, $this->importer);
        $this->fail('Nothing was refused.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toHaveKey($key);
    }

    expect(User::query()->count())->toBe($before);
})->with([
    'an email address someone has, in other capitals' => ['email', 'email'],
    'an author address someone has' => ['slug', 'slug'],
    'an address Teme\'s slugs can\'t hold' => ['slug rule', 'slug'],
    'a role Teme doesn\'t have' => ['role', 'role'],
    'a hash in a format Teme can\'t check' => ['hash', 'password'],
    'an Argon2id hash without its version, whose costs PHP can\'t read' => ['argon2id without its version', 'password'],
    'a biography longer than 1,000 characters' => ['bio', 'bio'],
]);

it('refuses an address another account takes between the check and the write, saying nothing of the hash or the email', function (): void {
    $taken = false;

    // Another run, or an invitation, takes the address once the check has passed.
    DB::listen(function (QueryExecuted $query) use (&$taken): void {
        if (! $taken && str_contains($query->sql, 'count(*)') && str_contains($query->sql, '"email"')) {
            $taken = true;
            User::factory()->create(['name' => 'Quick', 'email' => 'grace@old.example']);
        }
    });

    try {
        iusImport([], $this->importer);
        $this->fail('Nothing was refused.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toBe(['email' => ['Another account took this email address or author address meanwhile.']]);
    }

    expect($taken)->toBeTrue()
        ->and(User::query()->where('email', 'grace@old.example')->pluck('name')->all())->toBe(['Quick']);
});

it('brings a person who has not signed in here up to date: name, biography and hash', function (): void {
    $person = iusImported($this->importer);

    $written = iusUpdate($person, ['name' => 'Rear Admiral Grace Hopper', 'bio' => 'Wrote COBOL.', 'passwordHash' => iusWordPressBcrypt()], $this->importer);
    $person->refresh();

    expect($written)->toBeTrue()
        ->and([$person->name, $person->bio])->toBe(['Rear Admiral Grace Hopper', 'Wrote COBOL.'])
        ->and(iusStored($person->id))->toBe(iusWordPressBcrypt());
});

it('changes nothing once the person\'s hash is Teme\'s', function (): void {
    $person = iusImported($this->importer);
    $teme = Hash::make('their Teme password');
    DB::table('users')->where('id', $person->id)->update(['password' => $teme]);

    $written = iusUpdate($person, ['name' => 'Someone Else', 'passwordHash' => iusWordPressBcrypt()], $this->importer);

    expect($written)->toBeFalse()
        ->and($person->refresh()->name)->toBe('Grace Hopper')
        ->and(iusStored($person->id))->toBe($teme);
});

it('never changes the person running the import', function (): void {
    $person = iusImported($this->importer);
    $person->assignRole('administrator');

    $written = iusUpdate($person, ['name' => 'Someone Else', 'passwordHash' => iusWordPressBcrypt()], $person);

    expect($written)->toBeFalse()
        ->and($person->refresh()->name)->toBe('Grace Hopper')
        ->and(iusStored($person->id))->toBe(iusPhpass());
});

it('writes nothing to a person when the actor may not import', function (): void {
    $person = iusImported($this->importer);
    $editor = User::factory()->create();
    $editor->assignRole('editor');

    expect(fn () => iusUpdate($person, ['name' => 'Someone Else', 'passwordHash' => iusWordPressBcrypt(), 'role' => 'contributor'], $editor))->toThrow(AuthorizationException::class)
        ->and($person->refresh()->name)->toBe('Grace Hopper')
        ->and(iusStored($person->id))->toBe(iusPhpass());
});

it('lowers a role WordPress lowered, never raises one, and never lowers the last active administrator', function (): void {
    $person = iusImported($this->importer);

    iusUpdate($person, ['role' => 'editor'], $this->importer);
    $notRaised = iusRoles($person);

    $person->syncRoles(['administrator']);
    $this->importer->syncRoles(['editor']);
    scElevated(fn (): bool => iusUpdate($person, ['role' => 'author'], null));
    $lastKept = iusRoles($person);

    $other = User::factory()->create();
    $other->assignRole('administrator');
    $lowered = scElevated(fn (): bool => iusUpdate($person, ['role' => 'author'], null));

    expect($notRaised)->toBe(['contributor'])
        ->and($lastKept)->toBe(['administrator'])
        ->and($lowered)->toBeTrue()
        ->and(iusRoles($person))->toBe(['author'])
        ->and(DB::table('security_events')->where('type', 'user.role_changed')->where('user_id', $person->id)->count())->toBe(1);
});
