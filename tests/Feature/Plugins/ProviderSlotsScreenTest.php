<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Slots\PluginSlots;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once dirname(__DIR__, 2).'/Fixtures/plugins/slotted/src/Slotted.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);

    PluginFixtures::use('slotted');
    $manifest = app(PluginRegistry::class)->find('tests/slotted')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/slotted', '1.0.0', $manifest?->capabilities() ?? [], [], [], null));
    app(PluginLoader::class)->load();
    app()->forgetInstance(Elevation::class);

    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('lists each fillable slot with the plugins that can fill it', function (): void {
    $page = $this->actingAs($this->admin)->get('/admin/plugins/slots')->assertOk()->viewData('page')['props']['slots'];

    $image = collect($page)->firstWhere('id', PluginSlots::IMAGE_SERVICE);

    expect($image['providers'])->toBe([['name' => 'tests/slotted', 'label' => 'Slotted']])
        ->and($image['chosen'])->toBe('tests/slotted');
});

it('saves the choice of a provider, and clears it for none', function (): void {
    $this->actingAs($this->admin)->post('/admin/plugins/slots/'.PluginSlots::IMAGE_SERVICE, ['plugin' => 'tests/slotted'])->assertRedirect();
    expect(DB::table('plugin_slot_choices')->where('slot', PluginSlots::IMAGE_SERVICE)->value('plugin'))->toBe('tests/slotted');

    $this->actingAs($this->admin)->post('/admin/plugins/slots/'.PluginSlots::IMAGE_SERVICE, ['plugin' => ''])->assertRedirect();
    expect(DB::table('plugin_slot_choices')->where('slot', PluginSlots::IMAGE_SERVICE)->exists())->toBeFalse();
});

it('refuses a person without plugins.manage, and writes nothing', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($editor)->post('/admin/plugins/slots/'.PluginSlots::IMAGE_SERVICE, ['plugin' => 'tests/slotted'])->assertForbidden();
    expect(DB::table('plugin_slot_choices')->count())->toBe(0);
});
