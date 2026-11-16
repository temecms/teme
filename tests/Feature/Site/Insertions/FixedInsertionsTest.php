<?php

declare(strict_types=1);

use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Site\Insertions\FixedInsertions;
use App\Domain\Site\Insertions\PageInsertions;
use App\Domain\Site\Policy\PolicyMode;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Themes\ComposerRegistry;
use App\Domain\Themes\ViewIntent;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Teme\Contracts\Insertions\PageFacts;
use Teme\Contracts\Insertions\PageInsertion;
use Teme\Contracts\Insertions\PageInsertionContributor;
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

it('fixes the first insertion for each edge to it, with the plugin\'s label and a close button', function (): void {
    $response = addrGet('/')->assertOk();
    $html = (string) $response->getContent();

    expect(substr_count($html, '<aside class="teme-fixed"'))->toBe(2)
        ->and($html)->toContain('<aside class="teme-fixed" data-edge="bottom" data-teme-fixed="billboard.bar" aria-label="Sponsored">')
        ->toContain('<div data-test="billboard-bar">Bar</div>')
        ->not->toContain('billboard-second-bar')
        ->toContain('<span>Sponsored</span><button type="button" class="teme-fixed-close" data-teme-fixed-close aria-label="Close">')
        ->toContain('<aside class="teme-fixed" data-edge="right" data-teme-fixed="billboard.rail" aria-label="Sponsored" style="--teme-rail:300px">')
        ->and(strpos($html, 'data-teme-fixed="billboard.bar"'))->toBeGreaterThan(strpos($html, 'data-test="billboard-bottom"'));
});

it('lists core\'s close script by its hash in the page\'s policy', function (): void {
    $response = addrGet('/')->assertOk();
    $html = (string) $response->getContent();
    preg_match('~<script data-teme="fixed-insertions">(.*?)</script>~s', $html, $script);

    expect($script)->toHaveCount(2)
        ->and((string) $response->headers->get((string) PolicyMode::current()->header()))
        ->toContain("'sha256-".base64_encode(hash('sha256', $script[1], true))."'");
});

it('shows a rail only where the screen has room beside the theme\'s content', function (): void {
    // The default theme's content is 680 wide: 680 + 2 × (300 + 48) = 1376.
    $html = (string) addrGet('/')->assertOk()->getContent();

    expect($html)->toContain('@media (max-width:1375px){.teme-fixed[data-teme-fixed="billboard.rail"]{display:none!important}}')
        ->not->toContain('data-teme-fixed="billboard.bar"]{display:none');
});

it('labels what a plugin fixes "Advertisement" when it declares no label', function (): void {
    Billboard::$gives = [];
    $insertions = app(PageInsertions::class);
    $insertions->start(request(), ['kind' => 'view', 'intent' => new ViewIntent('latest', isFront: true), 'pageNumber' => 1], 'en');
    app(ComposerRegistry::class)->startPage(new ViewIntent('latest', isFront: true));
    $insertions->bind(new class implements PageInsertionContributor
    {
        public function pageInsertions(PageFacts $page): array
        {
            return [new PageInsertion('plain.bar', PagePosition::fixed('top'), '<p>Plain</p>')];
        }
    }, ['pages.insert'], 'tests/plain');

    expect(app(FixedInsertions::class)->all())->toBe([[
        'key' => 'plain.bar', 'edge' => 'top', 'width' => 0, 'minWidth' => 0, 'label' => 'Advertisement', 'html' => '<p>Plain</p>',
    ]]);
});

it('draws nothing fixed under a theme that refuses it', function (): void {
    $root = storage_path('framework/testing/fixed-themes');
    File::deleteDirectory($root);
    File::copyDirectory(base_path('tests/Fixtures/themes/good'), "{$root}/unfixed");
    $manifest = (array) json_decode((string) file_get_contents("{$root}/unfixed/teme-theme.json"), true);
    file_put_contents("{$root}/unfixed/teme-theme.json", json_encode(['code' => 'unfixed', 'requires' => ['teme' => '>=0.1.0-dev <1.0'], 'fixed' => false] + $manifest));
    config(['teme.theme_paths' => ["{$root}/unfixed"]]);
    themeActivate('unfixed');

    try {
        expect((string) addrGet('/')->assertOk()->getContent())->not->toContain('teme-fixed')
            ->toContain('data-test="billboard-top"');
    } finally {
        File::deleteDirectory($root);
    }
});
