<?php

declare(strict_types=1);

use Teme\Contracts\Editor\PanelPreview;
use Teme\Contracts\Editor\PanelRow;
use Teme\Contracts\Editor\PanelView;

it('keeps a row’s label, value and tone as given', function (): void {
    $row = new PanelRow('Status', 'Queued', 'info');

    expect([$row->label, $row->value, $row->tone])->toBe(['Status', 'Queued', 'info']);
});

it('refuses a row whose label, value or tone is out of bounds', function (string $label, string $value, ?string $tone): void {
    expect(fn () => new PanelRow($label, $value, $tone))->toThrow(InvalidArgumentException::class);
})->with([
    'empty label' => ['', 'x', null],
    'long label' => [str_repeat('l', 61), 'x', null],
    'long value' => ['Label', str_repeat('v', 121), null],
    'unknown tone' => ['Label', 'x', 'pink'],
]);

it('refuses a preview with no title or a long description', function (string $title, string $description): void {
    expect(fn () => new PanelPreview($title, $description))->toThrow(InvalidArgumentException::class);
})->with([
    'empty title' => ['', ''],
    'long title' => [str_repeat('t', 121), ''],
    'long description' => ['Title', str_repeat('d', 301)],
]);

it('refuses more than ten rows, a saved value that is too long, or an empty rows heading', function (): void {
    $row = new PanelRow('Label', 'x');

    expect(fn () => new PanelView(rows: array_fill(0, 11, $row)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PanelView(saved: ['message' => str_repeat('m', 2001)]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PanelView(rowsHeading: ''))->toThrow(InvalidArgumentException::class);
});

it('gives a view with none of the new parts the same shape as before', function (): void {
    $view = new PanelView(status: 'Done');

    expect([$view->rows, $view->preview, $view->saved, $view->savedAt, $view->rowsHeading])
        ->toBe([[], null, [], null, null]);
});
