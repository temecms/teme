<?php

/**
 * Core's table (I-335): a native table from its HTML, its headers, spans and
 * alignment kept, its caption a paragraph after it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert\Blocks;

use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\BlockMapping;
use App\Domain\Import\Convert\ParsedBlock;

final readonly class Tables implements BlockMapping
{
    public function blocks(): array
    {
        return ['core/table'];
    }

    public function convert(ParsedBlock $block, BlockContext $context): array
    {
        return $context->html($block->innerHtml);
    }
}
