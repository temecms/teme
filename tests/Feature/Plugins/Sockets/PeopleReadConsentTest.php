<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
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
use App\Domain\Plugins\PluginWords;
use App\Domain\Plugins\Seams\DispatcherSubscriptions;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Users\Operations\Input\InviteUserInput;
use App\Domain\Users\Operations\InviteUser;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\People\PeopleReader;
use Teme\Contracts\People\PersonInvited;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    PluginFixtures::use('scheduler');
    $this->manifest = PluginFixtures::manifest('scheduler');
    $this->oldConsent = [
        'capabilities' => $this->manifest['capabilities'],
        'hosts' => [],
        'hooks' => [],
        'routes' => [],
    ];
});

afterEach(function (): void {
    PluginFixtures::forget();
    app(CurrentJobActor::class)->clear();
});

/**
 * An older people.read grant: capability agreed, no wider stamp.
 */
function peopleReadOldGrant(array $manifest, array $consent): void
{
    PluginRecord::factory()->enabled()->create([
        'name' => 'tests/scheduler',
        'code' => 'scheduler',
        'version' => $manifest['version'],
        'consent' => $consent,
    ]);
    app(PluginRecords::class)->changed();
    app(PluginLoader::class)->load();
}

it('keeps an older people.read grant running: jobs and PeopleReader work, with no owner name or invite events, and the Plugins row asks again', function (): void {
    peopleReadOldGrant($this->manifest, $this->oldConsent);
    $found = app(PluginRegistry::class)->find('tests/scheduler');
    $consent = PluginRecord::query()->where('name', 'tests/scheduler')->sole()->consent;
    $heard = [];
    $hooks = app(DispatcherSubscriptions::class)->for('tests/scheduler', $this->manifest['capabilities'], ['PersonInvited'], []);
    $hooks->on(PersonInvited::class, function () use (&$heard): void {
        $heard[] = true;
    });
    app(HookDispatcher::class); // keep subscriptions alive for the invite
    $admin = User::factory()->create()->assignRole('administrator');
    $this->withElevation();
    app(InviteUser::class)->execute(new InviteUserInput(
        name: 'Ada Harbour',
        email: 'ada@harbour.example',
        role: 'editor',
        actorId: (string) $admin->id,
        deliver: false,
    ));

    $owner = User::factory()->create(['name' => 'Ada Harbour'])->assignRole('editor');
    $item = addrPublished($owner, 'Harbour notes');
    app(CurrentJobActor::class)->set(JobActor::person($owner->id));
    $reader = app(PluginDoors::class)
        ->for('tests/scheduler', 'scheduler', $this->manifest['capabilities'], [])[ContentReader::class];
    $adminViewer = User::factory()->create()->assignRole('administrator');
    $row = collect($this->actingAs($adminViewer)->get('/admin/plugins')->assertOk()->viewData('page')['props']['plugins'])->keyBy('name')['tests/scheduler'];

    expect(Consent::covers($consent, $found->manifest))->toBeTrue()
        ->and(Consent::coversWiderPeopleRead($consent))->toBeFalse()
        ->and(Consent::added($consent, $found->manifest)['capabilities'])->toContain('people.read')
        ->and(app(LoadedPlugins::class)->state('tests/scheduler'))->toBe(PluginState::Enabled)
        ->and(app(LoadedPlugins::class)->running())->toHaveKey('tests/scheduler')
        ->and(app(LoadedPlugins::class)->running()['tests/scheduler']->people)->toBeInstanceOf(PeopleReader::class)
        ->and($reader->draft($item->id, (string) $owner->id)->ownerName)->toBeNull()
        ->and($heard)->toBe([])
        ->and($row['asksMore'])->toBeTrue()
        ->and(collect($row['asks'])->contains(fn (array $line): bool => $line['new'] === true && $line['words'] === PluginWords::capability('people.read')))->toBeTrue();
});

it('gives the owner’s name and invite events after someone agrees again to the wider people.read', function (): void {
    peopleReadOldGrant($this->manifest, $this->oldConsent);
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

    $heard = [];
    $hooks = app(DispatcherSubscriptions::class)->for('tests/scheduler', $this->manifest['capabilities'], ['PersonInvited'], []);
    $hooks->on(PersonInvited::class, function () use (&$heard): void {
        $heard[] = true;
    });

    $admin = User::factory()->create()->assignRole('administrator');
    $this->withElevation();
    app(InviteUser::class)->execute(new InviteUserInput(
        name: 'Ada Harbour',
        email: 'ada@harbour.example',
        role: 'editor',
        actorId: (string) $admin->id,
        deliver: false,
    ));

    $owner = User::factory()->create(['name' => 'Ada Harbour'])->assignRole('editor');
    $item = addrPublished($owner, 'Harbour notes');
    app(CurrentJobActor::class)->set(JobActor::person($owner->id));
    $reader = app(PluginDoors::class)
        ->for('tests/scheduler', 'scheduler', $this->manifest['capabilities'], [])[ContentReader::class];

    expect(PluginRecord::query()->where('name', 'tests/scheduler')->sole()->consent['peopleRead'])->toBeTrue()
        ->and(Consent::coversWiderPeopleRead(PluginRecord::query()->where('name', 'tests/scheduler')->sole()->consent))->toBeTrue()
        ->and(app(LoadedPlugins::class)->state('tests/scheduler'))->toBe(PluginState::Enabled)
        ->and($reader->draft($item->id, (string) $owner->id)->ownerName)->toBe('Ada Harbour')
        ->and($heard)->toHaveCount(1);
});

it('records peopleRead on a fresh grant so owner names and invite events start at once', function (): void {
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

    $heard = [];
    $hooks = app(DispatcherSubscriptions::class)->for('tests/scheduler', $this->manifest['capabilities'], ['PersonInvited'], []);
    $hooks->on(PersonInvited::class, function () use (&$heard): void {
        $heard[] = true;
    });
    $admin = User::factory()->create()->assignRole('administrator');
    $this->withElevation();
    app(InviteUser::class)->execute(new InviteUserInput(
        name: 'Ada Harbour',
        email: 'ada@harbour.example',
        role: 'editor',
        actorId: (string) $admin->id,
        deliver: false,
    ));

    expect($consent['peopleRead'])->toBeTrue()
        ->and(Consent::covers($consent, app(PluginRegistry::class)->find('tests/scheduler')->manifest))->toBeTrue()
        ->and(Consent::coversWiderPeopleRead($consent))->toBeTrue()
        ->and(app(LoadedPlugins::class)->state('tests/scheduler'))->toBe(PluginState::Enabled)
        ->and($heard)->toHaveCount(1);
});
