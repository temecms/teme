<?php

/**
 * Cell::link for area table columns that open an outside address.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use Teme\Contracts\Lists\Cell;

it('keeps link text and its address', function (): void {
    $cell = Cell::link('Open report', 'https://ads.example/r/1');

    expect($cell->kind)->toBe(Cell::LINK)
        ->and($cell->value)->toBe('Open report')
        ->and($cell->href)->toBe('https://ads.example/r/1')
        ->and($cell->tone)->toBeNull();
});

it('refuses a link with empty text', function (): void {
    expect(fn () => Cell::link('', 'https://ads.example/r/1'))->toThrow(InvalidArgumentException::class);
});
