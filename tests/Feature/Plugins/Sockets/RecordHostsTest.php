<?php

declare(strict_types=1);

use App\Domain\Plugins\Doors\HostResolver;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\AgreePluginHosts;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\AgreePluginHostsInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\RemoveRecordHostInput;
use App\Domain\Plugins\Operations\RemoveRecordHost;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\RecordHosts;
use App\Domain\Plugins\SettingHosts;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Plugins\Lists\FakeResolver;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

// A host a plugin's record names is waiting until someone who manages plugins
// agrees to it, after their password; an agreed one stays until a manager
// removes it, and no one else can agree or remove. The plugin never reaches a
// waiting host.

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    PluginFixtures::use('hub');
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(HostResolver::class, FakeResolver::answering([
        'api.example.com' => ['93.184.215.14'],
        'hub.example.com' => ['93.184.215.15'],
        'hub.example.net' => ['93.184.215.16'],
    ]));

    $manifest = PluginFixtures::manifest('hub');
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        name: 'tests/hub',
        version: $manifest['version'],
        capabilities: $manifest['capabilities'],
        hosts: $manifest['hosts'],
        hooks: [],
        actorId: null,
        settingHosts: [],
    ));
    app(PluginLoader::class)->load();

    // The hosts a record named when it was saved, as the save operation records them.
    $this->named = fn (string $host = 'reports.example.org'): array => RecordHosts::noteSaved('tests/hub', '01JRECORDAAAAAAAAAAAAAAAAA', 'Sponsor banner', [$host]);
    $this->agree = fn (string $host, ?User $as = null): array => app(AgreePluginHosts::class)->execute(new AgreePluginHostsInput('tests/hub', [$host], (string) ($as ?? $this->admin)->id, 'records'));
    $this->consent = fn (): array => PluginRecord::query()->where('name', 'tests/hub')->sole()->consent;
});

afterEach(fn () => PluginFixtures::forget());

it('keeps a host a record names as waiting, and the plugin does not reach it', function (): void {
    expect(($this->named)())->toBe(['reports.example.org'])
        ->and(RecordHosts::waiting(($this->consent)()))->toBe(['reports.example.org'])
        ->and(app(SettingHosts::class)->reachable('tests/hub'))->not->toContain('reports.example.org');
});

it('lets someone who manages plugins agree, then the plugin reaches that host and no other', function (): void {
    ($this->named)();

    expect(($this->agree)('reports.example.org'))->toBe(['reports.example.org'])
        ->and(RecordHosts::waiting(($this->consent)()))->toBe([])
        ->and(app(SettingHosts::class)->reachable('tests/hub'))->toBe(['reports.example.org']);
});

it('writes nothing when someone without plugin management agrees, even with the area permission', function (): void {
    ($this->named)();

    expect(fn () => ($this->agree)('reports.example.org', $this->editor))->toThrow(AuthorizationException::class)
        ->and(RecordHosts::agreed(($this->consent)()))->toBe([]);
});

it('agrees nothing without the password step', function (): void {
    ($this->named)();
    app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => ($this->agree)('reports.example.org'))->toThrow(ElevationRequiredException::class)
        ->and(RecordHosts::agreed(($this->consent)()))->toBe([])
        ->and(app(SettingHosts::class)->reachable('tests/hub'))->toBe([]);
});

it('refuses to agree a host no record named, writing nothing', function (): void {
    ($this->named)();

    expect(fn () => ($this->agree)('elsewhere.example.org'))->toThrow(ValidationException::class)
        ->and(RecordHosts::agreed(($this->consent)()))->toBe([]);
});

it('keeps an agreed host until a manager removes it, then it waits again, and removal needs no password', function (): void {
    ($this->named)();
    ($this->agree)('reports.example.org');
    app()->instance(Elevation::class, new DeniedElevation);

    $remove = fn (?User $as = null) => app(RemoveRecordHost::class)->execute(new RemoveRecordHostInput('tests/hub', 'reports.example.org', (string) ($as ?? $this->admin)->id));

    expect(array_column(RecordHosts::named(($this->consent)()), 'host'))->toBe(['reports.example.org'])
        ->and(RecordHosts::agreed(($this->consent)()))->toBe(['reports.example.org']);

    expect(fn () => $remove($this->editor))->toThrow(AuthorizationException::class)
        ->and(RecordHosts::agreed(($this->consent)()))->toBe(['reports.example.org']);

    expect($remove())->toBe([])
        ->and(RecordHosts::waiting(($this->consent)()))->toBe(['reports.example.org'])
        ->and(app(SettingHosts::class)->reachable('tests/hub'))->not->toContain('reports.example.org');
});

it('refuses to remove a host that is not agreed for records', function (): void {
    expect(fn () => app(RemoveRecordHost::class)->execute(new RemoveRecordHostInput('tests/hub', 'nowhere.example.org', (string) $this->admin->id)))->toThrow(ValidationException::class);
});
