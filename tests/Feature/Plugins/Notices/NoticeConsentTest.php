<?php

declare(strict_types=1);

use App\Domain\Plugins\Consent;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\PluginState;
use App\Domain\Plugins\Queries\PluginList;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PluginFixtures::loadFromOriginal('relay');
    app()->instance(Elevation::class, new OperatorElevation);

    $this->enable = function (string $name): PluginRecord {
        $manifest = app(PluginRegistry::class)->find($name)?->manifest;
        app(EnablePlugin::class)->execute(new EnablePluginInput($name, $manifest->version(), $manifest->capabilities(), $manifest->hosts(), $manifest->hooks(), null));

        return PluginRecord::query()->where('name', $name)->sole();
    };
});

afterEach(fn () => PluginFixtures::forget());

it('keeps the kinds of notice and the plugins it requires with what was agreed', function (): void {
    PluginFixtures::use('relay', 'relay-pro');

    expect(($this->enable)('tests/relay')->consent)->toMatchArray(['notices' => ['job_failed', 'queue_stalled']])
        ->not->toHaveKey('requires')
        ->and(($this->enable)('tests/relay-pro')->consent)->toMatchArray(['requires' => ['tests/relay']])
        ->not->toHaveKey('notices');
});

it('asks again when an update adds a kind of notice or a plugin it requires', function (): void {
    $manifest = new Manifest([...PluginFixtures::manifest('relay'), 'requires' => ['teme' => '^0.5', 'plugins' => ['tests/relay-pro' => '^1.0']]]);
    $agreed = ['capabilities' => $manifest->capabilities(), 'hosts' => [], 'notices' => ['job_failed', 'queue_stalled'], 'requires' => ['tests/relay-pro']];

    expect(Consent::covers($agreed, $manifest))->toBeTrue()
        ->and(Consent::covers(['notices' => ['job_failed']] + $agreed, $manifest))->toBeFalse()
        ->and(Consent::added(['notices' => ['job_failed']] + $agreed, $manifest)['notices'] ?? null)->toBe(['queue_stalled'])
        ->and(Consent::covers(['requires' => []] + $agreed, $manifest))->toBeFalse()
        ->and(Consent::added(['requires' => []] + $agreed, $manifest)['requires'] ?? null)->toBe(['tests/relay-pro']);
});

it('keeps an updated plugin that adds a kind of notice waiting, unstarted, until someone agrees', function (): void {
    PluginFixtures::use('relay');
    ($this->enable)('tests/relay')->forceFill(['consent' => [...PluginRecord::query()->sole()->consent, 'notices' => ['job_failed']]])->save();
    app(PluginRegistry::class)->flush();

    app(PluginLoader::class)->load();
    $row = collect(app(PluginList::class)->all())->firstWhere('name', 'tests/relay');

    expect(app(LoadedPlugins::class)->running())->not->toHaveKey('tests/relay')
        ->and($row?->state)->toBe(PluginState::Waiting);
});
