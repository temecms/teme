<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\AssertionFailedError;
use Teme\Testing\InteractsWithTeme;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class, InteractsWithTeme::class);

beforeEach(fn () => config(['teme.version' => PluginFixtures::TEME]));

afterEach(fn () => PluginFixtures::forget());

it('finds a plugin by its folder', function (): void {
    $plugin = $this->plugin(PluginFixtures::path('hello'));

    expect($plugin->name)->toBe('tests/hello')
        ->and($plugin->code)->toBe('hello');
});

it('finds a plugin by the name Composer installed it under', function (): void {
    PluginFixtures::installed(['tests/hello' => 'hello']);

    expect($this->plugin('tests/hello')->code)->toBe('hello');
});

it('fails the test with the registry’s problem when the plugin can’t be used, or isn’t there', function (): void {
    $broken = PluginFixtures::copy('hello', ['capabilities' => ['nonsense']]);

    expect(fn () => $this->plugin($broken))->toThrow(AssertionFailedError::class, "tests/hello can't be used:")
        ->and(fn () => $this->plugin('tests/nowhere'))->toThrow(AssertionFailedError::class, 'No plugin is named "tests/nowhere"');
});

it('refuses an editor who enables a plugin, as the admin would, and writes nothing', function (): void {
    $editor = $this->person('editor');

    expect(fn () => $this->plugin(PluginFixtures::path('hello'))->enable($editor))
        ->toThrow(AssertionFailedError::class, 'Enabling tests/hello was refused');

    expect(PluginRecord::query()->count())->toBe(0);
});

it('enables as a new administrator, recorded as the one who agreed, so its schedules run', function (): void {
    $plugin = $this->plugin(PluginFixtures::path('jobs'))->enable();

    $record = PluginRecord::query()->where('name', 'tests/jobs')->sole();

    expect($record->state)->toBe(PluginRecord::ENABLED)
        ->and(User::query()->findOrFail($record->consented_by)->hasRole('administrator'))->toBeTrue()
        ->and($plugin->runSchedules(new DateTimeImmutable('2026-11-16 12:00:00')))->toBe(1)
        ->and($plugin->runSchedules(new DateTimeImmutable('2026-11-16 12:30:00')))->toBe(0);
});

it('starts the plugin it enables, beside one already running', function (): void {
    $this->plugin(PluginFixtures::path('hello'))->enable();
    $this->plugin(PluginFixtures::path('jobs'))->enable();

    expect(array_keys(app(LoadedPlugins::class)->running()))->toBe(['tests/hello', 'tests/jobs']);
});

it('disables a plugin through the Operation', function (): void {
    $this->plugin(PluginFixtures::path('hello'))->enable()->disable();

    expect(PluginRecord::query()->where('name', 'tests/hello')->value('state'))->toBe(PluginRecord::DISABLED);
});
