<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

it('imports downshift in one file only, the combobox', function (): void {
    $importers = array_values(array_filter(
        uiTsxFiles(),
        fn (string $file): bool => preg_match("/from ['\"]downshift['\"]/", uiComponent($file)) === 1,
    ));

    expect($importers)->toBe(['components/ui/combobox.tsx']);
});

it('draws a list to search only through the combobox', function (): void {
    // Shrinks only: the media grid is a grid of tiles; the slash menu and the palette move to the combobox in later parts.
    $allowed = ['components/editor/slash-menu.tsx', 'components/media/media-grid.tsx', 'components/palette/command-palette.tsx'];

    $found = array_values(array_filter(
        uiTsxFiles(),
        fn (string $file): bool => ! str_starts_with($file, 'components/ui/')
            && ! in_array($file, $allowed, true)
            && preg_match('/role="(listbox|option|combobox)"/', uiComponent($file)) === 1,
    ));

    expect($found)->toBe([]);
});
