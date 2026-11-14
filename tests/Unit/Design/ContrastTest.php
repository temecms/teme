<?php

declare(strict_types=1);

use App\Domain\Design\Contrast;
use App\Domain\Themes\Manifest;

it('computes WCAG contrast ratios', function (string $a, string $b, float $ratio): void {
    expect(Contrast::ratio($a, $b))->toBe($ratio);
})->with([
    ['#000000', '#ffffff', 21.0],
    ['#ffffff', '#ffffff', 1.0],
    ['#b3261e', '#ffffff', 6.54],
    ['#777777', '#ffffff', 4.48],
]);

it('picks the readable text colour for an accent', function (): void {
    expect(Contrast::onColor('#b3261e'))->toBe('#ffffff')
        ->and(Contrast::onColor('#ffd54f'))->toBe('#111111');
});

it('checks the accent against the light background only (I-457)', function (): void {
    $problems = Contrast::accentProblems('#ff8a80', '#ffffff');

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('light background')
        // Only 2.35:1 against the default theme's dark background: the dark scheme lightens it instead.
        ->and(Contrast::accentProblems('#0b5394', '#fcfbf8'))->toBe([]);
});

it('draws the AA line between two neighbouring greys, and checks the text on the accent too', function (): void {
    expect(Contrast::accentProblems('#767676', '#ffffff'))->toBe([])
        ->and(Contrast::accentProblems('#777777', '#ffffff'))->toHaveCount(2);
});

it('lightens an accent just enough for the dark scheme, keeping its hue', function (string $accent, string $darkBase, string $variant): void {
    expect(Contrast::darkVariant($accent, $darkBase))->toBe($variant)
        ->and(Contrast::ratio($variant, $darkBase))->toBeGreaterThanOrEqual(4.5)
        ->and(Contrast::ratio($variant, Contrast::onColor($variant)))->toBeGreaterThanOrEqual(4.5);
})->with([
    'the default theme\'s red' => ['#b3261e', '#141413', '#dd5143'],
    'a blue' => ['#0b5394', '#141413', '#4283c8'],
    'already light enough: unchanged' => ['#ff8a7a', '#141413', '#ff8a7a'],
    'written short and in capitals: unchanged, written out' => ['#F00', '#141413', '#ff0000'],
    'white: unchanged' => ['#ffffff', '#141413', '#ffffff'],
    'black, which has no hue: a grey' => ['#000000', '#141413', '#808080'],
    'on pure black, past #777777, whose text neither colour can read' => ['#000000', '#000000', '#7d7d7d'],
    'a dark scheme that keeps the light background: unchanged' => ['#b3261e', '#fcfbf8', '#b3261e'],
]);

it('gives the accent on each scheme the theme can show', function (): void {
    $withDark = new Manifest(['tokens' => ['base' => '#fcfbf8'], 'tokens_dark' => ['base' => '#141413']]);
    $lightOnly = new Manifest(['tokens' => ['base' => '#fcfbf8']]);

    expect(Contrast::accentSchemes('#b3261e', $withDark))->toBe([
        'light' => ['accent' => '#b3261e', 'onAccent' => '#ffffff', 'ratio' => 6.32],
        'dark' => ['accent' => '#dd5143', 'onAccent' => '#111111', 'ratio' => 4.68],
    ])
        ->and(Contrast::accentSchemes('#b3261e', $lightOnly)['dark'])->toBeNull();
});

it('gives the same answers in the admin\'s contrast.ts, run in Node', function (): void {
    $accents = [
        ['accent' => '#767676', 'base' => '#ffffff'],
        ['accent' => '#777777', 'base' => '#ffffff'],
        ['accent' => '#ff8a80', 'base' => '#ffffff'],
        ['accent' => '#ffd54f', 'base' => '#ffffff'],
    ];
    $variants = [
        ['#b3261e', '#141413'], ['#0b5394', '#141413'], ['#ff8a7a', '#141413'], ['#F00', '#141413'], ['#ffffff', '#141413'],
        ['#000000', '#141413'], ['#000000', '#000000'], ['#b3261e', '#fcfbf8'], ['#2446a8', '#141413'], ['#7a1fa2', '#121212'],
    ];

    $node = guardNode('contrast.mjs', [
        'candidates' => Contrast::ON_COLORS,
        'ratios' => [['#b3261e', '#ffffff'], ['#767676', '#ffffff'], ['#777777', '#ffffff']],
        'accents' => $accents,
        'variants' => $variants,
    ]);

    expect($node['ratios'])->toBe([6.54, 4.54, 4.48])
        ->and(array_column($node['accents'], 'onColor'))->toBe(['#ffffff', '#ffffff', '#111111', '#111111'])
        ->and(array_map('count', array_column($node['accents'], 'problems')))->toBe([0, 2, 1, 1])
        ->and(array_column($node['accents'], 'problems'))->toBe(array_map(fn (array $case): array => Contrast::accentProblems($case['accent'], $case['base']), $accents))
        ->and($node['variants'])->toBe(array_map(fn (array $pair): string => Contrast::darkVariant(...$pair), $variants))
        ->and($node['variants'])->toBe(['#dd5143', '#4283c8', '#ff8a7a', '#ff0000', '#ffffff', '#808080', '#7d7d7d', '#b3261e', '#517ae1', '#af5ada']);
});
