<?php

/**
 * A row of the block mapping registry (I-349): the WordPress blocks it
 * converts, and the Tiptap nodes each becomes. Core's rows are listed in
 * BlockMappings::CORE; a plugin tags its own under BlockMappings::TAG.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert;

interface BlockMapping
{
    /**
     * The block names it converts: `namespace/name`, or every block of a
     * namespace as `namespace/*`, in lower case.
     *
     * @return list<string>
     */
    public function blocks(): array;

    /**
     * The nodes the block becomes, in order: none to leave it out. A row
     * reports what it leaves out through the context's report.
     *
     * @return list<array<string, mixed>>
     */
    public function convert(ParsedBlock $block, BlockContext $context): array;
}
