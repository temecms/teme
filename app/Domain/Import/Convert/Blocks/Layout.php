<?php

/**
 * Layouts flattened (I-335): columns, groups, covers, media and
 * text, details, accordions, tabs and Canvas's blocks give what they hold,
 * in order. Teme's theme owns the layout.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert\Blocks;

use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\BlockMapping;
use App\Domain\Import\Convert\Html\HtmlFragment;
use App\Domain\Import\Convert\Html\Nodes;
use App\Domain\Import\Convert\Media\PlayerCards;
use App\Domain\Import\Convert\ParsedBlock;

final readonly class Layout implements BlockMapping
{
    public function blocks(): array
    {
        return [
            'core/columns', 'core/column', 'core/group', 'core/cover', 'core/media-text', 'core/details',
            'core/accordion', 'core/accordion-item', 'core/accordion-heading', 'core/accordion-panel',
            'core/tabs', 'core/tab-panels', 'core/tab-panel', 'core/text-columns', 'canvas/*',
        ];
    }

    public function convert(ParsedBlock $block, BlockContext $context): array
    {
        return match ($block->name) {
            'core/media-text' => [...$this->media($block, $context), ...$context->children($block)],
            'core/cover' => $this->cover($block, $context),
            'core/details' => [...$this->summary($block, $context), ...$context->children($block)],
            'core/accordion-heading' => $this->accordionHeading($block, $context),
            default => $block->innerBlocks === [] ? $context->html($block->innerHtml) : $context->children($block),
        };
    }

    /**
     * Media and text's image or video as a card.
     *
     * @return list<array<string, mixed>>
     */
    private function media(ParsedBlock $block, BlockContext $context): array
    {
        $root = HtmlFragment::body($block->ownHtml());
        $video = $root->querySelector('.wp-block-media-text__media video');

        if ($video !== null && $root->querySelector('.wp-block-media-text__media img') === null) {
            return PlayerCards::element($video, $context, $block->intAttr('mediaId'));
        }

        $img = $context->element($block->ownHtml(), '.wp-block-media-text__media img, figure img');

        if ($img === null) {
            return [];
        }

        $destination = $block->attr('linkDestination');

        return $context->imageCard(
            $img,
            attachmentId: $block->intAttr('mediaId'),
            link: $img->closest('a')?->getAttribute('href'),
            linkDestination: is_string($destination) ? $destination : null,
        );
    }

    /**
     * A cover's blocks, its background image or video left out and reported;
     * the oldest covers' words read from their HTML.
     *
     * @return list<array<string, mixed>>
     */
    private function cover(ParsedBlock $block, BlockContext $context): array
    {
        $root = HtmlFragment::body($block->ownHtml());
        $background = $root->querySelector('.wp-block-cover__image-background, .wp-block-cover__video-background');
        $url = $block->attr('url');

        if ($background !== null || (is_string($url) && $url !== '')) {
            $context->report->count('block.background', 'core/cover');
        }

        if ($block->innerBlocks !== []) {
            return $context->children($block);
        }

        $background?->remove();
        HtmlFragment::prune($root, $context->report);

        return $context->read($root);
    }

    /**
     * A details block's summary as a bold paragraph.
     *
     * @return list<array<string, mixed>>
     */
    private function summary(ParsedBlock $block, BlockContext $context): array
    {
        $summary = $context->element($block->ownHtml(), 'summary');

        return $summary === null ? [] : Nodes::bold($context->textblock('paragraph', $summary));
    }

    /**
     * An accordion's heading, its words read before the toggle button that
     * holds them would be left out, its icon left out.
     *
     * @return list<array<string, mixed>>
     */
    private function accordionHeading(ParsedBlock $block, BlockContext $context): array
    {
        $root = HtmlFragment::body($block->innerHtml);

        foreach (iterator_to_array($root->querySelectorAll('[aria-hidden="true"]')) as $hidden) {
            $hidden->remove();
        }

        $heading = $root->querySelector('h1, h2, h3, h4, h5, h6');
        $words = HtmlFragment::text($root->querySelector('.wp-block-accordion-heading__toggle-title') ?? $heading ?? $root);
        $level = $heading === null ? 3 : (int) substr($heading->localName, 1);

        return $words === '' ? [] : [Nodes::make('heading', Nodes::headingAttrs($level, $context->newId()), [['type' => 'text', 'text' => $words]])];
    }
}
