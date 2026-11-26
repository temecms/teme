<?php

/**
 * One kind of thing a site holds, written as one NDJSON file of the export
 * (I-365): its name, version and fields in words, which the format reference
 * is generated from, and its lines, read a page at a time.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Export;

interface ExportKind
{
    /** The file's name without `.ndjson`, and every line's `kind`: lowercase letters, digits and underscores, starting with a letter. */
    public function name(): string;

    /** This kind's format version: 1, raised whenever what a line holds changes. */
    public function version(): int;

    /** What the file holds, and in what order, for the reference. */
    public function description(): string;

    /** The command's noun, one and many: `item|items`. */
    public function noun(): string;

    /**
     * Every key a line may hold, a nested one as `parent.child`, in order,
     * with what it holds, in the words of the reference.
     *
     * @return array<string, string>
     */
    public function fields(): array;

    /**
     * Each line's keys and values, read a page at a time by keyset; never
     * `kind` or `version`, which the writer adds. A JSONB value is an
     * object (ExportJson::decode()), a nested record a PHP array.
     *
     * @return iterable<array<string, mixed>>
     */
    public function lines(ExportContext $context): iterable;
}
