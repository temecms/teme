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
use Illuminate\Support\Str;
use Teme\Contracts\Insertions\PageInsertion;
use Teme\Contracts\Insertions\PagePosition;
use Tests\Fixtures\Plugins\Billboard\Billboard;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

/*
 * What a plugin places at a region's start and end: drawn inside the region
 * with its content, and never in a region that waits for a width.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost', 'teme.theme_paths' => [base_path('tests/Fixtures/themes/wide')]]);
    themeActivate('wide');
    PluginFixtures::use('billboard');
    app()->instance(Elevation::class, new OperatorElevation);

    $manifest = app(PluginRegistry::class)->all()['tests/billboard']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/billboard', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), $manifest->hooks(), null));
    app(PluginLoader::class)->load();
    Billboard::reset();

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');
    $this->place = function (string $code, string $region): void {
        $content = regionCreate($this->admin, 'wide', $region);
        scElevated(function () use ($content, $code): void {
            regionSave($content, $this->admin, [['type' => 'site-code', 'attrs' => ['id' => (string) Str::ulid(), 'code' => $code, 'origins' => ['ads.example']]]]);
            listsPublish($content, $this->admin);
        });
    };
    pageArticle($this->editor, 'A story');
});

afterEach(function (): void {
    Billboard::reset();
    PluginFixtures::forget();
});

it('draws what a plugin places at a region\'s start and end inside the region, around its content', function (): void {
    ($this->place)('<div>Region content</div>', 'after-content');
    Billboard::$gives = [
        new PageInsertion('billboard.start', PagePosition::regionStart('after-content'), '<i data-test="billboard-start">Start</i>'),
        new PageInsertion('billboard.end', PagePosition::regionEnd('after-content'), '<i data-test="billboard-end">End</i>'),
    ];

    $html = (string) addrGet('/a-story/')->assertOk()->getContent();

    expect(strpos($html, 'data-test="billboard-start"'))->toBeGreaterThan(strpos($html, 'data-region="after-content"'))
        ->toBeLessThan(strpos($html, 'Region content'))
        ->and(strpos($html, 'Region content'))->toBeLessThan(strpos($html, 'data-test="billboard-end"'));
});

it('draws nothing inside a region that waits for a width, so nothing of it loads below that width', function (): void {
    ($this->place)('<script src="https://ads.example/a.js"></script><div>Half page ad</div>', 'half-page');
    Billboard::$gives = [
        new PageInsertion('billboard.start', PagePosition::regionStart('half-page'), '<i data-test="billboard-start">Start</i>'),
        new PageInsertion('billboard.end', PagePosition::regionEnd('half-page'), '<i data-test="billboard-end">End</i>'),
    ];

    $html = (string) addrGet('/a-story/')->assertOk()->getContent();

    expect($html)->toContain('data-teme-region-min="1440"')
        ->not->toContain('billboard-start')
        ->not->toContain('billboard-end');
});

it('draws nothing inside a region with nothing published', function (): void {
    Billboard::$gives = [new PageInsertion('billboard.start', PagePosition::regionStart('after-content'), '<i data-test="billboard-start">Start</i>')];

    expect((string) addrGet('/a-story/')->assertOk()->getContent())->not->toContain('billboard-start');
});

it('refuses a region slug that is not one', function (): void {
    expect(fn () => PagePosition::regionStart('after content'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PagePosition::regionEnd('After'))->toThrow(InvalidArgumentException::class);
});
