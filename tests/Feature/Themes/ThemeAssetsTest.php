<?php

declare(strict_types=1);

use App\Domain\Themes\ThemeAssets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Blade;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good'), base_path('tests/Fixtures/themes/broken')]]);
    themeActivate('good');
});

it('draws the active theme\'s built assets from its own build folder', function (): void {
    expect(app(ThemeAssets::class)->tags())->toContain('/themes/good/build/assets/theme-');
});

it('draws the same tags wherever a template writes @temeThemeAssets', function (): void {
    expect(Blade::render('@temeThemeAssets'))->toBe(app(ThemeAssets::class)->tags())
        ->toContain('/themes/good/build/assets/theme-');
});

it('leaves the admin\'s Vite configuration alone', function (): void {
    // TestCase swaps Vite for an empty fake (withoutVite()), whose asset()
    // returns '' whatever happens; this test needs the real shared instance.
    $this->withVite();
    $shared = app(Vite::class);

    app(ThemeAssets::class)->tags();

    // Vite has no public accessor for either, so they're read from inside.
    $configuration = fn (): array => [$this->buildDirectory, $this->hotFile];

    expect(app(Vite::class))->toBe($shared)
        ->and($configuration->call($shared))->toBe(['build', null]);
});
