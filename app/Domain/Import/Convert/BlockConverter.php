<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert;

use App\Domain\Cards\BlockIds;
use App\Domain\Cards\Imported\MarkupCleaner;
use App\Domain\Import\Convert\Html\HtmlBlocks;
use App\Domain\Import\Convert\Html\InlineContent;
use App\Domain\Import\Convert\Media\MediaTargets;
use App\Domain\Import\Convert\Shortcodes\ShortcodeMappings;

/**
 * Converts one WordPress item's block tree, as the companion plugin sends
 * it, into a Tiptap document Teme stores (I-335): each block through its
 * row in BlockMappings, a block no row maps by the unmapped rules, then the
 * document fitted. It reads no table and calls no network: the caller
 * hands in the old site's addresses and the media Teme has.
 */
final readonly class BlockConverter
{
    public function __construct(private BlockMappings $mappings, private HtmlBlocks $html, private InlineContent $inline, private MarkupCleaner $cleaner, private ShortcodeMappings $shortcodes) {}

    /**
     * The document, every block in it given an ID (I-3362), the messages
     * saying what was left out or changed, and the reusable blocks the
     * second pass puts in.
     *
     * @param  array<mixed>  $blocks  the companion's `blocks`: parse_blocks()' arrays, freeform chunks with `autop`
     */
    public function convert(array $blocks, ConversionSite $site, MediaTargets $media, ?SourceTargets $targets = null, ?ConversionItem $item = null): Conversion
    {
        $report = new ConversionReport;
        $context = new BlockContext($this->mappings, $this->html, $this->inline, $site, $media, $report, $targets ?? new KnownTargets, $item ?? new ConversionItem, $this->cleaner, $this->shortcodes);
        $parsed = array_values(array_map(fn (mixed $block): ParsedBlock => ParsedBlock::from(is_array($block) ? $block : []), $blocks));
        $fitted = DocumentFit::fit($context->blocks($parsed), $report);

        return new Conversion(['type' => 'doc', 'content' => BlockIds::fill($fitted['content'], $context->newId(...))], $report->messages(), $fitted['references'], $context->links->pending());
    }
}
