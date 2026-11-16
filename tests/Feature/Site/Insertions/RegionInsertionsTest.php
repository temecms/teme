<?php

declare(strict_types=1);

use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Plugins\Billboard\Billboard;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    PluginFixtures::use('billboard');
    // Core's theme draws between items and the after-content region, and accepts the fixtures' Teme version.
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    app()->instance(Elevation::class, new OperatorElevation);

    $manifest = app(PluginRegistry::class)->all()['tests/billboard']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/billboard', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), $manifest->hooks(), null));
    app(PluginLoader::class)->load();
    Billboard::reset();

    $this->author = User::factory()->create()->assignRole('author');
});

afterEach(function (): void {
    Billboard::reset();
    PluginFixtures::forget();
});

it('draws before and after a declared region, also when it is empty, and nothing beside an undeclared one', function (): void {
    pageArticle($this->author, 'Harbour story');

    $html = (string) addrGet('/harbour-story/')->assertOk()->getContent();
    $before = (int) strpos($html, 'data-test="billboard-before-region"');
    $after = (int) strpos($html, 'data-test="billboard-after-region"');

    expect($html)->toContain('<div data-test="billboard-before-region">Before</div>')
        ->toContain('<div data-test="billboard-after-region">After</div>')
        ->not->toContain('billboard-nowhere')
        ->not->toContain('data-region="after-content"')
        ->and($before)->toBeGreaterThan(0)
        ->and($before)->toBeLessThan($after);
});
