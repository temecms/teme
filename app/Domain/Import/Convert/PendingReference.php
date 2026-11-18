<?php

/**
 * A WordPress object the second pass puts into the document once every
 * item exists (I-333): a reusable block's content, inserted at `at`, the
 * path of indexes into the document's content where it goes. References
 * in one parent each name the index as if the others weren't there yet,
 * so the second pass inserts them from the last to the first.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert;

use App\Domain\Import\Phases\SourceRef;

final readonly class PendingReference
{
    /**
     * @param  list<int>  $at
     */
    public function __construct(public SourceRef $source, public array $at) {}

    /**
     * The reference as the posts phase keeps it in the item's facts.
     *
     * @return array{type: string, id: string, at: list<int>}
     */
    public function toArray(): array
    {
        return ['type' => $this->source->type, 'id' => $this->source->id, 'at' => $this->at];
    }
}
