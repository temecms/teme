<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Contributions\DeclaredSettings;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Settings\SettingType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $declared = [
        ['key' => 'kinds', 'type' => 'type', 'label' => 'Kinds', 'description' => 'Which types.', 'multiple' => true],
        ['key' => 'kind', 'type' => 'type', 'label' => 'Kind', 'description' => 'Which type.'],
    ];
    app(SettingsRegistry::class)->group(DeclaredSettings::group('courier', 'Courier', 'Sends a digest.', '1.0.0', $declared), 'tests/courier');

    $this->save = fn (array $values) => app(UpdateSettings::class)
        ->execute(new UpdateSettingsInput('plugin.courier', $values, [], [], 0, (string) $this->admin->id));
});

it('declares a multiple type setting the manifest validator accepts', function (): void {
    $declared = [
        ['key' => 'kinds', 'type' => 'type', 'label' => 'Kinds', 'description' => 'Which types.', 'multiple' => true],
    ];
    $group = DeclaredSettings::group('courier', 'Courier', 'Sends a digest.', '1.0.0', $declared);

    expect(app(ContributionsValidator::class)->errors(['settings' => $declared], '1.0.0'))->toBe([])
        ->and($group->setting('kinds')?->type)->toBe(SettingType::ContentType)
        ->and($group->setting('kinds')?->multiple)->toBeTrue()
        ->and($group->setting('kinds')?->default)->toBe([]);
});

it('builds a multiple type setting from the contract, and keeps a single one as it was', function (): void {
    $many = Setting::contentType('kinds', 'Kinds', 'Which types.', '1.0.0', multiple: true);
    $one = Setting::contentType('kind', 'Kind', 'Which type.', '1.0.0');

    expect($many->multiple)->toBeTrue()
        ->and($many->default)->toBe([])
        ->and($one->multiple)->toBeFalse()
        ->and($one->default)->toBe('');
});

it('draws a multiple type setting as a choice of the public types', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings/plugin.courier')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/group')
            ->where('fields.0.type', 'type')
            ->where('fields.0.multiple', true)
            ->where('fields.0.options', ['post' => 'Posts', 'page' => 'Pages'])
            ->where('fields.1.multiple', false));
});

it('stores several chosen types as keys, which the reader gives as a list', function (): void {
    ($this->save)(['kinds' => ['post', 'page'], 'kind' => 'page']);
    $reader = app(SettingsReader::class);

    expect($reader->stringList('plugin.courier', 'kinds'))->toBe(['post', 'page'])
        ->and($reader->string('plugin.courier', 'kind'))->toBe('page');
});

it('refuses a type the site doesn’t have among several, writing nothing', function (array $values): void {
    expect(fn () => ($this->save)($values))->toThrow(ValidationException::class);
    expect(DB::table('settings')->where('group_key', 'plugin.courier')->exists())->toBeFalse();
})->with([
    'one unknown type among several' => [['kinds' => ['post', 'recipe']]],
    'a type with no public addresses' => [['kinds' => ['region']]],
    'a single type for a multiple setting' => [['kinds' => 'post']],
    'a list for a single type' => [['kind' => ['post']]],
]);
