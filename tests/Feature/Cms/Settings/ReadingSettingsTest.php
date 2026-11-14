<?php

declare(strict_types=1);

use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SecretStore;
use App\Domain\Settings\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SecretReader;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(SettingsRegistry::class)->group(new SettingsGroup(
        key: 'integrations_demo',
        label: 'Integrations',
        description: 'A group with a secret, for these tests.',
        section: 'integrations',
        ability: 'settings.general.manage',
        settings: [
            Setting::number('limit', 'Limit', 'A number.', '0.1.0')->default(10),
            Setting::secret('api_key', 'API key', 'A credential.', '0.1.0')->env('TEME_TEST_API_KEY'),
        ],
        since: '0.1.0',
    ));
});

it('returns declared defaults when nothing is stored', function (): void {
    expect(app(SettingsReader::class)->string('general', 'site_title'))->toBe('My Teme site')
        ->and(app(SettingsReader::class)->string('general', 'timezone'))->toBe('UTC');
});

it('returns a stored override in place of the default', function (): void {
    SettingsGroupRecord::query()->create(['group_key' => 'general', 'overrides' => ['site_title' => 'Plant Notes'], 'version' => 1]);

    expect(app(SettingsReader::class)->string('general', 'site_title'))->toBe('Plant Notes')
        ->and(app(SettingsReader::class)->string('general', 'tagline'))->toBe('');
});

it('refuses to read a value as the wrong type', function (): void {
    expect(fn () => app(SettingsReader::class)->int('general', 'site_title'))->toThrow(UnexpectedValueException::class);
});

it('refuses an unknown group or key', function (): void {
    expect(fn () => app(SettingsReader::class)->get('nope', 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(SettingsReader::class)->get('general', 'nope'))->toThrow(InvalidArgumentException::class);
});

it('reads a group from the database once per request', function (): void {
    $reader = app(SettingsReader::class);
    DB::enableQueryLog();

    $reader->string('general', 'site_title');
    $reader->string('general', 'tagline');
    $reader->group('general');

    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], '"settings"')))->toHaveCount(1);
});

it('never includes secrets in what the reader returns', function (): void {
    SettingsGroupRecord::query()->create(['group_key' => 'integrations_demo', 'secrets' => ['api_key' => 'sk-live-123'], 'version' => 1]);

    expect(app(SettingsReader::class)->group('integrations_demo'))->toBe(['limit' => 10]);
});

it('stores secrets encrypted and reads them only through the secret reader', function (): void {
    SettingsGroupRecord::query()->create(['group_key' => 'integrations_demo', 'secrets' => ['api_key' => 'sk-live-123'], 'version' => 1]);

    $raw = DB::table('settings')->where('group_key', 'integrations_demo')->value('secrets');

    expect($raw)->not->toContain('sk-live-123')
        ->and(app(SecretReader::class)->secret('integrations_demo', 'api_key'))->toBe('sk-live-123')
        ->and(app(SecretStore::class)->status('integrations_demo', 'api_key'))->toBe(['isSet' => true, 'source' => 'database']);
});

it('lets the server environment supply a secret', function (): void {
    putenv('TEME_TEST_API_KEY=from-environment');

    try {
        expect(app(SecretReader::class)->secret('integrations_demo', 'api_key'))->toBe('from-environment')
            ->and(app(SecretStore::class)->status('integrations_demo', 'api_key'))->toBe(['isSet' => true, 'source' => 'environment']);
    } finally {
        putenv('TEME_TEST_API_KEY');
    }
});
