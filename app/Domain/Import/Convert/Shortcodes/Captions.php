<?php

/**
 * WordPress's caption shortcode, `[caption]` and `[wp_caption]` (I-336),
 * in both its forms: the caption as an attribute, or as the words after
 * the image. As WordPress shows it, a caption needs a width of at least 1;
 * an `attachment_N` ID traces the image.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert\Shortcodes;

use App\Domain\Import\Convert\BlockContext;

final readonly class Captions implements ShortcodeMapping
{
    public function shortcodes(): array
    {
        return ['caption', 'wp_caption'];
    }

    public function convert(Shortcode $shortcode, BlockContext $context): array
    {
        $root = $context->fragment($shortcode->content ?? '');
        $img = $root->querySelector('img');

        if ($img === null) {
            return $context->read($root);
        }

        $link = $img->closest('a');
        ($link ?? $img)->remove();
        $older = $shortcode->attribute('caption');
        $caption = match (true) {
            (int) $shortcode->attribute('width') < 1 => null,
            $older !== '' => $context->captionOf($context->fragment($older)),
            default => $context->captionOf($root),
        };
        $id = preg_match('/^attachment_(\d+)$/', $shortcode->attribute('id'), $found) === 1 ? (int) $found[1] : null;

        return $context->imageCard($img, caption: $caption, link: $link?->getAttribute('href'), captionId: $id);
    }
}
