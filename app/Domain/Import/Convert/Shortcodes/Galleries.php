<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Shortcodes;

use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\Blocks\Images;

/**
 * WordPress's gallery shortcode as Gallery cards (I-336), its images in the
 * order WordPress shows them. Columns, size, link and the tags are the
 * theme's, and a random order can't be stored.
 */
final readonly class Galleries implements ShortcodeMapping
{
    public function shortcodes(): array
    {
        return ['gallery'];
    }

    public function convert(Shortcode $shortcode, BlockContext $context): array
    {
        $ids = self::attachments($shortcode, $context);

        if ($ids === []) {
            $context->report->count('gallery.empty');

            return [];
        }

        $images = [];

        foreach ($ids as $id) {
            array_push($images, ...$context->attachmentImage($id));
        }

        return Images::galleries($images, null, $context);
    }

    /**
     * The attachment IDs a gallery or a playlist shows, in WordPress's
     * order: `ids` as given; else `include`, by ID; else the files of this
     * type attached to `id` or the item, without `exclude`'s (WordPress
     * excludes from attached files only); the last two reversed by
     * `order="DESC"`.
     *
     * @return list<int>
     */
    public static function attachments(Shortcode $shortcode, BlockContext $context, string $type = 'image'): array
    {
        $ids = self::ids($shortcode->attribute('ids'));

        if ($ids !== []) {
            return $ids;
        }

        $included = self::ids($shortcode->attribute('include'));
        $post = (int) $shortcode->attribute('id');
        $post = $post > 0 ? $post : $context->item->id;
        $found = $included !== [] ? $included : ($post > 0 ? $context->targets->attached($post, $type) : []);

        if ($included !== []) {
            sort($found);
        } else {
            $found = array_values(array_diff($found, self::ids($shortcode->attribute('exclude'))));
        }

        return strtoupper(trim($shortcode->attribute('order'))) === 'DESC' ? array_reverse($found) : $found;
    }

    /**
     * The positive whole numbers in a comma-separated list, in order.
     *
     * @return list<int>
     */
    private static function ids(string $list): array
    {
        $ids = [];

        foreach (explode(',', $list) as $id) {
            $id = filter_var(trim($id), FILTER_VALIDATE_INT);

            if (is_int($id) && $id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
