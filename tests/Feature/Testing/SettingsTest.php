<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\AssertionFailedError;
use Teme\Contracts\Settings\SecretReader;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Testing\InteractsWithTeme;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class, InteractsWithTeme::class);

beforeEach(fn () => config(['teme.version' => PluginFixtures::TEME]));

afterEach(fn () => PluginFixtures::forget());

it('changes the plugin’s own settings, its secret kept apart, read back as the plugin reads them', function (): void {
    $this->plugin(PluginFixtures::path('contributor'))->enable()
        ->changeSettings(['project' => 'north', 'token' => 's3cret']);

    expect(app(SettingsReader::class)->get('plugin.contributor', 'project'))->toBe('north')
        ->and(app(SecretReader::class)->secret('plugin.contributor', 'token'))->toBe('s3cret');
});

it('changes any group it names', function (): void {
    $this->plugin(PluginFixtures::path('contributor'))->enable()
        ->changeSettings('general', ['site_title' => 'Tested']);

    expect(app(SettingsReader::class)->string('general', 'site_title'))->toBe('Tested');
});

it('refuses an editor who changes them, and writes nothing', function (): void {
    $plugin = $this->plugin(PluginFixtures::path('contributor'))->enable();
    $editor = $this->person('editor');

    expect(fn () => $plugin->changeSettings(['project' => 'south'], $editor))
        ->toThrow(AssertionFailedError::class, 'Changing the settings in plugin.contributor was refused');

    expect(app(SettingsReader::class)->get('plugin.contributor', 'project'))->not->toBe('south');
});
