<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Blocks;

use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\BlockMapping;
use App\Domain\Import\Convert\Html\Nodes;
use App\Domain\Import\Convert\ParsedBlock;

/**
 * Core's image and gallery blocks (I-338): Image and Gallery
 * cards by media ID, each image traced to what Teme imported. A gallery
 * keeps one caption, as Teme's Gallery card does.
 */
final readonly class Images implements BlockMapping
{
    /** The most images a Gallery card holds (GalleryCard's rule). */
    private const int GALLERY = 24;

    /** The longest caption a Gallery card keeps; a longer one becomes a paragraph. */
    private const int CAPTION = 2000;

    public function blocks(): array
    {
        return ['core/image', 'core/gallery'];
    }

    public function convert(ParsedBlock $block, BlockContext $context): array
    {
        return $block->name === 'core/gallery' ? $this->gallery($block, $context) : $this->image($block, $context);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function image(ParsedBlock $block, BlockContext $context): array
    {
        $root = $context->fragment($block->innerHtml);
        $img = $root->querySelector('img');

        if ($img === null) {
            return [];
        }

        $caption = $root->querySelector('figcaption');
        $destination = $block->attr('linkDestination');

        return $context->imageCard(
            $img,
            attachmentId: $block->intAttr('id'),
            caption: $caption === null ? null : $context->captionOf($caption),
            link: $img->closest('a')?->getAttribute('href'),
            width: Nodes::width($root->querySelector('figure'), $block->attr('align')),
            decorative: $block->attr('isDecorative') === true,
            linkDestination: is_string($destination) ? $destination : null,
        );
    }

    /**
     * A gallery as Gallery cards of up to 24, its caption on the first, a
     * last chunk of one as that image's own card; a gallery of one as its
     * image's card, the gallery's caption on it or after it; a gallery with
     * no image Teme has, only its caption.
     *
     * @return list<array<string, mixed>>
     */
    private function gallery(ParsedBlock $block, BlockContext $context): array
    {
        $cards = $block->innerBlocks === [] ? $this->olderImages($block, $context) : $context->children($block);
        $outside = array_values(array_filter($cards, self::isOutsideLink(...)));
        $cards = array_values(array_filter($cards, fn (array $node): bool => ! self::isOutsideLink($node)));

        return [...$this->galleryOf($block, $context, $cards), ...$outside];
    }

    /**
     * Whether a node is the paragraph linking to an image from another site,
     * which follows the gallery's cards (I-1703).
     *
     * @param  array<string, mixed>  $node
     */
    private static function isOutsideLink(array $node): bool
    {
        return ($node['type'] ?? null) === 'paragraph' && count($node['content'] ?? []) === 1 && ($node['content'][0]['marks'][0]['type'] ?? null) === 'link';
    }

    /**
     * The cards of a gallery's images, and its caption.
     *
     * @param  list<array<string, mixed>>  $cards
     *
     * @return list<array<string, mixed>>
     */
    private function galleryOf(ParsedBlock $block, BlockContext $context, array $cards): array
    {
        $images = array_values(array_filter($cards, fn (array $node): bool => ($node['type'] ?? null) === 'image'));
        $caption = $context->element($block->ownHtml(), 'figcaption.blocks-gallery-caption');
        $words = $caption === null ? null : $context->captionOf($caption);
        $long = $words !== null && mb_strlen($words) > self::CAPTION;
        $after = $words === null ? [] : [Nodes::paragraph([['type' => 'text', 'text' => $words]])];

        if ($images === []) {
            return $after;
        }

        if (count($images) === 1) {
            // The gallery's caption goes on its one image, unless the image has its own or it's too long.
            if ($words !== null && ! $long && count($cards) === 1 && $cards[0]['attrs']['caption'] === null) {
                $cards[0]['attrs']['caption'] = $words;

                return $cards;
            }

            return [...$cards, ...$after];
        }

        // A caption on one image: on the card, or after it when too long for one.
        foreach ($cards as $node) {
            if (($node['type'] ?? null) !== 'image' || ($node['attrs']['caption'] ?? null) !== null) {
                $context->report->count('gallery.captions');
            }
        }

        $nodes = self::galleries($images, $long ? null : $words, $context);

        return $long ? [...$nodes, ...$after] : $nodes;
    }

    /**
     * Image cards as Gallery cards of 2 to 24, the caption on the first; a
     * chunk of one as that image's own card, its alt included.
     *
     * @param  list<array<string, mixed>>  $images
     *
     * @return list<array<string, mixed>>
     */
    public static function galleries(array $images, ?string $caption, BlockContext $context): array
    {
        $nodes = [];

        foreach (array_chunk($images, self::GALLERY) as $index => $chunk) {
            $shown = $index === 0 ? $caption : null;

            if (count($chunk) === 1) {
                $nodes[] = ['type' => 'image', 'attrs' => [...$chunk[0]['attrs'], 'caption' => $shown]];

                continue;
            }

            $nodes[] = ['type' => 'gallery', 'attrs' => [
                'id' => $context->newId(),
                'mediaIds' => array_column(array_column($chunk, 'attrs'), 'mediaId'),
                'caption' => $shown,
                'layout' => 'grid',
            ]];
        }

        return $nodes;
    }

    /**
     * The images of a gallery saved before WordPress 5.9: its `ids`, in
     * order, and the images in its HTML's list.
     *
     * @return list<array<string, mixed>>
     */
    private function olderImages(ParsedBlock $block, BlockContext $context): array
    {
        $ids = $block->attr('ids');
        $ids = is_array($ids) ? array_values($ids) : [];
        $cards = [];

        foreach (iterator_to_array($context->fragment($block->innerHtml)->querySelectorAll('li img')) as $index => $img) {
            $id = filter_var($ids[$index] ?? null, FILTER_VALIDATE_INT);
            $caption = $img->closest('li')?->querySelector('figcaption');

            array_push($cards, ...$context->imageCard(
                $img,
                attachmentId: is_int($id) ? $id : null,
                caption: $caption === null ? null : $context->captionOf($caption),
            ));
        }

        return $cards;
    }
}
