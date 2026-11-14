<?php

declare(strict_types=1);

use App\Domain\Regions\RegionWords;

it('says the most blocks a region holds, with block for one', function (int $max, string $words): void {
    expect(RegionWords::limit($max))->toBe($words);
})->with([
    'one' => [1, 'At most 1 block'],
    'five' => [5, 'At most 5 blocks'],
    'fifty' => [50, 'At most 50 blocks'],
]);

it('says what a region takes: text only, one card, or a list of cards', function (array $labels, int $max, string $words): void {
    expect(RegionWords::takes($labels, $max))->toBe($words);
})->with([
    'no card' => [[], 2, 'Takes text only. At most 2 blocks.'],
    'one card' => [['Callout'], 1, 'Takes text and this card: Callout. At most 1 block.'],
    'two cards' => [['Callout', 'Button'], 5, 'Takes text and these cards: Callout and Button. At most 5 blocks.'],
    'three cards' => [['Callout', 'Button', 'Image'], 50, 'Takes text and these cards: Callout, Button and Image. At most 50 blocks.'],
]);

it('says a theme is live, and how many of its regions wait to be filled, with region for one', function (int $empty, string $words): void {
    expect(RegionWords::live('Fixture Good', $empty))->toBe($words);
})->with([
    'none' => [0, 'Fixture Good is live.'],
    'one' => [1, 'Fixture Good is live. 1 region stays empty until you fill it in Content › Regions.'],
    'three' => [3, 'Fixture Good is live. 3 regions stay empty until you fill them in Content › Regions.'],
]);
