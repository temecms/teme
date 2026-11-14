<?php

declare(strict_types=1);

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    PluginFixtures::use('hello', 'widened');
    app()->instance(Elevation::class, new OperatorElevation);

    // As the manifest asks now: what the consent dialog shows.
    $this->input = fn (string $folder = 'hello', ?string $actorId = null, array $changes = []): EnablePluginInput => new EnablePluginInput(...[
        'name' => 'tests/'.$folder,
        'version' => PluginFixtures::manifest($folder)['version'],
        'capabilities' => PluginFixtures::manifest($folder)['capabilities'] ?? [],
        'hosts' => PluginFixtures::manifest($folder)['hosts'] ?? [],
        'hooks' => PluginFixtures::manifest($folder)['hooks'] ?? [],
        'actorId' => $actorId,
        ...$changes,
    ]);
    $this->events = fn (string $type): int => DB::table('security_events')->where('type', $type)->count();
});

afterEach(fn () => PluginFixtures::forget());

it('enables a plugin for the operator, recording what was agreed, for which version, by whom', function (): void {
    $name = app(EnablePlugin::class)->execute(($this->input)());
    $record = PluginRecord::query()->where('name', 'tests/hello')->sole();

    expect($name)->toBe('tests/hello')
        ->and($record->state)->toBe('enabled')
        ->and($record->disabled_reason)->toBeNull()
        ->and($record->code)->toBe('hello')
        ->and($record->version)->toBe('1.0.0')
        ->and($record->consent)->toEqualCanonicalizing(['capabilities' => ['content.read'], 'hosts' => [], 'hooks' => ['ContentPublished']])
        ->and($record->consented_by)->toBeNull()
        ->and($record->consented_at)->not->toBeNull()
        ->and($record->enabled_at)->not->toBeNull()
        ->and(($this->events)('plugin.enabled'))->toBe(1);
});

it('enables a plugin for an administrator after their password, and asks for it when it wasn’t confirmed', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => app(EnablePlugin::class)->execute(($this->input)('hello', (string) $admin->id)))->toThrow(ElevationRequiredException::class);
    expect(PluginRecord::query()->count())->toBe(0);

    app()->instance(Elevation::class, new class implements Elevation
    {
        public function ensure(OperationInput $input): void {}

        public function isOperator(): bool
        {
            return false;
        }
    });
    app(EnablePlugin::class)->execute(($this->input)('hello', (string) $admin->id));

    expect(PluginRecord::query()->sole()->consented_by)->toBe($admin->id);
});

it('writes nothing when someone without plugins.manage tries to enable a plugin', function (?string $role): void {
    $actorId = $role === null ? null : (string) User::factory()->create()->assignRole($role)->id;
    $role === null && app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => app(EnablePlugin::class)->execute(($this->input)('hello', $actorId)))->toThrow(AuthorizationException::class);
    expect(PluginRecord::query()->count())->toBe(0)
        ->and(($this->events)('plugin.enabled'))->toBe(0);
})->with(['editor', 'author', 'nobody at the shell' => null]);

it('refuses a plugin whose code names App, even when the cache says it doesn’t', function (): void {
    $copy = PluginFixtures::copy('hello');
    PluginFixtures::installed(['tests/hello' => $copy]);
    PluginFixtures::use();
    $cache = storage_path('framework/testing/teme-plugins-'.bin2hex(random_bytes(6)).'.php');
    config(['teme.plugin_cache' => $cache]);
    Artisan::call('teme:plugins:cache');

    file_put_contents($copy.'/src/Reaches.php', "<?php\n\nnamespace Tests\\Fixtures\\Plugins\\Hello;\n\nuse App\\Models\\User;\n");
    app(PluginRegistry::class)->flush();

    expect(app(PluginRegistry::class)->find('tests/hello')?->usable())->toBeTrue();
    expect(fn () => app(EnablePlugin::class)->execute(($this->input)()))
        ->toThrow(ValidationException::class, 'It uses Teme’s own code, which plugins may not use: App\\Models\\User in src/Reaches.php, line 5.');
    expect(PluginRecord::query()->count())->toBe(0);

    unlink($cache);
});

it('refuses a plugin that can’t be used, with its first problem', function (): void {
    config(['teme.version' => '0.4.0']);

    expect(fn () => app(EnablePlugin::class)->execute(($this->input)()))
        ->toThrow(ValidationException::class, 'The plugin requires Teme ^0.5, but this is Teme 0.4.0.');
    expect(PluginRecord::query()->count())->toBe(0);
});

it('refuses consent that differs from what the plugin asks for now', function (array $changes): void {
    expect(fn () => app(EnablePlugin::class)->execute(($this->input)('widened', null, $changes)))
        ->toThrow(ValidationException::class, 'It asks for something different now. Look again before you enable it.');
    expect(PluginRecord::query()->count())->toBe(0);
})->with([
    'without http' => [['capabilities' => ['content.read']]],
    'another host' => [['hosts' => ['*.example.com']]],
    'another version' => [['version' => '0.9.0']],
    'another hook' => [['hooks' => ['ContentPublished', 'ContentTrashed']]],
]);

it('takes the lists in any order, as sets', function (): void {
    app(EnablePlugin::class)->execute(($this->input)('widened', null, ['capabilities' => ['http', 'content.read']]));

    expect(PluginRecord::query()->sole()->state)->toBe('enabled');
});

it('refuses a plugin that isn’t installed', function (): void {
    expect(fn () => app(EnablePlugin::class)->execute(($this->input)('hello', null, ['name' => 'tests/gone'])))
        ->toThrow(ValidationException::class, 'No plugin is named "tests/gone".');
});

it('enables a disabled plugin again and clears its faults and stopped parts', function (): void {
    PluginRecord::factory()->disabled()->create([
        'name' => 'tests/hello', 'code' => 'hello', 'version' => '0.9.0',
        'faults' => ['tests/hello#ContentPublished#1' => ['hook' => 'ContentPublished', 'count' => 3]],
        'quarantine' => ['tests/hello#ContentPublished#1' => ['hook' => 'ContentPublished', 'version' => '0.9.0']],
    ]);

    app(EnablePlugin::class)->execute(($this->input)());
    $record = PluginRecord::query()->sole();

    expect($record->state)->toBe('enabled')
        ->and($record->disabled_reason)->toBeNull()
        ->and($record->disabled_at)->toBeNull()
        ->and($record->disabled_by)->toBeNull()
        ->and($record->version)->toBe('1.0.0')
        ->and($record->faults)->toBe([])
        ->and($record->quarantine)->toBe([]);
});

it('forgets the enabled list and tells the workers to restart, after the commit only', function (): void {
    Cache::forever(PluginRecords::CACHE_KEY, ['held']);
    Cache::forget('illuminate:queue:restart');

    DB::beginTransaction();
    app(EnablePlugin::class)->execute(($this->input)());

    expect(Cache::get(PluginRecords::CACHE_KEY))->toBe(['held'])
        ->and(Cache::has('illuminate:queue:restart'))->toBeFalse();

    DB::rollBack();

    expect(Cache::get(PluginRecords::CACHE_KEY))->toBe(['held'])
        ->and(Cache::has('illuminate:queue:restart'))->toBeFalse()
        ->and(PluginRecord::query()->count())->toBe(0);

    app(EnablePlugin::class)->execute(($this->input)());

    expect(Cache::has(PluginRecords::CACHE_KEY))->toBeFalse()
        ->and(Cache::get('illuminate:queue:restart'))->toBeInt();
});
