<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

/**
 * The lines of a baseline whose path starts with one of these. Paths are
 * relative to resources/js; the design check writes them with "js/" first.
 *
 * @param  list<string>  $paths
 *
 * @return list<string>
 */
function tsLines(string $baseline, int $column, string $prefix, array $paths): array
{
    $lines = file(base_path("tests/Architecture/{$baseline}"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    return array_values(array_filter($lines, function (string $line) use ($column, $prefix, $paths): bool {
        $path = explode("\t", $line)[$column] ?? '';

        foreach ($paths as $owned) {
            if (str_starts_with($path, $prefix.$owned)) {
                return true;
            }
        }

        return false;
    }));
}

dataset('tool screens', [
    'Import' => [['pages/tools/import/', 'components/import/']],
]);

it('leaves no design-system line on :dataset', function (array $paths): void {
    expect(tsLines('design-system-baseline.txt', 1, 'js/', $paths))->toBe([]);
})->with('tool screens');

it('leaves every word on :dataset to the translation helper', function (array $paths): void {
    expect(tsLines('admin-words-baseline.txt', 0, '', $paths))->toBe([]);
})->with('tool screens');
