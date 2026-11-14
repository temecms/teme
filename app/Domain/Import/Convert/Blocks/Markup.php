<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Blocks;

use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\BlockMapping;
use App\Domain\Import\Convert\Html\HtmlFragment;
use App\Domain\Import\Convert\Html\Nodes;
use App\Domain\Import\Convert\ParsedBlock;

/**
 * Blocks that hold markup of their own (I-329): raw HTML as an Imported
 * content card, a formula as its LaTeX in a code block, and a block whose
 * type WordPress no longer had as the block it was; a shortcode block's text
 * read as any HTML, so its shortcodes run.
 */
final readonly class Markup implements BlockMapping
{
    public function blocks(): array
    {
        return ['core/html', 'core/math', 'core/missing', 'core/shortcode'];
    }

    public function convert(ParsedBlock $block, BlockContext $context): array
    {
        return match ($block->name) {
            'core/math' => $this->formula($block, $context),
            'core/missing' => $this->missing($block, $context),
            'core/shortcode' => $context->html($block->innerHtml),
            default => $context->imported($block->innerHtml, 'html-block'),
        };
    }

    /**
     * A formula's LaTeX in a code block; without LaTeX, its words as a
     * paragraph.
     *
     * @return list<array<string, mixed>>
     */
    private function formula(ParsedBlock $block, BlockContext $context): array
    {
        $latex = $block->attr('latex');

        if (is_string($latex) && trim($latex) !== '') {
            $context->report->count('block.formula');

            return [Nodes::make('codeBlock', ['language' => 'latex'], [['type' => 'text', 'text' => trim($latex)]])];
        }

        $words = HtmlFragment::text(HtmlFragment::body($block->innerHtml));

        return $words === '' ? [] : [Nodes::paragraph([['type' => 'text', 'text' => $words]])];
    }

    /**
     * The block WordPress read it as when it still had its type, from its
     * original name and markup; its markup read as any HTML when it names
     * no block.
     *
     * @return list<array<string, mixed>>
     */
    private function missing(ParsedBlock $block, BlockContext $context): array
    {
        $name = $block->attr('originalName');
        $content = $block->attr('originalContent');
        $content = is_string($content) ? $content : $block->ownHtml();

        if (! is_string($name) || $name === '' || strtolower($name) === 'core/missing') {
            return $context->html($content);
        }

        $original = new ParsedBlock(strtolower($name), [], $content, $block->innerBlocks, [$content, ...array_fill(0, count($block->innerBlocks), null)], null);

        return $context->blocks([$original]);
    }
}
