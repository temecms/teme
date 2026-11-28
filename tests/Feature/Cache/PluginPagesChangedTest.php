<?php

declare(strict_types=1);

use App\Domain\Cache\PublicChanges;
use App\Domain\Cards\Plugin\CoreCardsChanged;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Site\Insertions\CoreInsertionsChanged;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Plugins\Billboard\Billboard;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

/*
 * A plugin job tells the page cache its insertions or cards changed: named
 * pages are purged after the job's work commits, the whole site at most once
 * a minute, and nothing at all for a plugin that is not running.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    PluginFixtures::use('billboard');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    app()->instance(Elevation::class, new OperatorElevation);

    $manifest = app(PluginRegistry::class)->all()['tests/billboard']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/billboard', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), $manifest->hooks(), null));
    app(PluginLoader::class)->load();
    Billboard::reset();

    $this->author = User::factory()->create()->assignRole('author');
    $this->story = pageArticle($this->author, 'Harbour story');
    $this->folder = cacheOn();
});

afterEach(function (): void {
    Billboard::reset();
    PluginFixtures::forget();

    if (isset($this->folder)) {
        cacheOff($this->folder);
    }
});

it('purges the pages of the named content once the job asks, and nothing else of the page cache', function (): void {
    addrGet('/harbour-story/')->assertOk();
    addrGet('/about-nothing/')->assertNotFound();

    expect(cacheEntry('/harbour-story/'))->not->toBeNull();

    (new CoreInsertionsChanged(app(PublicChanges::class), 'tests/billboard'))->notify([(string) $this->story->id]);

    expect(cacheEntry('/harbour-story/'))->toBeNull();
});

it('purges the whole site when the job names no content, and at most once a minute', function (): void {
    addrGet('/')->assertOk();
    (new CoreInsertionsChanged(app(PublicChanges::class), 'tests/billboard'))->notify();

    expect(cacheEntry('/'))->toBeNull();

    addrGet('/')->assertOk();
    (new CoreInsertionsChanged(app(PublicChanges::class), 'tests/billboard'))->notify();

    expect(cacheEntry('/'))->not->toBeNull();
});

it('writes nothing and purges nothing for a plugin that is not running', function (): void {
    addrGet('/harbour-story/')->assertOk();

    expect(fn () => (new CoreInsertionsChanged(app(PublicChanges::class), 'tests/unknown'))->notify([(string) $this->story->id]))
        ->toThrow(AuthorizationException::class)
        ->and(cacheEntry('/harbour-story/'))->not->toBeNull();
});

it('purges the pages of the named content once a job says its cards changed', function (): void {
    addrGet('/harbour-story/')->assertOk();

    (new CoreCardsChanged('billboard', 'tests/billboard'))->notify([(string) $this->story->id]);

    expect(cacheEntry('/harbour-story/'))->toBeNull();
});
