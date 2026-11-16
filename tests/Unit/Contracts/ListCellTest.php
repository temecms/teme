<?php

declare(strict_types=1);

use Teme\Contracts\Lists\Cell;
use Teme\Contracts\Lists\ListRow;
use Teme\Contracts\Lists\ListRows;

it('keeps text exactly as given, markup included', function (): void {
    $cell = Cell::text('<b>x</b>');

    expect($cell->value)->toBe('<b>x</b>')
        ->and($cell->kind)->toBe(Cell::TEXT)
        ->and($cell->tone)->toBeNull();
});

it('refuses a number that is not finite', function (float $number): void {
    expect(fn () => Cell::number($number))->toThrow(InvalidArgumentException::class);
})->with([
    'NAN' => [NAN],
    'INF' => [INF],
    '-INF' => [-INF],
]);

it("refuses a badge with an empty label or a tone it doesn't know", function (string $label, string $tone): void {
    expect(fn () => Cell::badge($label, $tone))->toThrow(InvalidArgumentException::class);
})->with([
    'empty label' => ['', 'neutral'],
    'unknown tone' => ['Ok', 'pink'],
]);

it('gives a badge the neutral tone unless told otherwise', function (): void {
    expect(Cell::badge('Ok')->tone)->toBe('neutral')
        ->and(Cell::badge('Ok', 'warn')->tone)->toBe('warn');
});

it("lists a page's row ids in order", function (): void {
    $rows = new ListRows('post', [
        new ListRow('a', 'published', null, '2026-11-16T00:00:00Z'),
        new ListRow('b', 'draft', 'en', '2026-11-16T01:00:00Z'),
    ]);

    expect($rows->ids())->toBe(['a', 'b']);
});
