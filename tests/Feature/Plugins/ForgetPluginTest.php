<?php

declare(strict_types=1);

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\ForgetPlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\ForgetPluginInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);

    $this->enable = function (string $name): string {
        $plugin = app(PluginRegistry::class)->find($name);
        $manifest = $plugin?->manifest;

        return app(EnablePlugin::class)->execute(new EnablePluginInput(
            $name,
            $manifest?->version() ?? '1.0.0',
            $manifest?->capabilities() ?? [],
            $manifest?->hosts() ?? [],
            $manifest?->hooks() ?? [],
            null,
        ));
    };
});

afterEach(fn () => PluginFixtures::forget());

it('forgets a disabled plugin whose package is gone so a renamed package with the same code enables', function (): void {
    $old = PluginFixtures::copy('hello');
    PluginFixtures::use($old);
    ($this->enable)('tests/hello');
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/hello', null));

    (new Filesystem)->deleteDirectory($old);
    PluginFixtures::use();
    app(PluginRegistry::class)->flush();

    expect(app(PluginRegistry::class)->find('tests/hello'))->toBeNull()
        ->and(PluginRecord::query()->where('name', 'tests/hello')->exists())->toBeTrue();

    $renamed = PluginFixtures::copy('hello', ['name' => 'tests/hello-renamed'], ['name' => 'tests/hello-renamed']);
    PluginFixtures::use($renamed);

    expect(fn () => ($this->enable)('tests/hello-renamed'))
        ->toThrow(ValidationException::class, 'already used by tests/hello');

    app(ForgetPlugin::class)->execute(new ForgetPluginInput('tests/hello', null));

    expect(PluginRecord::query()->where('name', 'tests/hello')->exists())->toBeFalse()
        ->and(DB::table('security_events')->where('type', 'plugin.forgotten')->count())->toBe(1);

    ($this->enable)('tests/hello-renamed');

    expect(PluginRecord::query()->where('name', 'tests/hello-renamed')->sole()->state)->toBe(PluginRecord::ENABLED);
});

it('refuses ForgetPlugin while the plugin is enabled, writing nothing', function (): void {
    $copy = PluginFixtures::copy('hello');
    PluginFixtures::use($copy);
    ($this->enable)('tests/hello');

    (new Filesystem)->deleteDirectory($copy);
    PluginFixtures::use();
    app(PluginRegistry::class)->flush();

    expect(fn () => app(ForgetPlugin::class)->execute(new ForgetPluginInput('tests/hello', null)))
        ->toThrow(ValidationException::class);

    expect(PluginRecord::query()->where('name', 'tests/hello')->exists())->toBeTrue()
        ->and(DB::table('security_events')->where('type', 'plugin.forgotten')->count())->toBe(0);
});

it('refuses ForgetPlugin while the package is still present, writing nothing', function (): void {
    PluginFixtures::use('hello');
    ($this->enable)('tests/hello');
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/hello', null));

    expect(fn () => app(ForgetPlugin::class)->execute(new ForgetPluginInput('tests/hello', null)))
        ->toThrow(ValidationException::class);

    expect(PluginRecord::query()->where('name', 'tests/hello')->exists())->toBeTrue()
        ->and(DB::table('security_events')->where('type', 'plugin.forgotten')->count())->toBe(0);
});

it('writes nothing when someone without plugins.manage tries to forget a plugin', function (?string $role): void {
    $copy = PluginFixtures::copy('hello');
    PluginFixtures::use($copy);
    ($this->enable)('tests/hello');
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/hello', null));
    (new Filesystem)->deleteDirectory($copy);
    PluginFixtures::use();
    app(PluginRegistry::class)->flush();

    $events = DB::table('security_events')->where('type', 'plugin.forgotten')->count();
    $actorId = $role === null ? null : (string) User::factory()->create()->assignRole($role)->id;
    $role === null && app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => app(ForgetPlugin::class)->execute(new ForgetPluginInput('tests/hello', $actorId)))
        ->toThrow(AuthorizationException::class);

    expect(PluginRecord::query()->where('name', 'tests/hello')->exists())->toBeTrue()
        ->and(DB::table('security_events')->where('type', 'plugin.forgotten')->count())->toBe($events);
})->with(['editor', 'author', 'nobody at the shell' => null]);

it('lists and asks unless --force on teme:plugins:forget', function (): void {
    $copy = PluginFixtures::copy('hello');
    PluginFixtures::use($copy);
    ($this->enable)('tests/hello');
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/hello', null));
    (new Filesystem)->deleteDirectory($copy);
    PluginFixtures::use();
    app(PluginRegistry::class)->flush();

    $this->artisan('teme:plugins:forget', ['name' => 'tests/hello'])
        ->expectsConfirmation('Forget this plugin’s record?', 'no')
        ->assertSuccessful();

    expect(PluginRecord::query()->where('name', 'tests/hello')->exists())->toBeTrue();

    $this->artisan('teme:plugins:forget', ['name' => 'tests/hello', '--force' => true])
        ->assertSuccessful();

    expect(PluginRecord::query()->where('name', 'tests/hello')->exists())->toBeFalse();
});

it('offers Forget only for a disabled plugin whose package is gone', function (): void {
    $this->admin = User::factory()->create()->assignRole('administrator');
    $rows = fn (): array => collect($this->actingAs($this->admin)->get('/admin/plugins')->assertOk()->viewData('page')['props']['plugins'])->keyBy('name')->all();

    $copy = PluginFixtures::copy('hello');
    PluginFixtures::use($copy);
    ($this->enable)('tests/hello');

    expect(($rows)()['tests/hello']['canForget'])->toBeFalse();

    app()->instance(Elevation::class, new OperatorElevation);
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/hello', null));

    expect(($rows)()['tests/hello']['canForget'])->toBeFalse();

    (new Filesystem)->deleteDirectory($copy);
    PluginFixtures::use();
    app(PluginRegistry::class)->flush();

    PluginRecord::factory()->disabled()->create(['name' => 'tests/gone', 'code' => 'gone']);

    $listed = ($rows)();

    expect($listed['tests/hello']['canForget'])->toBeTrue()
        ->and($listed['tests/gone']['canForget'])->toBeTrue();
});
