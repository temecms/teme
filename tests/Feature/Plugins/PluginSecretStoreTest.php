<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\DeclaredSettings;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\Doors\PluginSecretStoreWrites;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\ChangePluginSecret;
use App\Domain\Plugins\Operations\Input\ChangePluginSecretInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\Events\SettingsChanged;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Settings\PluginSecretStore;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->actingAs($this->admin);
    $this->enable = fn (string $state = PluginRecord::ENABLED) => PluginRecord::factory()->enabled()->create([
        'name' => 'tests/vault',
        'code' => 'vault',
        'version' => '1.0.0',
        'state' => $state,
        'consent' => ['capabilities' => ['http'], 'hosts' => [], 'hooks' => []],
    ]);
    $declared = [['key' => 'api_key', 'type' => 'secret', 'label' => 'API key', 'description' => 'The key.']];
    app(SettingsRegistry::class)->group(DeclaredSettings::group('vault', 'Vault', 'Keeps tokens.', '1.0.0', $declared), 'tests/vault');
    $this->door = fn (string $actor): PluginSecretStore => new PluginSecretStoreWrites('vault');
    $this->stored = fn (): array => SettingsGroupRecord::query()->find('plugin.vault')?->secrets ?? [];
});

it('offers the secret store only to a plugin that declares a secret setting', function (): void {
    PluginFixtures::installed(['tests/keyfile' => 'keyfile', 'tests/hello' => 'hello']);
    PluginFixtures::use();
    app(PluginRegistry::class)->flush();
    $doors = app(PluginDoors::class);

    expect($doors->for('tests/keyfile', 'keyfile', ['http'], [])[PluginSecretStore::class] ?? null)->toBeInstanceOf(PluginSecretStoreWrites::class)
        ->and($doors->for('tests/hello', 'hello', ['http'], []))->not->toHaveKey(PluginSecretStore::class);
});

it('keeps a free secret out of the settings history, the change event and the security log', function (): void {
    Event::fake([SettingsChanged::class]);
    ($this->enable)();

    ($this->door)((string) $this->admin->id)->put('oauth_token', 'secret-value-123', (string) $this->admin->id);
    ($this->door)((string) $this->admin->id)->forget('oauth_token', (string) $this->admin->id);

    expect(DB::table('settings_changes')->whereRaw("changed_keys::text like '%store:%'")->exists())->toBeFalse()
        ->and(DB::table('settings_changes')->whereRaw("coalesce(before::text,'') || coalesce(after::text,'') like '%secret-value%'")->exists())->toBeFalse()
        ->and(DB::table('security_events')->whereRaw("context::text like '%secret-value%'")->exists())->toBeFalse();
    Event::assertNotDispatched(SettingsChanged::class);
});

it('keeps a free key a plugin writes, reads it back, and keeps it encrypted at rest', function (): void {
    ($this->enable)();

    ($this->door)((string) $this->admin->id)->put('oauth_token', 'token-plaintext-value', (string) $this->admin->id);

    $raw = DB::table('settings')->where('group_key', 'plugin.vault')->value('secrets');

    expect(($this->door)((string) $this->admin->id)->get('oauth_token'))->toBe('token-plaintext-value')
        ->and($raw)->not->toContain('token-plaintext-value')
        ->and(($this->stored)())->toHaveKey('store:oauth_token');
});

it('takes the key away with forget', function (): void {
    ($this->enable)();
    $door = ($this->door)((string) $this->admin->id);
    $door->put('oauth_token', 'token', (string) $this->admin->id);

    $door->forget('oauth_token', (string) $this->admin->id);

    expect($door->get('oauth_token'))->toBeNull();
});

it('refuses a value that is empty or larger than 4 KB, a bad key, and writes nothing', function (string $key, string $value): void {
    ($this->enable)();

    expect(fn () => ($this->door)((string) $this->admin->id)->put($key, $value, (string) $this->admin->id))->toThrow(WriteRefused::class);
    expect(($this->stored)())->toBe([]);
})->with([
    'empty value' => ['token', ''],
    'value over 4 KB' => ['token', str_repeat('a', 4097)],
    'key with a dot' => ['bad.key', 'value'],
    'key starting with a digit' => ['1token', 'value'],
]);

it('keeps at most 100 free keys, but lets one already held be changed', function (): void {
    ($this->enable)();
    $door = ($this->door)((string) $this->admin->id);

    foreach (range(1, 100) as $n) {
        $door->put("key_{$n}", 'value', (string) $this->admin->id);
    }

    $door->put('key_1', 'changed', (string) $this->admin->id);

    expect(fn () => $door->put('key_101', 'value', (string) $this->admin->id))->toThrow(WriteRefused::class)
        ->and($door->get('key_1'))->toBe('changed')
        ->and(count(array_filter(array_keys(($this->stored)()), fn (string $key): bool => str_starts_with($key, 'store:'))))->toBe(100);
});

it('refuses a person who may not manage plugins, writing nothing', function (): void {
    ($this->enable)();
    $member = User::factory()->create()->assignRole('editor');

    expect(fn () => app(ChangePluginSecret::class)->execute(new ChangePluginSecretInput('vault', 'token', 'value', (string) $member->id)))
        ->toThrow(AuthorizationException::class)
        ->and(($this->stored)())->toBe([]);
});

it('refuses a change to a plugin that is not enabled, writing nothing', function (): void {
    ($this->enable)(PluginRecord::DISABLED);

    expect(fn () => app(ChangePluginSecret::class)->execute(new ChangePluginSecretInput('vault', 'token', 'value', (string) $this->admin->id)))
        ->toThrow(AuthorizationException::class)
        ->and(($this->stored)())->toBe([]);
});
