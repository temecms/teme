<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\DeclaredSettings;
use App\Domain\Plugins\Doors\PluginSecrets;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Settings\SecretReader;

uses(RefreshDatabase::class);

it('gives a plugin its own secrets and nobody else’s', function (): void {
    $registry = app(SettingsRegistry::class);
    $token = [['key' => 'token', 'type' => 'secret', 'label' => 'Token', 'description' => 'The token.']];
    $registry->group(DeclaredSettings::group('example-sync', 'Example sync', 'Tells your workspace.', '1.0.0', $token), 'acme/example-sync');
    $registry->group(DeclaredSettings::group('feeds', 'Feeds', 'Pings feed hubs.', '1.0.0', $token), 'acme/feeds');

    $writer = app(SettingsWriter::class);
    $writer->write(new SettingsWrite('plugin.example-sync', [], secrets: ['token' => 'example-token']));
    $writer->write(new SettingsWrite('plugin.feeds', [], secrets: ['token' => 'feeds-token']));
    $writer->write(new SettingsWrite('backups', [], secrets: ['machine_key' => 'backup-key']));

    $core = app(SecretReader::class);
    $secrets = new PluginSecrets('example-sync', $core);

    expect($core->secret('backups', 'machine_key'))->toBe('backup-key')
        ->and($secrets->secret('plugin.example-sync', 'token'))->toBe('example-token')
        ->and($secrets->secret('plugin.feeds', 'token'))->toBeNull()
        ->and($secrets->secret('backups', 'machine_key'))->toBeNull()
        ->and($secrets->secret('plugin.example-sync-2', 'token'))->toBeNull();
});
