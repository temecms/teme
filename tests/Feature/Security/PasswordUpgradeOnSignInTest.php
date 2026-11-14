<?php

declare(strict_types=1);

use App\Http\Admin\Auth\UpgradeHashAfterSignIn;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Fortify;

uses(RefreshDatabase::class);

/**
 * Gives a person exactly this stored hash, written past the model's cast,
 * which would hash it again.
 */
function upsGiveHash(User $user, string $hash): User
{
    DB::table('users')->where('id', $user->id)->update(['password' => $hash]);

    return $user->refresh();
}

function upsSignIn(User $user, string $password): TestResponse
{
    $response = test()->withServerVariables(['REMOTE_ADDR' => '198.51.100.40'])
        ->post('/admin/login', ['email' => $user->email, 'password' => $password]);

    Auth::forgetGuards();

    return $response;
}

function upsSignOut(): void
{
    test()->post('/admin/logout');
    Auth::forgetGuards();
}

function upsStored(User $user): string
{
    return (string) DB::table('users')->where('id', $user->id)->value('password');
}

/**
 * @return list<array{user_id: int|null, context: mixed}>
 */
function upsLogged(): array
{
    return DB::table('security_events')->where('type', 'password.hash_upgraded')->orderBy('id')->get()
        ->map(fn (object $row): array => ['user_id' => $row->user_id, 'context' => json_decode((string) $row->context, true)])
        ->values()
        ->all();
}

it('signs in with an older hash and leaves Argon2id in its place', function (string $hash, string $from): void {
    $user = upsGiveHash(User::factory()->create(), $hash);

    upsSignIn($user, 'correct horse battery staple')
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $stored = upsStored($user);

    expect($stored)->toStartWith('$argon2id$')
        ->and(Hash::check('correct horse battery staple', $stored))->toBeTrue()
        ->and(upsLogged())->toBe([['user_id' => $user->id, 'context' => ['from' => $from]]]);
})->with([
    'phpass' => ['$P$BTeme1234mc6/ctael236960/qKEKM1', 'phpass'],
    'WordPress bcrypt' => ['$wp$2y$04$5aAxxvIskoUury1rVRrTPeh8TStPa0/V0C4uO5YAdVIvZCIbKRN5C', 'wordpress-bcrypt'],
    'MD5' => ['9cc2ae8a1ba7a93da39b46fc1019c481', 'md5'],
    'bcrypt' => [password_hash('correct horse battery staple', PASSWORD_BCRYPT, ['cost' => 4]), 'bcrypt'],
    'Argon2id at other costs' => [password_hash('correct horse battery staple', PASSWORD_ARGON2ID, ['memory_cost' => 512, 'time_cost' => 1, 'threads' => 1]), 'argon2id'],
]);

it('leaves a current Argon2id hash as it is', function (): void {
    $user = User::factory()->create(['password' => 'correct horse battery staple']);
    $current = upsStored($user);

    upsSignIn($user, 'correct horse battery staple')->assertSessionHasNoErrors();

    expect(upsStored($user))->toBe($current)
        ->and(upsLogged())->toBe([])
        ->and(DB::table('security_events')->where('type', 'signin.succeeded')->count())->toBe(1);
});

it('upgrades nothing when the password is wrong', function (): void {
    $user = upsGiveHash(User::factory()->create(), '$P$BTeme1234mc6/ctael236960/qKEKM1');

    upsSignIn($user, 'correct horse battery stapler')->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(upsStored($user))->toBe('$P$BTeme1234mc6/ctael236960/qKEKM1')
        ->and(upsLogged())->toBe([]);
});

it('upgrades nothing when the sign-in is throttled, even with the right password', function (): void {
    $user = upsGiveHash(User::factory()->create(), '$P$BTeme1234mc6/ctael236960/qKEKM1');

    foreach (range(1, 5) as $attempt) {
        upsSignIn($user, 'correct horse battery stapler');
    }

    upsSignIn($user, 'correct horse battery staple')->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(upsStored($user))->toBe('$P$BTeme1234mc6/ctael236960/qKEKM1')
        ->and(upsLogged())->toBe([]);
});

it('upgrades nothing before a second factor is passed', function (): void {
    $user = upsGiveHash(User::factory()->withTwoFactor()->create(), '$P$BTeme1234mc6/ctael236960/qKEKM1');

    upsSignIn($user, 'correct horse battery staple')->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect(upsStored($user))->toBe('$P$BTeme1234mc6/ctael236960/qKEKM1')
        ->and(upsLogged())->toBe([]);
});

it('signs in again with the same password after the upgrade, and upgrades once', function (): void {
    $user = upsGiveHash(User::factory()->create(), '$P$BTeme1234mc6/ctael236960/qKEKM1');

    upsSignIn($user, 'correct horse battery staple')->assertSessionHasNoErrors();
    $upgraded = upsStored($user);
    upsSignOut();

    upsSignIn($user, 'correct horse battery staple')
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    expect($upgraded)->toStartWith('$argon2id$')
        ->and(upsStored($user))->toBe($upgraded)
        ->and(upsLogged())->toHaveCount(1);
});

it('keeps the person signed in on the next request after the upgrade', function (): void {
    $user = upsGiveHash(User::factory()->create(), '$P$BTeme1234mc6/ctael236960/qKEKM1');

    upsSignIn($user, 'correct horse battery staple')->assertSessionHasNoErrors();

    expect(upsStored($user))->toStartWith('$argon2id$');

    $this->get(route('dashboard'))->assertOk();
    $this->assertAuthenticatedAs($user);
});

it('lets in a WordPress password typed with spaces around it, and after the upgrade the password without them', function (): void {
    $user = upsGiveHash(User::factory()->create(), '$P$BTeme1234mc6/ctael236960/qKEKM1');

    upsSignIn($user, ' correct horse battery staple ')->assertSessionHasNoErrors();
    upsSignOut();

    upsSignIn($user, 'correct horse battery staple')
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    expect(upsStored($user))->toStartWith('$argon2id$')
        ->and(upsLogged())->toHaveCount(1);
});

it('upgrades the hash of a suspended account, which is still kept out', function (): void {
    $this->seed(RoleSeeder::class);
    $user = upsGiveHash(User::factory()->suspended()->create()->assignRole('author'), '$P$BTeme1234mc6/ctael236960/qKEKM1');

    upsSignIn($user, 'correct horse battery staple');

    $this->get('/admin')->assertRedirect(route('login'));
    $this->assertGuest();
    expect(upsStored($user))->toStartWith('$argon2id$');
});

it('never writes the password or either hash into the security log', function (): void {
    $user = upsGiveHash(User::factory()->create(), '$P$BTeme1234mc6/ctael236960/qKEKM1');

    upsSignIn($user, 'correct horse battery staple')->assertSessionHasNoErrors();

    $log = (string) json_encode(DB::table('security_events')->get(), JSON_UNESCAPED_SLASHES);

    expect($log)->toContain('password.hash_upgraded')
        ->and($log)->not->toContain('correct horse battery staple')
        ->and($log)->not->toContain('Teme1234')
        ->and($log)->not->toContain(upsStored($user));
});

it('runs the upgrade as the last step of the sign-in pipeline', function (): void {
    $steps = array_values(call_user_func(Fortify::$authenticateThroughCallback, request()));

    expect(array_slice($steps, -2))->toBe([PrepareAuthenticatedSession::class, UpgradeHashAfterSignIn::class]);
});
