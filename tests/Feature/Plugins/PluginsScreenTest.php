<?php

declare(strict_types=1);

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginWords;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Plugins\Capability;
use Tests\Fixtures\Backups\HealthyBackups;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    PluginFixtures::use('hello', 'widened');

    // The plugins' rows, read by name: the page lists whatever else is found.
    $this->rows = fn (): array => collect($this->actingAs($this->admin)->get('/admin/plugins')->assertOk()->viewData('page')['props']['plugins'])->keyBy('name')->all();
    $this->agreed = ['capabilities' => ['content.read', 'http'], 'hosts' => ['api.example.com'], 'hooks' => ['ContentPublished']];
});

afterEach(fn () => PluginFixtures::forget());

it('lists every installed plugin and every row whose code is gone, by tab', function (): void {
    PluginRecord::factory()->enabled()->create(['name' => 'tests/widened', 'code' => 'widened', 'consent' => $this->agreed]);
    PluginRecord::factory()->enabled()->create(['name' => 'tests/gone', 'code' => 'gone']);

    $rows = ($this->rows)();

    expect(array_keys($rows))->toBe(['tests/gone', 'tests/hello', 'tests/widened'])
        ->and($rows['tests/hello'])->toMatchArray(['label' => 'Hello', 'version' => '1.0.0', 'state' => 'not_enabled', 'badge' => 'Disabled', 'enabled' => false, 'needsAttention' => false, 'action' => 'enable'])
        ->and($rows['tests/widened'])->toMatchArray(['state' => 'enabled', 'badge' => 'Enabled', 'enabled' => true, 'needsAttention' => false, 'action' => 'disable'])
        ->and($rows['tests/gone'])->toMatchArray(['state' => 'code_gone', 'badge' => 'Can’t be used', 'enabled' => true, 'needsAttention' => true, 'action' => 'disable']);
});

it('says each state’s reason in words', function (): void {
    PluginRecord::factory()->enabled()->create(['name' => 'tests/gone', 'code' => 'gone']);
    PluginRecord::factory()->stopped()->create(['name' => 'tests/widened', 'code' => 'widened', 'consent' => $this->agreed, 'faults' => ['tests/widened#boot' => ['error' => 'It broke.']]]);

    $rows = ($this->rows)();

    expect($rows['tests/hello']['reason'])->toBe('Installed, not enabled yet.')
        ->and($rows['tests/gone']['reason'])->toBe('Its code is no longer installed. Its settings are kept.')
        ->and($rows['tests/widened'])->toMatchArray(['badge' => 'Stopped by Teme', 'needsAttention' => true, 'reason' => 'Teme disabled it after it failed to start five times: It broke.', 'action' => 'enable']);
});

it('offers Review access for an update that asks for more than was agreed, and says so', function (): void {
    PluginFixtures::use(PluginFixtures::copy('widened', ['version' => '1.1.0', 'capabilities' => ['content.read', 'http', 'media.read']]));
    PluginRecord::factory()->enabled()->create(['name' => 'tests/widened', 'code' => 'widened', 'consent' => $this->agreed]);

    $row = ($this->rows)()['tests/widened'];

    expect($row)->toMatchArray([
        'badge' => 'Needs attention',
        'reason' => 'Update 1.1.0 is blocked: it asks for more access than you agreed to.',
        'action' => 'review',
        'agreedVersion' => '1.0.0',
        'asksMore' => true,
        'needsAttention' => true,
    ]);
});

it('words each capability and hook, never as codes, and marks what an update adds as NEW', function (): void {
    PluginFixtures::use(PluginFixtures::copy('widened', ['version' => '1.1.0', 'capabilities' => ['content.read', 'http', 'media.read'], 'hooks' => ['ContentPublished', 'MediaStored', 'SettingsChanged']]));
    PluginRecord::factory()->enabled()->create(['name' => 'tests/widened', 'code' => 'widened', 'consent' => $this->agreed]);

    $row = ($this->rows)()['tests/widened'];

    expect($row['asks'])->toBe([
        ['words' => 'Read your posts, pages and other content, including drafts', 'new' => false],
        ['words' => 'Connect to services outside this site: api.example.com', 'new' => false],
        ['words' => 'Read the media library', 'new' => true],
    ])->and($row['agreed'])->toBe([
        ['words' => 'Read your posts, pages and other content, including drafts', 'new' => false],
        ['words' => 'Connect to services outside this site: api.example.com', 'new' => false],
    ])->and($row['listensFor'])->toBe(['an item published', 'a file uploaded or replaced', 'a setting changed'])
        ->and(json_encode([$row['asks'], $row['agreed'], $row['listensFor']]))->not->toContain('content.read')->not->toContain('ContentPublished');
});

