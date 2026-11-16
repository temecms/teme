<?php

declare(strict_types=1);

use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Site\Policy\PolicyMode;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Teme\Contracts\Insertions\PageInsertion;
use Teme\Contracts\Insertions\PagePosition;
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

/** The page's content security policy, as its header carries it. */
function hiPolicy(TestResponse $response): string
{
    return (string) $response->headers->get((string) PolicyMode::current()->header());
}

/** The markup between the page's <head> and </head>. */
function hiHead(string $html): string
{
    return (string) strstr((string) strstr($html, '<head>'), '</head>', true);
}

it('keeps a script source and an ad network\'s meta in the head, and drops everything the head doesn\'t hold', function (): void {
    $response = addrGet('/')->assertOk();
    $head = hiHead((string) $response->getContent());

    expect($head)->toContain('<script async="" src="https://ads.billboard.example/tag.js" crossorigin="anonymous"></script>')
        ->toContain('<meta name="google-adsense-account" content="ca-pub-1">')
        ->not->toContain('noindex')
        ->not->toContain('a.css')
        ->not->toContain('alert(1)')
        ->not->toContain('<iframe')
        ->and((string) $response->getContent())->not->toContain('frames.billboard.example/x')
        ->and(hiPolicy($response))->toMatch('~script-src [^;]*https://ads\.billboard\.example~');
});

it('keeps a preconnect link to an https origin and a module script, and drops the meta names the page owns', function (): void {
    Billboard::$gives = [new PageInsertion('head', PagePosition::head(), implode('', [
        '<link rel="preconnect" href="https://cdn.billboard.example" crossorigin>',
        '<link rel="dns-prefetch" href="http://plain.billboard.example">',
        '<script type="module" src="https://cdn.billboard.example/m.js" data-x="1"></script>',
        '<script src="javascript:alert(1)"></script>',
        '<meta name="description" content="Mine">',
        '<meta http-equiv="refresh" content="0">',
        '<meta name="viewport" content="width=1">',
        '<base href="https://evil.example/">',
    ]))];

    $head = hiHead((string) addrGet('/')->assertOk()->getContent());

    expect($head)->toContain('<link rel="preconnect" href="https://cdn.billboard.example" crossorigin="">')
        ->toContain('<script type="module" src="https://cdn.billboard.example/m.js"></script>')
        ->not->toContain('plain.billboard.example')
        ->not->toContain('javascript:')
        ->not->toContain('content="Mine"')
        ->not->toContain('http-equiv="refresh"')
        ->not->toContain('width=1')
        ->not->toContain('evil.example');
});

it('draws the top and bottom of the body cleaned as article insertions are', function (): void {
    $html = (string) addrGet('/')->assertOk()->getContent();
    $skip = (int) strpos($html, 'teme-skip-link');
    $top = (int) strpos($html, 'data-test="billboard-top"');
    $bottom = (int) strpos($html, 'data-test="billboard-bottom"');

    expect($html)->toContain('<div data-test="billboard-top">Top</div>')
        ->toContain('<div data-test="billboard-bottom">Bottom</div>')
        ->not->toContain(Billboard::SCRIPT)
        ->and($skip)->toBeLessThan($top)
        ->and($top)->toBeLessThan($bottom)
        ->and($bottom)->toBeLessThan((int) strpos($html, 'data-teme="session"'));
});
