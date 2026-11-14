<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Blocks;

use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\BlockMapping;
use App\Domain\Import\Convert\ParsedBlock;
use App\Domain\Import\Phases\SourceRef;

/**
 * A reusable block (I-333): left for the second pass, which inlines the
 * `wp_block` post it names once every item exists. One with no reference
 * is left out.
 */
final readonly class Reusable implements BlockMapping
{
    public function blocks(): array
    {
        return ['core/block'];
    }

    public function convert(ParsedBlock $block, BlockContext $context): array
    {
        $ref = $block->intAttr('ref');

        return $ref === null || $ref < 1 ? [] : $context->reference(SourceRef::of('post', $ref));
    }
}
