<?php

declare(strict_types=1);

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\RemovePluginDataInput;
use App\Domain\Plugins\Operations\RemovePluginData;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Queries\PluginsScreen;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);

    PluginFixtures::use('tables');
    $manifest = app(PluginRegistry::class)->find('tests/tables')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/tables',
        $manifest?->version() ?? '1.0.0',
        $manifest?->capabilities() ?? [],
        $manifest?->hosts() ?? [],
        $manifest?->hooks() ?? [],
        null,
    ));
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/tables', null));

    SettingsGroupRecord::query()->create(['group_key' => 'plugin.tables', 'overrides' => ['mode' => 'fast'], 'secrets' => ['api_key' => 'sk-1'], 'version' => 1]);
    SettingsGroupRecord::query()->create(['group_key' => 'plugin.other', 'overrides' => ['mode' => 'slow'], 'secrets' => ['api_key' => 'sk-2'], 'version' => 1]);
});

it('removes the plugin’s settings group and its secrets with its tables', function (): void {
    $tables = app(RemovePluginData::class)->execute(new RemovePluginDataInput('tests/tables', null));

    expect($tables)->not->toBeEmpty()
        ->and(Schema::hasTable($tables[0]))->toBeFalse()
        ->and(SettingsGroupRecord::query()->find('plugin.tables'))->toBeNull()
        ->and(PluginRecord::query()->where('name', 'tests/tables')->exists())->toBeTrue();
});

it('records in the security log that settings were removed, and how many secrets, never their values', function (): void {
    app(RemovePluginData::class)->execute(new RemovePluginDataInput('tests/tables', null));

    $event = DB::table('security_events')->where('type', 'plugin.data_removed')->sole();
    $context = json_decode((string) $event->context, true);

    expect($context)->toMatchArray(['plugin' => 'tests/tables', 'settings_removed' => true, 'secrets_removed' => 1])
        ->and((string) $event->context)->not->toContain('sk-1');
});

it('leaves another plugin’s settings and secrets alone', function (): void {
    app(RemovePluginData::class)->execute(new RemovePluginDataInput('tests/tables', null));

    $other = SettingsGroupRecord::query()->find('plugin.other');

    expect($other?->overrides)->toBe(['mode' => 'slow'])
        ->and($other?->secrets)->toBe(['api_key' => 'sk-2']);
});

it('removes nothing when the password check is refused', function (): void {
    app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => app(RemovePluginData::class)->execute(new RemovePluginDataInput('tests/tables', null)))
        ->toThrow(AuthorizationException::class);

    expect(SettingsGroupRecord::query()->find('plugin.tables')?->secrets)->toBe(['api_key' => 'sk-1'])
        ->and(Schema::hasTable('tables_links'))->toBeTrue();
});

it('tells the plugins screen what saved settings and secrets will go', function (): void {
    $row = collect(app(PluginsScreen::class)->rows())->firstWhere('name', 'tests/tables');

    expect($row['savedData'])->toBe(['saved' => true, 'secrets' => 1]);

    app(RemovePluginData::class)->execute(new RemovePluginDataInput('tests/tables', null));

    $row = collect(app(PluginsScreen::class)->rows())->firstWhere('name', 'tests/tables');

    expect($row['savedData'])->toBe(['saved' => false, 'secrets' => 0]);
});
