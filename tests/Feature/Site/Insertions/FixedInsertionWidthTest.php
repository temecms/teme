<?php

declare(strict_types=1);

use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Insertions\PageInsertion;
use Teme\Contracts\Insertions\PagePosition;
use Tests\Fixtures\Plugins\Billboard\Billboard;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

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
});

afterEach(function (): void {
    Billboard::reset();
    PluginFixtures::forget();
});

it('keeps a fixed insertion that shows from a width out of the page until the screen reaches it', function (): void {
    Billboard::$gives = [new PageInsertion('billboard.wide', PagePosition::fixed('top', 0, 1024), '<div data-test="billboard-wide">Wide</div>')];

    $html = (string) addrGet('/')->assertOk()->getContent();
    $start = strpos($html, '<template data-teme-fixed-from="1024">');
    $end = strpos($html, '</template>', (int) $start);

    expect($start)->not->toBeFalse()
        ->and($end)->toBeGreaterThan($start)
        ->and(strpos($html, 'data-teme-fixed="billboard.wide"'))->toBeGreaterThan($start)
        ->toBeLessThan($end)
        ->and(strpos($html, 'data-test="billboard-wide"'))->toBeGreaterThan($start)
        ->toBeLessThan($end)
        ->and($html)->not->toContain('data-teme-fixed="billboard.wide"]{display:none');
});

it('reveals the width-gated bar with a script that waits for the width and then arms its close button', function (): void {
    Billboard::$gives = [new PageInsertion('billboard.wide', PagePosition::fixed('top', 0, 1024), '<div data-test="billboard-wide">Wide</div>')];

    $html = (string) addrGet('/')->assertOk()->getContent();
    preg_match('~<script data-teme="fixed-insertions">(.*?)</script>~s', $html, $script);

    expect($script)->toHaveCount(2)
        ->and($script[1])->toContain("querySelectorAll('[data-teme-fixed-root] > template[data-teme-fixed-from]')")
        ->toContain("matchMedia('(min-width: '")
        ->toContain('template.content.cloneNode(true)');
});

it('keeps one bar per edge: a later insertion on the same edge stays out, as the first one does', function (): void {
    Billboard::$gives = [
        new PageInsertion('billboard.wide', PagePosition::fixed('top', 0, 1024), '<div data-test="billboard-wide">Wide</div>'),
        new PageInsertion('billboard.plain', PagePosition::fixed('top'), '<div data-test="billboard-plain">Plain</div>'),
    ];

    $html = (string) addrGet('/')->assertOk()->getContent();

    expect($html)->toContain('data-test="billboard-wide"')
        ->not->toContain('data-test="billboard-plain"');
});

it('shows a fixed insertion at every width when it declares none, as it always has', function (): void {
    $html = (string) addrGet('/')->assertOk()->getContent();

    expect($html)->toContain('<aside class="teme-fixed" data-edge="bottom" data-teme-fixed="billboard.bar"')
        ->not->toContain('<template data-teme-fixed-from');
});

it('refuses a width a screen can\'t have', function (): void {
    expect(fn () => PagePosition::fixed('top', 0, 100))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PagePosition::fixed('top', 0, 5000))->toThrow(InvalidArgumentException::class);
});
