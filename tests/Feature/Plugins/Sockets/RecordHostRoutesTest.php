<?php

declare(strict_types=1);

use App\Domain\Plugins\Doors\HostResolver;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\RecordHosts;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Plugins\Lists\FakeResolver;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

// The agree and remove routes answer JSON: 200 on success, 422 with the
// sentence when refused, 423 when the password step is missing. Only someone who
// manages plugins can use them.

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    PluginFixtures::use('hub');
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(HostResolver::class, FakeResolver::answering(['api.example.com' => ['93.184.215.14']]));

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

    RecordHosts::noteSaved('tests/hub', '01JRECORDAAAAAAAAAAAAAAAAA', 'Sponsor banner', ['reports.example.org']);
    $this->agreeUrl = '/admin/plugins/hub/hosts/agree';
    $this->removeUrl = '/admin/plugins/hub/hosts/remove';
    $this->consent = fn (): array => PluginRecord::query()->where('name', 'tests/hub')->firstOrFail()->consent;
});

afterEach(function (): void {
    PluginFixtures::forget();
});

it('answers 423 and agrees nothing without the password step', function (): void {
    // No password confirmed in this session, so the step is missing.
    $this->actingAs($this->admin)->postJson($this->agreeUrl, ['host' => 'reports.example.org'])
        ->assertStatus(423);

    expect(RecordHosts::agreed(($this->consent)()))->toBe([]);
});

it('refuses an editor with the area permission and agrees nothing', function (): void {
    $this->actingAs($this->editor)->postJson($this->agreeUrl, ['host' => 'reports.example.org'])
        ->assertForbidden();

    expect(RecordHosts::agreed(($this->consent)()))->toBe([]);
});

it('removes an agreed host for a manager and refuses the editor', function (): void {
    ($this->consent)();
    RecordHosts::agree('tests/hub', ['reports.example.org'], 1);

    $this->actingAs($this->editor)->postJson($this->removeUrl, ['host' => 'reports.example.org'])
        ->assertForbidden();
    expect(RecordHosts::agreed(($this->consent)()))->toBe(['reports.example.org']);

    $this->actingAs($this->admin)->postJson($this->removeUrl, ['host' => 'reports.example.org'])
        ->assertOk()
        ->assertExactJson(['removed' => 'reports.example.org']);
    expect(RecordHosts::agreed(($this->consent)()))->toBe([]);
});
