<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\DeclaredSettings;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Settings\SecretReader;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

/*
 * What a plugin may read of the site's settings (I-3364): every setting that
 * isn't a secret, core's and every plugin's, and its own secrets alone.
 */
beforeEach(function (): void {
    $registry = app(SettingsRegistry::class);
    $declared = fn (string $key): array => [
        ['key' => 'token', 'type' => 'secret', 'label' => 'Token', 'description' => 'The token.'],
        ['key' => $key, 'type' => 'text', 'label' => 'Address', 'description' => 'Where it sends.'],
    ];
    $registry->group(DeclaredSettings::group('example-sync', 'Example sync', 'Tells your workspace.', '1.0.0', $declared('project')), 'acme/example-sync');
    $registry->group(DeclaredSettings::group('feeds', 'Feeds', 'Pings feed hubs.', '1.0.0', $declared('hub')), 'acme/feeds');

    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('general', ['site_title' => 'The Field Notes'], [], [], 0, (string) $admin->id));

    $writer = app(SettingsWriter::class);
    $writer->write(new SettingsWrite('plugin.example-sync', ['project' => 'blog'], secrets: ['token' => 'example-token']));
    $writer->write(new SettingsWrite('plugin.feeds', ['hub' => 'https://hub.example/'], secrets: ['token' => 'feeds-token']));
    $writer->write(new SettingsWrite('backups', [], secrets: ['machine_key' => 'backup-key']));
});

/** What Example sync is handed, granted http. */
function exampleOffered(): array
{
    return app(PluginDoors::class)->for('acme/example-sync', 'example-sync', ['http'], ['api.example.com']);
}

it('lets a plugin read core’s settings and another plugin’s, so plugins can check each other’s settings for conflicts', function (): void {
    $reader = exampleOffered()[SettingsReader::class];

    expect($reader->string('general', 'site_title'))->toBe('The Field Notes')
        ->and($reader->string('plugin.feeds', 'hub'))->toBe('https://hub.example/')
        ->and($reader->group('plugin.feeds'))->toBe(['hub' => 'https://hub.example/'])
        ->and($reader->string('plugin.example-sync', 'project'))->toBe('blog');
});

it('never gives a secret through the settings reader, core’s, its own or another plugin’s, stored or not', function (string $group, string $key): void {
    $reader = exampleOffered()[SettingsReader::class];

    expect($reader->group($group))->not->toHaveKey($key)
        ->and(fn () => $reader->get($group, $key))->toThrow(InvalidArgumentException::class, "Unknown setting [{$group}.{$key}].");

    // The same answer once the secret is cleared: not even whether one is stored shows.
    app(SettingsWriter::class)->write(new SettingsWrite($group, [], secrets: [$key => null]));

    expect(fn () => $reader->get($group, $key))->toThrow(InvalidArgumentException::class, "Unknown setting [{$group}.{$key}].");
})->with([
    'core’s' => ['backups', 'machine_key'],
    'its own' => ['plugin.example-sync', 'token'],
    'another plugin’s' => ['plugin.feeds', 'token'],
]);

it('gives a plugin its own secret through the secret reader, and nobody else’s', function (): void {
    $secrets = exampleOffered()[SecretReader::class];

    expect($secrets->secret('plugin.example-sync', 'token'))->toBe('example-token')
        ->and($secrets->secret('plugin.feeds', 'token'))->toBeNull()
        ->and($secrets->secret('backups', 'machine_key'))->toBeNull();
});

it('hands a plugin nothing that writes, registers or changes a setting', function (): void {
    $offered = exampleOffered();
    $before = DB::table('settings')->orderBy('group_key')->get()->toJson();

    foreach ($offered as $contract => $service) {
        $public = array_map(
            fn (ReflectionMethod $method): string => $method->getName(),
            array_filter((new ReflectionObject($service))->getMethods(ReflectionMethod::IS_PUBLIC), fn (ReflectionMethod $method): bool => ! $method->isConstructor()),
        );
        $promised = array_map(fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass($contract))->getMethods());

        // The only extras are statics that write nothing: the reader's cache key's name, the door's curl options.
        $statics = [SettingsReader::class => ['cacheKey'], OutboundHttp::class => ['transport']];

        expect(array_values(array_diff($public, $promised)))->toBe($statics[$contract] ?? []);
    }

    $reader = $offered[SettingsReader::class];

    foreach (app(SettingsRegistry::class)->all() as $group) {
        $reader->group($group->key);
    }

    expect(array_keys($offered))->toEqualCanonicalizing([SettingsReader::class, SecretReader::class, OutboundHttp::class])
        ->and(DB::table('settings')->orderBy('group_key')->get()->toJson())->toBe($before);
});
