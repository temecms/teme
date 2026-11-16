<?php

declare(strict_types=1);

use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Doors\HostResolver;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\AgreePluginHosts;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\AgreePluginHostsInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Http\HostRefused;
use Tests\Fixtures\Plugins\Lists\FakeResolver;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

/*
 * Hosts a plugin reaches because a person entered them in its settings
 * (docs/plans/plugin-sockets-1.md, A2): agreed by a person at enable or on
 * the settings save, never by the plugin, and reachable only while the
 * setting still names them.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    PluginFixtures::use('hub');
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(HostResolver::class, FakeResolver::answering([
        'api.example.com' => ['93.184.215.14'],
        'hub.example.com' => ['93.184.215.15'],
        'hub.example.net' => ['93.184.215.16'],
    ]));
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('', 204)]);

    $this->enable = function (array $settingHosts = ['hub.example.com']): void {
        $manifest = PluginFixtures::manifest('hub');
        app(EnablePlugin::class)->execute(new EnablePluginInput(
            name: 'tests/hub',
            version: $manifest['version'],
            capabilities: $manifest['capabilities'],
            hosts: $manifest['hosts'],
            hooks: [],
            actorId: null,
            settingHosts: $settingHosts,
        ));
        app(PluginLoader::class)->load();
    };
    $this->door = fn () => app(LoadedPlugins::class)->running()['tests/hub']->http;
    $this->save = fn (array $values, array $agreedHosts = [], ?User $as = null) => app(UpdateSettings::class)->execute(new UpdateSettingsInput(
        group: 'plugin.hub',
        values: $values,
        reset: [],
        secrets: [],
        expectedVersion: (int) DB::table('settings')->where('group_key', 'plugin.hub')->value('version'),
        actorId: (string) ($as ?? $this->admin)->id,
        agreedHosts: $agreedHosts,
    ));
    $this->consent = fn (): array => PluginRecord::query()->where('name', 'tests/hub')->sole()->consent;
});

afterEach(fn () => PluginFixtures::forget());

it('refuses a manifest whose hostsFrom names anything but its own url or urls settings, or comes without http', function (array $changes, string $words): void {
    PluginFixtures::use(PluginFixtures::copy('hub', $changes));

    expect(app(PluginRegistry::class)->find('tests/hub')?->problems)->toContain($words);
})->with([
    'a text setting' => [['hostsFrom' => ['note']], 'hostsFrom names note, which is not one of its url or urls settings.'],
    'a setting it doesn’t declare' => [['hostsFrom' => ['nowhere']], 'hostsFrom names nowhere, which is not one of its url or urls settings.'],
    'no http' => [['capabilities' => [], 'hosts' => []], 'It takes hosts from its settings, but the plugin doesn’t ask for http.'],
    'more than three' => [['hostsFrom' => ['hub_url', 'more_hubs', 'hub_url', 'more_hubs']], 'hostsFrom must be a list of at most 3 of its url or urls settings.'],
]);

it('records the settings it takes hosts from, and the hosts agreed in them, when it is enabled', function (): void {
    ($this->enable)();

    expect(($this->consent)())->toMatchArray([
        'hostsFrom' => ['hub_url', 'more_hubs'],
        'settingHosts' => ['hub.example.com'],
    ]);
});

it('refuses to enable it when the setting hosts shown are not those its settings name now', function (): void {
    expect(fn () => ($this->enable)(['hub.example.net']))->toThrow(ValidationException::class, 'It asks for something different now. Look again before you enable it.');
    expect(PluginRecord::query()->count())->toBe(0);
});

it('reaches a host agreed in its settings, and refuses one entered there but never agreed', function (): void {
    ($this->enable)([]);

    expect(fn () => ($this->door)()->postForm('https://hub.example.com/', ['hub.mode' => 'publish']))->toThrow(HostRefused::class);

    PluginRecord::query()->where('name', 'tests/hub')->delete();
    ($this->enable)();

    expect(($this->door)()->postForm('https://hub.example.com/', ['hub.mode' => 'publish'])->status)->toBe(204)
        ->and(($this->door)()->get('https://api.example.com/')->status)->toBe(204);
    expect(fn () => ($this->door)()->get('https://hub.example.net/'))->toThrow(HostRefused::class);
});

it('stops reaching a host removed from the setting, and reaches it again when it is put back, without another yes', function (): void {
    ($this->enable)();

    ($this->save)(['hub_url' => 'https://api.example.com/hub']);
    expect(fn () => ($this->door)()->get('https://hub.example.com/'))->toThrow(HostRefused::class);

    ($this->save)(['hub_url' => 'https://hub.example.com/']);
    expect(($this->door)()->get('https://hub.example.com/')->status)->toBe(204);
});

it('refuses a save that enters a new host without agreeing to it, writing nothing', function (): void {
    ($this->enable)();
    $before = ($this->consent)();

    expect(fn () => ($this->save)(['more_hubs' => ['https://hub.example.net/']]))
        ->toThrow(ValidationException::class, 'Agree to let Hub connect to hub.example.net, or remove them.');
    expect(DB::table('settings')->where('group_key', 'plugin.hub')->exists())->toBeFalse()
        ->and(($this->consent)())->toBe($before);
});

it('asks for the password to agree to a new host, then saves it and records who agreed', function (): void {
    ($this->enable)();
    app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => ($this->save)(['more_hubs' => ['https://hub.example.net/']], ['hub.example.net']))->toThrow(ElevationRequiredException::class);
    expect(DB::table('settings')->where('group_key', 'plugin.hub')->exists())->toBeFalse();

    app()->instance(Elevation::class, new OperatorElevation);
    ($this->save)(['more_hubs' => ['https://hub.example.net/']], ['hub.example.net']);
    $consent = ($this->consent)();

    expect($consent['settingHosts'])->toBe(['hub.example.com', 'hub.example.net'])
        ->and($consent['settingHostsAgreed'])->toHaveCount(1)
        ->and($consent['settingHostsAgreed'][0])->toMatchArray(['hosts' => ['hub.example.net'], 'by' => $this->admin->id])
        ->and(($this->door)()->get('https://hub.example.net/')->status)->toBe(204);
});

it('saves a settings change that names no new host without asking for anything', function (): void {
    ($this->enable)();
    app()->instance(Elevation::class, new DeniedElevation);

    ($this->save)(['note' => 'hello', 'hub_url' => 'https://hub.example.com/']);

    expect(DB::table('settings')->where('group_key', 'plugin.hub')->exists())->toBeTrue();
});

it('writes nothing when someone without plugins.manage agrees to a plugin’s hosts', function (?string $role): void {
    ($this->enable)();
    $before = ($this->consent)();
    $actor = $role === null ? null : (string) User::factory()->create()->assignRole($role)->id;

    expect(fn () => app(AgreePluginHosts::class)->execute(new AgreePluginHostsInput('tests/hub', ['hub.example.net'], $actor)))
        ->toThrow(AuthorizationException::class);
    expect(($this->consent)())->toBe($before);
})->with(['editor', 'nobody' => null]);

it('waits for another yes when an update takes hosts from another setting', function (): void {
    ($this->enable)();
    $record = PluginRecord::query()->where('name', 'tests/hub')->sole();
    $narrower = new Manifest([...PluginFixtures::manifest('hub'), 'hostsFrom' => ['hub_url']]);
    $wider = new Manifest(PluginFixtures::manifest('hub'));

    expect(Consent::covers($record->consent, $wider))->toBeTrue()
        ->and(Consent::covers([...$record->consent, 'hostsFrom' => ['hub_url']], $narrower))->toBeTrue()
        ->and(Consent::covers([...$record->consent, 'hostsFrom' => ['hub_url']], $wider))->toBeFalse();
});

it('lists the hosts its settings name at the console, and records them when the operator agrees', function (): void {
    $this->artisan('teme:plugins:enable', ['name' => 'tests/hub'])
        ->expectsOutputToContain('Connect to services outside this site: api.example.com, and the hosts you enter in “Hub” and “More hubs”')
        ->expectsOutputToContain('They name these hosts now: hub.example.com')
        ->assertFailed();

    $this->artisan('teme:plugins:enable', ['name' => 'tests/hub', '--agree' => true])->assertSuccessful();

    expect(($this->consent)()['settingHosts'])->toBe(['hub.example.com']);
});

it('lists the hosts its settings name on the plugins page, and records them when it is enabled there', function (): void {
    app()->forgetInstance(Elevation::class);
    $manifest = PluginFixtures::manifest('hub');

    $this->actingAs($this->admin)->get('/admin/plugins')
        ->assertInertia(fn ($page) => $page->where('settingHosts.tests/hub', ['settings' => ['Hub', 'More hubs'], 'hosts' => ['hub.example.com']]));

    $this->actingAs($this->admin)->withSession(scConfirmed())
        ->post('/admin/plugins/hub/enable', [
            'version' => $manifest['version'],
            'capabilities' => $manifest['capabilities'],
            'hosts' => $manifest['hosts'],
            'hooks' => [],
            'settingHosts' => ['hub.example.com'],
        ])
        ->assertRedirect(route('plugins.index'))
        ->assertInertiaFlash('toast.type', 'success');

    expect(($this->consent)()['settingHosts'])->toBe(['hub.example.com']);
});