it('words every capability a plugin can ask for, never as its code', function (Capability $capability): void {
    expect(PluginWords::capability($capability->value, ['api.example.com']))->not->toBe($capability->value);
})->with(Capability::cases());

it('says which hosts a new host marks as NEW', function (): void {
    PluginFixtures::use(PluginFixtures::copy('widened', ['version' => '1.1.0', 'hosts' => ['api.example.com', 'files.other.example']]));
    PluginRecord::factory()->enabled()->create(['name' => 'tests/widened', 'code' => 'widened', 'consent' => $this->agreed]);

    $row = ($this->rows)()['tests/widened'];

    expect($row['asks'][1])->toBe(['words' => 'Connect to services outside this site: api.example.com, files.other.example', 'new' => true])
        ->and($row['shown'])->toMatchArray(['version' => '1.1.0', 'hosts' => ['api.example.com', 'files.other.example']]);
});

it('does not offer Remove its data while the plugin is enabled with tables', function (): void {
    PluginFixtures::use('tables');
    app()->instance(Elevation::class, new OperatorElevation);
    app(EnablePlugin::class)->execute(new EnablePluginInput(
        'tests/tables',
        '1.0.0',
        ['content.read'],
        [],
        ['ContentPublished'],
        null,
    ));

    $row = ($this->rows)()['tests/tables'];

    expect($row['canRemoveData'])->toBeFalse()
        ->and($row['tables'])->not->toBeEmpty();

    // The page request restores the default Elevation; re-bind the operator.
    app()->instance(Elevation::class, new OperatorElevation);
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/tables', null));

    expect(($this->rows)()['tests/tables']['canRemoveData'])->toBeTrue();
});

it('offers the settings link only to a plugin that declares settings', function (): void {
    $copy = PluginFixtures::copy('hello', ['contributes' => ['settings' => [['key' => 'token', 'type' => 'text', 'label' => 'Token', 'description' => 'Its token.']]]]);
    PluginFixtures::use($copy, 'widened');

    $rows = ($this->rows)();

    expect($rows['tests/hello']['hasSettings'])->toBeTrue()
        ->and($rows['tests/widened']['hasSettings'])->toBeFalse();
});

it('puts Plugins in the main menu under Site, between Themes and Types, and nowhere for someone without plugins.manage', function (): void {
    $keys = fn (User $person): array => array_column(array_values(array_filter(
        $this->actingAs($person)->get(route('dashboard'))->viewData('page')['props']['nav'],
        fn (array $entry): bool => $entry['section'] === 'site',
    )), 'key');
    $entry = collect($this->actingAs($this->admin)->get(route('dashboard'))->viewData('page')['props']['nav'])->firstWhere('key', 'plugins');

    expect($entry)->toMatchArray(['label' => 'Plugins', 'icon' => 'plug', 'section' => 'site', 'href' => '/admin/plugins'])
        ->and(array_slice($keys($this->admin), 0, 3))->toBe(['themes', 'plugins', 'types'])
        ->and($keys(User::factory()->create()->assignRole('editor')))->not->toContain('plugins');
});

it('hides the banner’s Open Plugins on the Plugins page itself', function (): void {
    HealthyBackups::seed();
    PluginRecord::factory()->enabled()->create(['name' => 'tests/gone', 'code' => 'gone']);

    $banner = fn (string $path): ?array => $this->actingAs($this->admin)->get($path)->assertOk()->viewData('page')['props']['statusBanner'];

    expect($banner('/admin/tools/updates'))->toMatchArray(['message' => 'tests/gone can’t run: Its code is no longer installed. Its settings are kept.', 'actionLabel' => 'Open Plugins', 'actionUrl' => route('plugins.index')])
        ->and($banner('/admin/plugins'))->toMatchArray(['message' => 'tests/gone can’t run: Its code is no longer installed. Its settings are kept.', 'actionLabel' => null, 'actionUrl' => null]);
});

it('draws the page for an administrator', function (): void {
    $this->actingAs($this->admin)->get('/admin/plugins')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('plugins/index')->has('plugins', 2));
});

it('marks nothing as NEW at a first enable, when everything it asks is new', function (): void {
    $row = ($this->rows)()['tests/widened'];

    expect(array_column($row['asks'], 'new'))->toBe([false, false])
        ->and($row['agreedBefore'])->toBeFalse();
});
