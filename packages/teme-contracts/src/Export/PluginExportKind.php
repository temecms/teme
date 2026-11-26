<?php

/**
 * One of a plugin's exports: its format version, its fields in words, and
 * its lines a page at a time, as plain arrays.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Export;

/**
 * One of a plugin's exports: its format version, its fields in words, and
 * its lines a page at a time, as plain arrays. A line's values are text,
 * numbers, booleans, null, or lists and maps of those; a line never sets
 * `kind` or `version`, which core writes first.
 *
 * @since next
 */
interface PluginExportKind
{
    /** The most lines one page may hold. @since next */
    public const int MOST_LINES = 1_000;

    /**
     * This export's format version: 1, raised whenever what a line holds changes.
     *
     * @since next
     */
    public function version(): int;

    /**
     * Every key a line may hold, in order, with what it holds, in words.
     *
     * @return array<string, string>
     *
     * @since next
     */
    public function fields(): array;

    /**
     * One page of lines, at most 1,000: the first page when $after is null,
     * else the page after the `next` the previous page gave. `next` is null
     * on the last page. A page with no lines and a `next` is a fault.
     *
     * @return array{lines: list<array<string, mixed>>, next: string|null}
     *
     * @since next
     */
    public function lines(?string $after): array;
}
