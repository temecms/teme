<?php

/**
 * A phase that reads lists of objects from the old site, a page at a time
 * in ID order, and imports each one through ImportObject, in its own
 * transaction with its map row (I-332).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases;

interface ListPhase extends ImportPhase
{
    /**
     * The lists it reads, the same ones in the same order for the same run.
     *
     * @return list<ImportList>
     */
    public function lists(ImportContext $context): array;

    /**
     * Which object an item is, read from its `id` alone: an item WordPress
     * couldn't load carries only `id` and `error`.
     *
     * @param  array<string, mixed>  $item
     */
    public function identify(ImportList $list, array $item, int|string $key): SourceRef;

    /**
     * Imports one object through Operations, inside its transaction: never
     * a download or anything slow here (PreparesObjects is for that).
     *
     * @param  array<string, mixed>  $item
     *
     * @throws ImportFailed when this object can't be imported; the run goes on
     * @throws ImportStopped when the whole run must stop
     */
    public function import(SourceRef $ref, array $item, ImportContext $context, ?PreparedObject $prepared): ImportResult;
}
