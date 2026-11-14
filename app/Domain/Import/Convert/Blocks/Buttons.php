<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Blocks;

use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\BlockMapping;
use App\Domain\Import\Convert\Html\HtmlFragment;
use App\Domain\Import\Convert\ParsedBlock;
use Dom\Element;
use Illuminate\Support\Str;

/**
 * Buttons as Button cards (I-335): the link's words as the label, an
 * article on the old site by its ID, any other address as it is, an
 * outline button secondary. One the card can't hold becomes a paragraph
 * with its words.
 */
final readonly class Buttons implements BlockMapping
{
    /** The longest label a Button card holds (ButtonCard's rule). */
    private const int LABEL = 80;

    /** The longest address a Button card keeps. */
    private const int LONGEST = 2048;

    public function blocks(): array
    {
        return ['core/buttons', 'core/button'];
    }

    public function convert(ParsedBlock $block, BlockContext $context): array
    {
        if ($block->name === 'core/buttons') {
            return $context->children($block);
        }

        // Read without leaving out buttons: one saved as <button> holds its label.
        $root = HtmlFragment::body($block->innerHtml);
        $link = $root->querySelector('a');
        $label = HtmlFragment::text($link ?? $root);

        if ($label === '') {
            return [];
        }

        $href = $link === null ? '' : trim((string) $link->getAttribute('href'));
        $outline = str_contains(' '.$root->querySelector('.wp-block-button')?->getAttribute('class').' ', ' is-style-outline ');

        // The old site's address is never kept (I-1702): a button to an article holds its ID, any other is a linked paragraph.
        if ($href !== '' && $context->site->isOwn($href)) {
            $contentId = $context->links->content($href);

            return $contentId !== null && mb_strlen($label) <= self::LABEL
                ? [$this->card($context, $label, $contentId, null, $outline)]
                : $this->paragraph($root, $link, $href, $context);
        }

        $url = $link === null ? null : $context->site->absolute($href);
        $url = $url !== null && strlen($url) <= self::LONGEST && Str::isUrl($url, ['http', 'https']) ? $url : null;

        if ($url === null || mb_strlen($label) > self::LABEL) {
            return $this->paragraph($root, $link, $url, $context);
        }

        return [$this->card($context, $label, null, $url, $outline)];
    }

    /**
     * A Button card of an article or an outside address.
     *
     * @return array<string, mixed>
     */
    private function card(BlockContext $context, string $label, ?string $contentId, ?string $url, bool $outline): array
    {
        return ['type' => 'button', 'attrs' => [
            'id' => $context->newId(),
            'label' => $label,
            'contentId' => $contentId,
            'url' => $url,
            'style' => $outline ? 'secondary' : 'primary',
        ]];
    }

    /**
     * A button the card can't hold, as a paragraph of its words, linked
     * when it has an address.
     *
     * @return list<array<string, mixed>>
     */
    private function paragraph(Element $root, ?Element $link, ?string $url, BlockContext $context): array
    {
        $document = $root->ownerDocument;

        if ($link === null || $document === null) {
            return $context->textblock('paragraph', $root);
        }

        $link->removeAttribute('href');

        if ($url !== null) {
            $link->setAttribute('href', $url);
        }

        $paragraph = $document->createElement('p');
        $paragraph->appendChild($link);

        return $context->textblock('paragraph', $paragraph);
    }
}
