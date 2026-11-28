<?php

declare(strict_types=1);

use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Doors\CurrentJobActor;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\PluginState;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Content\ContentReader;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    PluginFixtures::use('scheduler');
    $this->manifest = PluginFixtures::manifest('scheduler');
});

afterEach(function (): void {
    PluginFixtures::forget();
    app(CurrentJobActor::class)->clear();
});

it('asks again for an older people.read grant, so the plugin waits with no owner name until someone agrees', function (): void {
    PluginRecord::factory()->enabled()->create([
        'name' => 'tests/scheduler',
        'code' => 'scheduler',
        'version' => $this->manifest['version'],
        'consent' => [
            'capabilities' => $this->manifest['capabilities'],
            'hosts' => [],
            'hooks' => [],
            'routes' => [],
        ],
    ]);
    app(PluginRecords::class)->changed();
    app(PluginLoader::class)->load();
    $found = app(PluginRegistry::class)->find('tests/scheduler');
    $consent = PluginRecord::query()->where('name', 'tests/scheduler')->sole()->consent;

    expect(Consent::covers($consent, $found->manifest))->toBeFalse()
        ->and(Consent::added($consent, $found->manifest)['capabilities'])->toContain('people.read')
        ->and(app(LoadedPlugins::class)->state('tests/scheduler'))->toBe(PluginState::Waiting)
        ->and(app(LoadedPlugins::class)->running())->not->toHaveKey('tests/scheduler');
});

it('gives the owner’s name after someone agrees again to the wider people.read', function (): void {
    PluginRecord::factory()->enabled()->create([
        'name' => 'tests/scheduler',
        'code' => 'scheduler',
        'version' => $this->manifest['version'],
        'consent' => [
            'capabilities' => $this->manifest['capabilities'],
            'hosts' => [],
            'hooks' => [],
            'routes' => [],
        ],
    ]);
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/scheduler',
        $this->manifest['version'],
        $this->manifest['capabilities'],
        [],
        [],
        null,
    ));
    app()->forgetScopedInstances();
    app(PluginLoader::class)->load();

    $owner = User::factory()->create(['name' => 'Ada Harbour'])->assignRole('editor');
    $item = addrPublished($owner, 'Harbour notes');
    app(CurrentJobActor::class)->set(JobActor::person($owner->id));
    $plugin = app(LoadedPlugins::class)->running()['tests/scheduler'] ?? null;

    expect(PluginRecord::query()->where('name', 'tests/scheduler')->sole()->consent['peopleRead'])->toBeTrue()
        ->and(app(LoadedPlugins::class)->state('tests/scheduler'))->toBe(PluginState::Enabled)
        ->and($plugin)->not->toBeNull();

    $reader = app(PluginDoors::class)
        ->for('tests/scheduler', 'scheduler', $this->manifest['capabilities'], [])[ContentReader::class];

    expect($reader->draft($item->id, (string) $owner->id)->ownerName)->toBe('Ada Harbour');
});

it('records peopleRead on a fresh grant so people.read starts at once', function (): void {
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/scheduler',
        $this->manifest['version'],
        $this->manifest['capabilities'],
        [],
        [],
        null,
    ));
    app(PluginLoader::class)->load();
    $consent = PluginRecord::query()->where('name', 'tests/scheduler')->sole()->consent;

    expect($consent['peopleRead'])->toBeTrue()
        ->and(Consent::covers($consent, app(PluginRegistry::class)->find('tests/scheduler')->manifest))->toBeTrue()
        ->and(app(LoadedPlugins::class)->state('tests/scheduler'))->toBe(PluginState::Enabled);
});
