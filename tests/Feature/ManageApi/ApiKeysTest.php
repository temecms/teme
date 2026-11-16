<?php

declare(strict_types=1);

use App\Domain\ManageApi\ApiAbility;
use App\Domain\ManageApi\ApiKeys;
use App\Domain\ManageApi\Models\ApiKey;
use App\Domain\ManageApi\Operations\CreateApiKey;
use App\Domain\ManageApi\Operations\Input\CreateApiKeyInput;
use App\Domain\ManageApi\Operations\Input\RevokeApiKeyInput;
use App\Domain\ManageApi\Operations\RevokeApiKey;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->writer = User::factory()->create()->assignRole('author');
    $this->withElevation();
});

/**
 * Makes a key as $actor, for $person, with these abilities.
 *
 * @param  list<string>  $abilities
 *
 * @return array{key: ApiKey, secret: string}
 */
function makeApiKey(User $actor, User|string $person, array $abilities = ['site.read'], ?string $expiresAt = null, string $name = 'Importer'): array
{
    $made = app(CreateApiKey::class)->execute(new CreateApiKeyInput($name, $person instanceof User ? (string) $person->id : $person, $abilities, $expiresAt, (string) $actor->id));

    return ['key' => $made->key, 'secret' => $made->secret];
}

it('makes a key for a person and gives its secret once, keeping only its hash and its start', function (): void {
    $made = makeApiKey($this->admin, $this->writer, ['site.read', 'content.write']);

    $row = DB::table('api_keys')->first();

    expect($made['secret'])->toMatch('/^teme_[0-9A-Za-z]{40}$/')
        ->and($row->token_hash)->toBe(hash('sha256', $made['secret']))
        ->and($row->token_start)->toBe(substr($made['secret'], 0, 12))
        ->and((int) $row->user_id)->toBe($this->writer->id)
        ->and((int) $row->created_by)->toBe($this->admin->id)
        ->and(json_decode((string) $row->abilities, true))->toBe(['site.read', 'content.write'])
        ->and(json_encode($row))->not->toContain($made['secret'])
        ->and($made['key']->abilities())->toBe([ApiAbility::SiteRead, ApiAbility::ContentWrite]);
});

it('finds a live key by its secret, and never a revoked, expired or unknown one', function (): void {
    $live = makeApiKey($this->admin, $this->writer, name: 'Live');
    $revoked = makeApiKey($this->admin, $this->writer, name: 'Revoked');
    $expiring = makeApiKey($this->admin, $this->writer, expiresAt: now()->addHour()->toIso8601String(), name: 'Expiring');

    app(RevokeApiKey::class)->execute(new RevokeApiKeyInput($revoked['key']->id, (string) $this->admin->id));
    $this->travel(2)->hours();

    $keys = app(ApiKeys::class);

    expect($keys->findLive($live['secret'])?->id)->toBe($live['key']->id)
        ->and($keys->findLive($revoked['secret']))->toBeNull()
        ->and($keys->findLive($expiring['secret']))->toBeNull()
        ->and($keys->findLive('teme_'.str_repeat('a', 40)))->toBeNull()
        ->and($keys->findLive(''))->toBeNull();
});

it('records making and revoking a key as security events', function (): void {
    $made = makeApiKey($this->admin, $this->writer);

    app(RevokeApiKey::class)->execute(new RevokeApiKeyInput($made['key']->id, (string) $this->admin->id));

    expect(DB::table('security_events')->where('type', 'api_key.created')->count())->toBe(1)
        ->and(DB::table('security_events')->where('type', 'api_key.revoked')->count())->toBe(1)
        ->and(DB::table('api_keys')->whereNotNull('revoked_at')->count())->toBe(1)
        ->and(DB::table('security_events')->pluck('context')->implode(' '))->not->toContain($made['secret']);
});

it('refuses an editor and writes nothing', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => makeApiKey($editor, $this->writer))->toThrow(AuthorizationException::class)
        ->and(DB::table('api_keys')->count())->toBe(0)
        ->and(DB::table('security_events')->where('type', 'api_key.created')->count())->toBe(0);
});

it('refuses an administrator without a fresh password and writes nothing', function (): void {
    $this->app->instance(Elevation::class, new DeniedElevation);

    expect(fn () => makeApiKey($this->admin, $this->writer))->toThrow(ElevationRequiredException::class)
        ->and(DB::table('api_keys')->count())->toBe(0);
});

it('refuses an ability not in the list, a past expiry, an unknown person and a name a live key has, writing nothing', function (array $abilities, ?string $expiresAt, bool $unknownPerson, string $name): void {
    makeApiKey($this->admin, $this->writer, name: 'Taken');

    $person = $unknownPerson ? '999999' : $this->writer;

    expect(fn () => makeApiKey($this->admin, $person, $abilities, $expiresAt, $name))->toThrow(ValidationException::class)
        ->and(DB::table('api_keys')->count())->toBe(1);
})->with([
    'an ability not in the list' => [['site.read', 'users.delete'], null, false, 'Other'],
    'no ability' => [[], null, false, 'Other'],
    'a past expiry' => [['site.read'], '2020-01-01T00:00:00Z', false, 'Other'],
    'an unknown person' => [['site.read'], null, true, 'Other'],
    'a name a live key has' => [['site.read'], null, false, 'Taken'],
]);

it('refuses to revoke for an editor and changes nothing', function (): void {
    $made = makeApiKey($this->admin, $this->writer);
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => app(RevokeApiKey::class)->execute(new RevokeApiKeyInput($made['key']->id, (string) $editor->id)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('api_keys')->whereNull('revoked_at')->count())->toBe(1)
        ->and(DB::table('security_events')->where('type', 'api_key.revoked')->count())->toBe(0);
});

it('lets a revoked key’s name be used again', function (): void {
    $made = makeApiKey($this->admin, $this->writer);
    app(RevokeApiKey::class)->execute(new RevokeApiKeyInput($made['key']->id, (string) $this->admin->id));

    makeApiKey($this->admin, $this->writer);

    expect(DB::table('api_keys')->count())->toBe(2);
});

it('deletes a person’s keys with the person', function (): void {
    makeApiKey($this->admin, $this->writer);

    DB::table('users')->where('id', $this->writer->id)->delete();

    expect(DB::table('api_keys')->count())->toBe(0);
});

it('gives administrators api.keys.manage on an existing site, adding only', function (): void {
    Permission::query()->where('name', 'api.keys.manage')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Schema::drop('api_keys');

    (require database_path('migrations/2027_06_23_100000_create_api_keys_table.php'))->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Role::findByName('administrator')->hasPermissionTo('api.keys.manage'))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo('api.keys.manage'))->toBeFalse()
        ->and(RoleSeeder::PERMISSIONS)->toContain('api.keys.manage');
});
