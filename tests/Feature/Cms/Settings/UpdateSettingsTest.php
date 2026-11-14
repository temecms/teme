<?php

declare(strict_types=1);

use App\Domain\Settings\Exceptions\StaleSettingsException;
use App\Domain\Settings\Models\SettingsChange;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Settings\SecretReader;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');

    app(SettingsRegistry::class)->group(new SettingsGroup(
        key: 'integrations_demo',
        label: 'Integrations',
        description: 'A group with a secret, for these tests.',
        section: 'integrations',
        ability: 'settings.general.manage',
        settings: [Setting::secret('api_key', 'API key', 'A credential.', '0.1.0')->env('TEME_TEST_API_KEY')],
        since: '0.1.0',
    ));
});

/**
 * @param  array<string, mixed>  $values
 * @param  list<string>  $reset
 * @param  array<string, ?string>  $secrets
 *
 * @return array{group: string, version: int}
 */
function updateSettings(User $actor, string $group, array $values = [], array $reset = [], array $secrets = [], int $version = 0): array
{
    return app(UpdateSettings::class)->execute(new UpdateSettingsInput(
        group: $group,
        values: $values,
        reset: $reset,
        secrets: $secrets,
        expectedVersion: $version,
        actorId: (string) $actor->id,
    ));
}

it('stores a changed value, bumps the version, and is readable at once', function (): void {
    $result = updateSettings($this->admin, 'general', ['site_title' => 'Plant Notes']);

    expect($result)->toBe(['group' => 'general', 'version' => 1])
        ->and(app(SettingsReader::class)->string('general', 'site_title'))->toBe('Plant Notes');
});

it('never writes a default', function (): void {
    updateSettings($this->admin, 'general', ['site_title' => 'My Teme site', 'timezone' => 'UTC']);

    expect(SettingsGroupRecord::query()->find('general'))->toBeNull();
});

it('removes an override when set back to the default', function (): void {
    updateSettings($this->admin, 'general', ['site_title' => 'Plant Notes']);
    updateSettings($this->admin, 'general', ['site_title' => 'My Teme site'], version: 1);

    expect(SettingsGroupRecord::query()->find('general')->overrides)->toBe([]);
});

it('resets a key to its default', function (): void {
    updateSettings($this->admin, 'general', ['tagline' => 'Leaves and roots']);
    updateSettings($this->admin, 'general', reset: ['tagline'], version: 1);

    expect(app(SettingsReader::class)->string('general', 'tagline'))->toBe('');
});

it('records who changed what', function (): void {
    updateSettings($this->admin, 'general', ['site_title' => 'Plant Notes']);

    $change = SettingsChange::query()->sole();

    expect($change->group_key)->toBe('general')
        ->and($change->changed_keys)->toBe(['site_title'])
        ->and($change->before)->toBe([])
        ->and($change->after)->toBe(['site_title' => 'Plant Notes'])
        ->and($change->actor_id)->toBe($this->admin->id)
        ->and($change->origin)->toBe('human');
});

it('refuses a form based on a version that is no longer current', function (): void {
    updateSettings($this->admin, 'general', ['site_title' => 'First']);

    expect(fn () => updateSettings($this->admin, 'general', ['site_title' => 'Second'], version: 0))
        ->toThrow(StaleSettingsException::class);

    expect(app(SettingsReader::class)->string('general', 'site_title'))->toBe('First');
});

it('refuses unknown keys, bad values and secrets sent as values', function (array $values): void {
    expect(fn () => updateSettings($this->admin, 'general', $values))->toThrow(ValidationException::class);
})->with([
    'unknown key' => [['nope' => 'x']],
    'empty required title' => [['site_title' => '']],
    'title too long' => [['site_title' => str_repeat('a', 121)]],
    'bad email' => [['admin_email' => 'not-an-email']],
    'bad time zone' => [['timezone' => 'Mars/Olympus_Mons']],
]);

it('stores a secret encrypted, records only its name, and never reveals it', function (): void {
    updateSettings($this->admin, 'integrations_demo', secrets: ['api_key' => 'sk-live-123']);

    $change = SettingsChange::query()->sole();

    expect(app(SecretReader::class)->secret('integrations_demo', 'api_key'))->toBe('sk-live-123')
        ->and($change->changed_keys)->toBe(['api_key'])
        ->and(json_encode([$change->before, $change->after]))->not->toContain('sk-live-123');
});

it('treats an empty secret as unchanged and null as clear', function (): void {
    updateSettings($this->admin, 'integrations_demo', secrets: ['api_key' => 'sk-live-123']);
    updateSettings($this->admin, 'integrations_demo', secrets: ['api_key' => ''], version: 1);

    expect(app(SecretReader::class)->secret('integrations_demo', 'api_key'))->toBe('sk-live-123');

    updateSettings($this->admin, 'integrations_demo', secrets: ['api_key' => null], version: 1);

    expect(app(SecretReader::class)->secret('integrations_demo', 'api_key'))->toBeNull();
});

it('refuses to overwrite a secret the server environment provides', function (): void {
    putenv('TEME_TEST_API_KEY=from-environment');

    try {
        expect(fn () => updateSettings($this->admin, 'integrations_demo', secrets: ['api_key' => 'other']))
            ->toThrow(ValidationException::class);
    } finally {
        putenv('TEME_TEST_API_KEY');
    }
});

it('writes nothing for someone without the group\'s ability', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => updateSettings($editor, 'general', ['site_title' => 'Hijacked']))
        ->toThrow(AuthorizationException::class);

    expect(SettingsGroupRecord::query()->find('general'))->toBeNull()
        ->and(SettingsChange::query()->count())->toBe(0);
});
