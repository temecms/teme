<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Contributions\DeclaredSettings;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\WritePluginSettingsInput;
use App\Domain\Plugins\Operations\WritePluginSettings;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Settings\SettingType;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->declared = PluginFixtures::manifest('librarian')['contributes']['settings'];
    app(SettingsRegistry::class)->group(DeclaredSettings::group('librarian', 'Librarian', 'Reads and writes.', '1.0.0', $this->declared), 'tests/librarian');
});

it('declares a person setting the manifest validator accepts', function (): void {
    $group = DeclaredSettings::group('librarian', 'Librarian', 'Reads and writes.', '1.0.0', $this->declared);

    expect(app(ContributionsValidator::class)->errors(['settings' => $this->declared], '1.0.0'))->toBe([])
        ->and($group->setting('byline')?->type)->toBe(SettingType::Person);
});

it('draws a person setting with core’s person picker, offering the people who may write', function (): void {
    $writer = User::factory()->create(['name' => 'Wren Writer'])->assignRole('author');

    $this->actingAs($this->admin)->get('/admin/settings/plugin.librarian')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/group')
            ->where('fields.0.type', 'person')
            ->where('fields.0.people', fn ($people): bool => collect($people)->pluck('value')->contains((string) $writer->id)));
});

it('stores a chosen person, and the reader gives the account ID', function (): void {
    $writer = User::factory()->create()->assignRole('author');

    app(UpdateSettings::class)->execute(new UpdateSettingsInput('plugin.librarian', ['byline' => (string) $writer->id], [], [], 0, (string) $this->admin->id));
    $reader = app(PluginDoors::class)->for('tests/librarian', 'librarian', ['content.read'], [])[SettingsReader::class];

    expect($reader->string('plugin.librarian', 'byline'))->toBe((string) $writer->id);
});

it('refuses a person with no account, writing nothing', function (): void {
    expect(fn () => app(UpdateSettings::class)->execute(new UpdateSettingsInput('plugin.librarian', ['byline' => '999999'], [], [], 0, (string) $this->admin->id)))
        ->toThrow(ValidationException::class);

    expect(DB::table('settings')->where('group_key', 'plugin.librarian')->exists())->toBeFalse();
});

it('refuses a plugin choosing its own person setting, writing nothing', function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    PluginFixtures::use('librarian');
    PluginFixtures::installed(['tests/librarian' => 'librarian']);
    $manifest = app(PluginRegistry::class)->find('tests/librarian')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/librarian',
        $manifest?->version() ?? '1.0.0',
        $manifest?->capabilities() ?? [],
        $manifest?->hosts() ?? [],
        $manifest?->hooks() ?? [],
        (string) $this->admin->id,
    ));
    $writer = User::factory()->create()->assignRole('author');

    try {
        app(WritePluginSettings::class)->execute(new WritePluginSettingsInput('librarian', ['byline' => (string) $writer->id], (string) $this->admin->id));
        $this->fail('The write was not refused.');
    } catch (ValidationException $refused) {
        expect($refused->errors()['values.byline'] ?? [])->toBe(['A plugin can’t choose a person for itself.']);
    }

    expect(DB::table('settings')->where('group_key', 'plugin.librarian')->exists())->toBeFalse();

    PluginFixtures::forget();
});
