<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\ActOnPluginRecords;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\ActOnPluginRecordsInput;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Admin\Saved;
use Teme\Contracts\Content\WriteRefused;
use Tests\Fixtures\Plugins\Placard\PlacardArea;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

foreach (glob(dirname(__DIR__, 3).'/Fixtures/plugins/placard/src/*.php') ?: [] as $file) {
    require_once $file;
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    PlacardArea::reset();
    $this->editor = User::factory()->create()->assignRole('editor');

    PluginFixtures::use('placard');
    $manifest = app(PluginRegistry::class)->find('tests/placard')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest?->version() ?? '1.0.0', $manifest?->capabilities() ?? [], $manifest?->hosts() ?? [], $manifest?->hooks() ?? [], null));
    app(PluginLoader::class)->load();

    $this->ids = array_map(fn (string $name): string => (string) DB::table('placard_ads')->insertGetId(['name' => $name, 'status' => 'active', 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]), ['Sponsor banner', 'Winter sale']);

    $this->act = fn (string $action, ?array $ids = null, bool $confirmed = false, ?User $person = null, string $record = 'ad'): array => app(ActOnPluginRecords::class)->execute(new ActOnPluginRecordsInput('placard', $record, $action, $ids ?? $this->ids, $confirmed, (string) ($person ?? $this->editor)->id));

    $this->http = fn (array $data, ?User $person = null) => $this->actingAs($person ?? $this->editor)->post(route('plugins.area.act', ['code' => 'placard', 'record' => 'ad']), $data);
});

afterEach(function (): void {
    PlacardArea::reset();
    PluginFixtures::forget();
});

it('runs a declared action on the records named, and says how many', function (): void {
    expect(($this->act)('disable'))->toBe(['count' => 2, 'changed' => true])
        ->and(DB::table('placard_ads')->pluck('status')->unique()->values()->all())->toBe(['paused']);

    ($this->http)(['action' => 'enable', 'ids' => [$this->ids[0]]])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Done for 1 record.']);

    expect(DB::table('placard_ads')->where('id', (int) $this->ids[0])->value('status'))->toBe('active');
});

it('deletes only once the dialog confirmed it', function (): void {
    expect(fn () => ($this->act)('delete'))->toThrow(ValidationException::class)
        ->and(DB::table('placard_ads')->count())->toBe(2)
        ->and(PlacardArea::$calls)->toBe([]);

    ($this->act)('delete', confirmed: true);

    expect(DB::table('placard_ads')->count())->toBe(0);
});

it('rolls back what the plugin wrote when it refuses, showing its sentence', function (): void {
    PlacardArea::$on['act'] = function (): Saved {
        DB::table('placard_ads')->update(['status' => 'paused']);

        throw new WriteRefused('One of these ads is locked.');
    };

    try {
        ($this->act)('disable');
        $this->fail('The action was not refused.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toBe(['record' => ['One of these ads is locked.']]);
    }

    expect(DB::table('placard_ads')->pluck('status')->unique()->values()->all())->toBe(['active']);
});

it('refuses, writing nothing, an action the table doesn’t declare, edit, or more than 100 records', function (string $action, ?array $ids): void {
    expect(fn () => ($this->act)($action, $ids))->toThrow(ValidationException::class)
        ->and(PlacardArea::$calls)->toBe([])
        ->and(DB::table('placard_ads')->pluck('status')->unique()->values()->all())->toBe(['active']);
})->with([
    'an undeclared action' => ['archive', null],
    'edit' => ['edit', null],
    'no records' => ['disable', []],
    'more than 100' => ['disable', array_map(strval(...), range(1, 101))],
    'an id that isn’t one' => ['disable', ['']],
]);

it('refuses, writing nothing, a person without the permission, a disabled plugin, or an undeclared record', function (Closure $refuse): void {
    $refuse->call($this);

    expect(PlacardArea::$calls)->toBe([])
        ->and(DB::table('placard_ads')->pluck('status')->unique()->values()->all())->toBe(['active']);
})->with([
    'no permission' => [function (): void {
        expect(fn () => ($this->act)('disable', person: User::factory()->create()->assignRole('author')))->toThrow(AuthorizationException::class);
    }],
    'a disabled plugin' => [function (): void {
        app(DisablePlugin::class)->execute(new DisablePluginInput('tests/placard', null));
        expect(fn () => ($this->act)('disable'))->toThrow(AuthorizationException::class);
    }],
    'an undeclared record' => [function (): void {
        expect(fn () => ($this->act)('disable', record: 'banner'))->toThrow(AuthorizationException::class);
    }],
]);
