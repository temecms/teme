<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\ActOnPluginRecords;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\ActOnPluginRecordsInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\SavePluginRecordInput;
use App\Domain\Plugins\Operations\SavePluginRecord;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Queries\PluginAreaScreen;
use App\Domain\Plugins\Queries\PluginRecordForm;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Teme\Contracts\Admin\AreaContext;
use Tests\Fixtures\Plugins\Placard\PlacardArea;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

foreach (glob(dirname(__DIR__, 3).'/Fixtures/plugins/placard/src/*.php') ?: [] as $file) {
    require_once $file;
}

/*
 * What a plugin's area is told about the person's own permissions
 * (docs/plans/plugin-sockets-3.md, C8): the ids of this plugin's
 * permissions they hold, in every read and every write, and no other
 * plugin's.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    PlacardArea::reset();

    $contributes = PluginFixtures::manifest('placard')['contributes'];
    $contributes['permissions'][] = ['id' => 'view-all', 'label' => 'See everyone’s ads', 'description' => 'See ads other people made.', 'roles' => []];
    PluginFixtures::use(PluginFixtures::copy('placard', ['contributes' => $contributes]));
    $manifest = app(PluginRegistry::class)->find('tests/placard')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest?->version() ?? '1.0.0', $manifest?->capabilities() ?? [], $manifest?->hosts() ?? [], $manifest?->hooks() ?? [], null));
    app(PluginLoader::class)->load();

    // Another plugin's permission the editors hold, which Placard is never told of.
    Role::findByName('editor')->givePermissionTo(Permission::create(['name' => 'plugin.billboard.manage']));

    $this->editor = User::factory()->create()->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->id = (string) DB::table('placard_ads')->insertGetId(['name' => 'Sponsor banner', 'status' => 'active', 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);

    // Every context Placard was handed, whichever call it came with.
    $this->contexts = fn (): array => array_values(array_map(
        fn (array $call): ?array => ($last = end($call[1])) instanceof AreaContext ? $last->permissions : null,
        PlacardArea::$calls,
    ));

    $this->use = function (User $person): void {
        $screen = app(PluginAreaScreen::class);
        $plugin = $screen->plugin('placard', $person);
        $screen->data($plugin, 'overview', 'totals', (string) $person->id);
        $screen->data($plugin, 'ads', 'ads', (string) $person->id);
        app(PluginRecordForm::class)->find($plugin, 'ad', $this->id, (string) $person->id);
        app(SavePluginRecord::class)->execute(new SavePluginRecordInput('placard', 'ad', null, ['name' => 'Winter sale', 'location' => 'between_items', 'versions' => [['label' => 'Wide', 'code' => '<div>Ad</div>', 'weight' => '1']], 'types' => [], 'terms' => []], (string) $person->id));
        app(ActOnPluginRecords::class)->execute(new ActOnPluginRecordsInput('placard', 'ad', 'disable', [$this->id], false, (string) $person->id));
    };
});

afterEach(function (): void {
    PlacardArea::reset();
    PluginFixtures::forget();
});

it('tells the area which of its own permissions the person holds, in every read and write', function (User $person, array $held): void {
    ($this->use)($person);

    expect(array_column(PlacardArea::$calls, 0))->toBe(['figures', 'rows', 'values', 'save', 'act'])
        ->and(($this->contexts)())->toBe(array_fill(0, 5, $held));
})->with([
    'an editor, who holds manage and another plugin’s permission' => [fn () => $this->editor, ['manage']],
    'an administrator, who holds both' => [fn () => $this->admin, ['manage', 'view-all']],
]);
