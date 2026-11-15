<?php

declare(strict_types=1);

use App\Domain\Import\PluginData\PluginImports;
use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    PluginFixtures::use('importer');
    $manifest = PluginFixtures::manifest('importer');
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/importer', $manifest['version'], $manifest['capabilities'], [], [], null));
    $this->updated = function (): void {
        $manifest = PluginFixtures::manifest('importer');
        PluginFixtures::use(PluginFixtures::copy('importer', ['imports' => [...$manifest['imports'], 'options' => ['importer_mode', 'importer_secret_key']]]));
        app(PluginRecords::class)->changed();
        app(PluginLoader::class)->load();
    };
});

afterEach(fn () => PluginFixtures::forget());

it('records the names agreed to, beside the capabilities and hosts', function (): void {
    expect(PluginRecord::query()->where('name', 'tests/importer')->sole()->consent['imports'])->toEqualCanonicalizing([
        'options' => ['importer_mode'],
        'postTypes' => ['ap_actor' => ['_importer_inbox', '_importer_following']],
        'userMeta' => ['importer_also_known_as'],
        'termMeta' => [],
    ]);
});

it('holds an update that declares a new name until someone agrees, and shows the name as new', function (): void {
    ($this->updated)();
    $admin = User::factory()->create()->assignRole('administrator');

    $row = collect($this->actingAs($admin)->get('/admin/plugins')->assertOk()->viewData('page')['props']['plugins'])->keyBy('name')['tests/importer'];

    expect($row['state'])->toBe('waiting')
        ->and($row['action'])->toBe('review')
        ->and($row['asksMore'])->toBeTrue()
        ->and($row['asks'][0]['new'])->toBeTrue()
        ->and($row['asks'][0]['words'])->toContain('importer_secret_key')
        ->and($row['agreed'][0]['words'])->not->toContain('importer_secret_key')
        ->and(app(PluginImports::class)->all())->toBe([]);
});

it('hands over only the names both declared and agreed', function (): void {
    $manifest = new Manifest([...PluginFixtures::manifest('importer'), 'imports' => [
        'importer' => 'X',
        'options' => ['importer_mode', 'importer_new'],
        'postTypes' => ['ap_actor' => ['_importer_inbox', '_importer_new'], 'ap_new' => []],
        'userMeta' => [],
    ]]);
    $consent = PluginRecord::query()->where('name', 'tests/importer')->sole()->consent;

    expect(Consent::imports($consent, $manifest))->toBe([
        'options' => ['importer_mode'],
        'postTypes' => ['ap_actor' => ['_importer_inbox']],
        'userMeta' => [],
        'termMeta' => [],
    ])->and(Consent::added($consent, $manifest)['imports'])->toBe([
        'options' => ['importer_new'],
        'postTypes' => ['ap_actor' => ['_importer_new'], 'ap_new' => []],
        'userMeta' => [],
        'termMeta' => [],
    ]);
});

it('hands a plugin enabled before names were recorded nothing until someone agrees again', function (): void {
    $record = PluginRecord::query()->where('name', 'tests/importer')->sole();
    $record->forceFill(['consent' => ['capabilities' => $record->consent['capabilities'], 'hosts' => [], 'hooks' => []]])->save();
    app(PluginRecords::class)->changed();
    app(PluginLoader::class)->load();

    expect(app(PluginImports::class)->all())->toBe([]);
});
