<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Blocks;

use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\BlockMapping;
use App\Domain\Import\Convert\Html\Nodes;
use App\Domain\Import\Convert\ParsedBlock;

/**
 * Core's lists (I-335): the format of WordPress 6.1 and later, a list of
 * list-item blocks, and the older one, a list in HTML. An ordered list
 * keeps its start and type.
 */
final readonly class Lists implements BlockMapping
{
    public function blocks(): array
    {
        return ['core/list', 'core/list-item'];
    }

    public function convert(ParsedBlock $block, BlockContext $context): array
    {
        if ($block->name === 'core/list-item') {
            $li = $context->element($block->ownHtml(), 'li');

            return [...($li === null ? [] : $context->read($li)), ...$context->children($block)];
        }

        if ($block->innerBlocks === []) {
            return $context->html($block->innerHtml);
        }

        $list = $context->element($block->ownHtml(), 'ul, ol');
        $items = [];

        foreach ($block->innerBlocks as $inner) {
            $items[] = $inner->name === 'core/list-item' ? $this->item($inner, $context) : Nodes::listItem($context->blocks([$inner]));
        }

        $ordered = $list === null ? $block->attr('ordered') === true : $list->localName === 'ol';

        return Nodes::listOf($ordered, $items, $list === null ? [] : Nodes::listAttrs($list));
    }

    /**
     * A list item: its own words, then its nested lists.
     *
     * @return array<string, mixed>
     */
    private function item(ParsedBlock $item, BlockContext $context): array
    {
        $li = $context->element($item->ownHtml(), 'li');

        return Nodes::listItem([...($li === null ? [] : $context->read($li)), ...$context->children($item)]);
    }
}
