<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Status\Checks\PluginsRunning;
use App\Domain\Status\StatusBanner;
use App\Domain\Status\StatusChecks;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PluginFixtures::use('hello', 'widened');

    // A row as EnablePlugin leaves it, agreeing to what the fixture asks now.
    $this->enabled = fn (string $folder, array $changes = []): PluginRecord => PluginRecord::factory()->enabled()->create([
        'name' => 'tests/'.$folder,
        'code' => PluginFixtures::manifest($folder)['code'],
        'version' => PluginFixtures::manifest($folder)['version'],
        'consent' => [
            'capabilities' => PluginFixtures::manifest($folder)['capabilities'] ?? [],
            'hosts' => PluginFixtures::manifest($folder)['hosts'] ?? [],
            'hooks' => PluginFixtures::manifest($folder)['hooks'] ?? [],
        ],
        ...$changes,
    ]);
    $this->banner = fn (): ?StatusBanner => app(PluginsRunning::class)->banner();
});

afterEach(fn () => PluginFixtures::forget());

it('raises an error naming why one enabled plugin can’t run', function (Closure $arrange, string $label, string $reason): void {
    $arrange->call($this);

    expect(($this->banner)())->toEqual(new StatusBanner('error', "{$label} can’t run: {$reason}", 'Open Plugins', route('plugins.index'), 70));
})->with([
    'its code names App' => [function (): void {
        $copy = PluginFixtures::copy('hello', files: ['src/Reach.php' => "<?php\n\nnamespace Tests\\Fixtures\\Plugins\\Hello;\n\nuse App\\Models\\User;\n"]);
        PluginFixtures::use($copy);
        ($this->enabled)('hello');
    }, 'Hello', 'It uses Teme’s own code, which plugins may not use: App\\Models\\User in src/Reach.php, line 5.'],
    'incompatible' => [function (): void {
        PluginFixtures::use(PluginFixtures::copy('hello', ['requires' => ['teme' => '^0.6']]));
        ($this->enabled)('hello');
    }, 'Hello', 'It needs Teme ^0.6; this is Teme 0.5.0.'],
    'its code gone' => [function (): void {
        ($this->enabled)('hello', ['name' => 'tests/gone', 'code' => 'gone']);
    }, 'tests/gone', 'Its code is no longer installed. Its settings are kept.'],
    'waiting' => [function (): void {
        PluginFixtures::use(PluginFixtures::copy('widened', ['version' => '1.1.0', 'capabilities' => ['content.read', 'http', 'media.read']]));
        ($this->enabled)('widened');
    }, 'Widened', 'Update 1.1.0 is blocked: it asks for more access than you agreed to.'],
    'failed to start' => [function (): void {
        ($this->enabled)('hello');
        app(LoadedPlugins::class)->failed('tests/hello', 'The broken plugin broke.');
    }, 'Hello', 'It failed to start: The broken plugin broke.'],
]);

it('counts only enabled plugins that can’t run, never one Teme has disabled', function (): void {
    ($this->enabled)('hello', ['name' => 'tests/gone', 'code' => 'gone']);
    ($this->enabled)('hello', ['name' => 'tests/also-gone', 'code' => 'also-gone']);
    PluginRecord::factory()->stopped()->create(['name' => 'tests/hello', 'code' => 'hello']);

    expect(($this->banner)())->toEqual(new StatusBanner('error', '2 enabled plugins can’t run.', 'Open Plugins', route('plugins.index'), 70));
});

it('says when Teme has disabled a plugin, once nothing enabled is broken', function (): void {
    ($this->enabled)('hello');
    PluginRecord::factory()->stopped()->create(['name' => 'tests/widened', 'code' => 'widened', 'faults' => ['tests/widened#boot' => ['count' => 5, 'first' => 1, 'last' => 2, 'error' => 'RuntimeException: no']]]);

    expect(($this->banner)())->toEqual(new StatusBanner('warning', 'Teme disabled Widened after it failed to start five times.', 'Open Plugins', route('plugins.index'), 50));

    PluginRecord::factory()->stopped()->create(['name' => 'tests/gone', 'code' => 'gone']);

    expect(($this->banner)())->toEqual(new StatusBanner('warning', 'Teme disabled 2 plugins after they failed to start.', 'Open Plugins', route('plugins.index'), 50));
});

it('warns when an enabled plugin has a stopped part', function (): void {
    ($this->enabled)('hello', ['quarantine' => [
        'tests/hello#ContentPublished#1' => ['version' => '1.0.0', 'error' => 'RuntimeException: no', 'at' => 1],
    ]]);

    expect(($this->banner)())->toEqual(new StatusBanner('warning', 'Hello has stopped part of its work after repeated errors.', 'Open Plugins', route('plugins.index'), 40));
});

it('says nothing for a plugin never enabled, disabled by a person, or disabled on the server', function (): void {
    PluginRecord::factory()->disabled()->create(['name' => 'tests/widened', 'code' => 'widened']);
    ($this->enabled)('hello', ['name' => 'tests/gone', 'code' => 'gone']);
    config(['teme.plugins.disabled' => 'tests/gone']);

    expect(($this->banner)())->toBeNull();

    // A stopped part from an older version is lifted by the update.
    ($this->enabled)('hello', ['quarantine' => ['tests/hello#ContentPublished#1' => ['version' => '0.9.0', 'error' => 'RuntimeException: no', 'at' => 1]]]);

    expect(($this->banner)())->toBeNull();
});

it('shows the banner only to people who hold plugins.manage', function (): void {
    $this->seed(RoleSeeder::class);
    ($this->enabled)('hello', ['name' => 'tests/gone', 'code' => 'gone']);
    $checks = new StatusChecks;
    $checks->register(PluginsRunning::class);
    app()->instance(StatusChecks::class, $checks);

    expect(app(PluginsRunning::class)->ability())->toBe('plugins.manage')
        ->and($checks->forUser(User::factory()->create()->assignRole('administrator'))?->message)->toBe('tests/gone can’t run: Its code is no longer installed. Its settings are kept.')
        ->and($checks->forUser(User::factory()->create()->assignRole('editor')))->toBeNull();
});
