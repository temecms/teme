<?php

/**
 * The notes of a reader's export file: what the file holds that Teme doesn't
 * bring, as the file's report stores them, each named for the plan and the
 * run's report with the first few IDs of the items it counts.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File;

/**
 * Turns a file report's notes into the rows the plan and the run's report show.
 *
 * @since next
 */
final class FileNotes
{
    /** The plain name each note kind is grouped under on the plan and in the run's report. */
    private const array GROUPS = [
        'comment' => 'Comments not brought',
        'menu-item' => 'Menu items not brought',
        'term' => 'Terms not brought',
        'featured-image' => 'Featured pictures not brought',
        'menu-target' => 'Menu items with nothing to point at',
        'language' => 'More than one language',
        'parent' => 'Items placed at the top',
    ];

    /**
     * Returns each note of a file report as a row: its kind, its plain name, how many items it counts, the first few IDs and its sentence.
     *
     * @param  array<array-key, mixed>  $notes  the file report's `notes`, as decoded from JSON
     *
     * @return list<array{kind: string, group: string, count: int, examples: list<string>, sentence: string}>
     *
     * @since next
     */
    public static function rows(array $notes): array
    {
        $rows = [];

        foreach ($notes as $kind => $note) {
            if (! is_array($note) || ! is_string($kind)) {
                continue;
            }

            $rows[] = [
                'kind' => $kind,
                'group' => self::GROUPS[$kind] ?? $kind,
                'count' => (int) ($note['count'] ?? 0),
                'examples' => array_values(array_map(strval(...), is_array($note['examples'] ?? null) ? $note['examples'] : [])),
                'sentence' => (string) ($note['sentence'] ?? ''),
            ];
        }

        return $rows;
    }
}
