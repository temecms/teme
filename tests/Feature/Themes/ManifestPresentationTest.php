<?php

declare(strict_types=1);

use App\Domain\Themes\ManifestValidator;

it('accepts valid layout, image contexts, fonts and tokens', function (): void {
    $errors = app(ManifestValidator::class)->errors(pageManifest([
        'layout' => ['content' => 680, 'wide' => 1040, 'full' => null],
        'images' => ['feature' => '(min-width: 1100px) 1040px, 100vw'],
        'fonts' => [['family' => 'Newsreader', 'file' => 'fonts/newsreader.woff2', 'weight' => '400 700', 'style' => 'normal', 'preload' => true]],
        'tokens' => ['base' => '#fff', 'contrast' => '#111', 'accent' => '#b3261e'],
    ]), base_path('tests/Fixtures/themes/good'));

    expect($errors)->toBe([]);
});

it('refuses an invalid sizes string, two preloaded fonts, an unknown token, and a missing content context', function (): void {
    $errors = app(ManifestValidator::class)->errors(pageManifest([
        'images' => ['content' => null, 'card' => '100vw, (min-width: 800px) 400px'],
        'fonts' => [
            ['family' => 'A', 'file' => 'a.woff2', 'weight' => '400', 'style' => 'normal', 'preload' => true],
            ['family' => 'B', 'file' => 'b.woff2', 'weight' => '400', 'style' => 'normal', 'preload' => true],
        ],
        'tokens' => ['brand-red' => '#f00'],
    ]), base_path('tests/Fixtures/themes/good'));

    expect(implode("\n", $errors))
        ->toContain('images.card')
        ->toContain('Only one font may be preloaded')
        ->toContain('Unknown token "brand-red"')
        ->toContain('images.content');
});
