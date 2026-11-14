<?php

declare(strict_types=1);

use App\Domain\Themes\Reference\ViewDataPin;

it('adds a new value, keeps an unchanged one, and remembers a changed type under was', function (): void {
    $pinned = ['A::$x' => ['type' => 'string'], 'A::f()' => ['type' => '(): mixed']];
    $current = ['A::$x' => 'string', 'A::f()' => '(): ?B', 'A::$y' => 'int'];

    expect(ViewDataPin::updated($pinned, $current))->toBe([
        'A::$x' => ['type' => 'string'],
        'A::$y' => ['type' => 'int'],
        'A::f()' => ['type' => '(): ?B', 'was' => ['(): mixed']],
    ]);
});

it('marks a value the code no longer has as gone, and never drops it', function (): void {
    expect(ViewDataPin::updated(['A::$old' => ['type' => 'string']], []))->toBe(['A::$old' => ['type' => 'string', 'gone' => true]]);
});

it('brings a gone value back without its mark, keeping what it was', function (): void {
    expect(ViewDataPin::updated(['A::$x' => ['type' => 'int', 'was' => ['string'], 'gone' => true]], ['A::$x' => 'int']))
        ->toBe(['A::$x' => ['type' => 'int', 'was' => ['string']]]);
});

it('asks for a changelog line for each changed or gone value, under Theme view data only', function (): void {
    $entries = ['A::$x' => ['type' => 'int', 'was' => ['string']], 'A::$old' => ['type' => 'string', 'gone' => true], 'A::$y' => ['type' => 'int']];
    $changelog = "## [0.4.0]\n\n### Theme view data\n\n- `A::\$x` is now a number.\n\n### Fixed\n\n- `A::\$old` mentioned elsewhere.\n";

    expect(ViewDataPin::unnoted($entries, $changelog))->toBe(['A::$old']);
});

it('finds its changelog heading when the part is written in brackets', function (): void {
    $entries = ['A::$x' => ['type' => 'int', 'was' => ['string']]];
    $changelog = "## [0.4.0]\n\n### Theme view data (4.1b)\n\n- `A::\$x` is now a number.\n";

    expect(ViewDataPin::unnoted($entries, $changelog))->toBe([]);
});
