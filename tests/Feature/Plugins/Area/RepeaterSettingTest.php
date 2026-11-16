<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\WritePluginSettingsInput;
use App\Domain\Plugins\Operations\WritePluginSettings;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Settings\SettingType;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

foreach (glob(dirname(__DIR__, 3).'/Fixtures/plugins/placard/src/*.php') ?: [] as $file) {
    require_once $file;
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    $this->admin = User::factory()->create()->assignRole('administrator');

    $this->start = function (string $folder = 'placard'): void {
        PluginFixtures::use($folder);
        $manifest = app(PluginRegistry::class)->find('tests/placard')?->manifest;
        app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest?->version() ?? '1.0.0', $manifest?->capabilities() ?? [], $manifest?->hosts() ?? [], $manifest?->hooks() ?? [], null));
        app(PluginLoader::class)->load();
    };

    $this->save = function (array $values): void {
        $version = (int) DB::table('settings')->where('group_key', 'plugin.placard')->value('version');
        app(UpdateSettings::class)->execute(new UpdateSettingsInput('plugin.placard', $values, [], [], $version, (string) $this->admin->id));
    };

    $this->stored = fn (): ?string => DB::table('settings')->where('group_key', 'plugin.placard')->value('overrides');

    $this->errors = fn (array $settings): array => app(ContributionsValidator::class)->errors(['settings' => $settings], '1.0.0');
});

afterEach(function (): void {
    PluginFixtures::forget();
});

it('stores a repeater as a JSON list of its items, each by its fields', function (): void {
    ($this->start)();

    ($this->save)(['slots' => [['name' => 'Top', 'size' => '90'], ['name' => 'Side', 'size' => 250]]]);

    expect(app(SettingsReader::class)->get('plugin.placard', 'slots'))->toBe([['name' => 'Top', 'size' => 90], ['name' => 'Side', 'size' => 250]])
        ->and(json_decode((string) ($this->stored)(), true)['slots'] ?? null)->toBe([['name' => 'Top', 'size' => 90], ['name' => 'Side', 'size' => 250]]);
});

it('refuses a repeater beyond its item count, or an item a field’s rule refuses, writing nothing', function (array $slots, string $key): void {
    ($this->start)();

    try {
        ($this->save)(['slots' => $slots]);
        $this->fail('The save was not refused.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toHaveKey($key);
    }

    expect(($this->stored)())->toBeNull();
})->with([
    'too many items' => [array_fill(0, 6, ['name' => 'Slot', 'size' => 90]), 'values.slots'],
    'a height below its least' => [[['name' => 'Top', 'size' => 10]], 'values.slots.0.size'],
    'a name too long' => [[['name' => 'Top'], ['name' => str_repeat('a', 41)]], 'values.slots.1.name'],
    'an item that isn’t one' => [['not an item'], 'values.slots.0'],
]);

it('keeps code up to 65,535 characters, and refuses more', function (): void {
    ($this->start)();

    ($this->save)(['fallback' => '<div class="ad">'.str_repeat('x', 65_000).'</div>']);

    expect(mb_strlen((string) app(SettingsReader::class)->get('plugin.placard', 'fallback')))->toBe(65_022)
        ->and(fn () => ($this->save)(['fallback' => str_repeat('x', 65_536)]))->toThrow(ValidationException::class);
});

it('builds code and repeater settings, and refuses a sub-field a repeater can’t hold', function (): void {
    $repeater = Setting::repeater('slots', 'Slots', 'The ad units.', '1.0.0', [
        Setting::text('name', 'Name', 'What you call it.', '1.0.0'),
        Setting::code('code', 'Code', 'Its markup.', '1.0.0'),
    ])->min(1)->max(5);

    expect($repeater->type)->toBe(SettingType::Repeater)
        ->and($repeater->default)->toBe([])
        ->and([$repeater->min, $repeater->max])->toBe([1, 5])
        ->and(array_map(fn (Setting $field): string => $field->key, $repeater->fields))->toBe(['name', 'code'])
        ->and(Setting::code('fallback', 'Fallback', 'Markup.', '1.0.0')->type)->toBe(SettingType::Code)
        ->and(fn () => Setting::repeater('slots', 'Slots', '', '1.0.0', [Setting::secret('key', 'Key', '', '1.0.0')]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Setting::repeater('slots', 'Slots', '', '1.0.0', [Setting::urls('sites', 'Sites', '', '1.0.0')]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Setting::repeater('slots', 'Slots', '', '1.0.0', [Setting::repeater('inner', 'Inner', '', '1.0.0', [Setting::text('a', 'A', '', '1.0.0')])]))->toThrow(InvalidArgumentException::class);

    expect(($this->errors)([['key' => 'slots', 'type' => 'repeater', 'label' => 'Slots', 'description' => '', 'fields' => [['key' => 'site', 'type' => 'url', 'label' => 'Site', 'description' => '']]]]))
        ->not->toBe([]);
});

it('never lets a plugin write code, or a repeater that holds code, for itself', function (): void {
    $settings = PluginFixtures::manifest('placard')['contributes']['settings'];
    $settings[] = ['key' => 'units', 'type' => 'repeater', 'label' => 'Units', 'description' => 'Ad units.', 'fields' => [['key' => 'code', 'type' => 'code', 'label' => 'Code', 'description' => 'Its markup.']]];
    ($this->start)(PluginFixtures::copy('placard', ['contributes' => [...PluginFixtures::manifest('placard')['contributes'], 'settings' => $settings]]));
    $write = fn (array $values) => app(WritePluginSettings::class)->execute(new WritePluginSettingsInput('placard', $values, (string) $this->admin->id));

    expect(fn () => $write(['fallback' => '<b>Ad</b>']))->toThrow(ValidationException::class)
        ->and(fn () => $write(['units' => [['code' => '<b>Ad</b>']]]))->toThrow(ValidationException::class)
        ->and(($this->stored)())->toBeNull();

    $write(['slots' => [['name' => 'Top', 'size' => 90]]]);

    expect(app(SettingsReader::class)->get('plugin.placard', 'slots'))->toBe([['name' => 'Top', 'size' => 90]]);
});
