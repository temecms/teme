<?php

declare(strict_types=1);

namespace App\Domain\Sitemaps\Queries;

use App\Domain\Media\MediaDisks;
use Illuminate\Support\Facades\DB;

/**
 * The images a sitemap names for each item (I-296): the live images its
 * published version uses, read from media_usages, each once, at the
 * widest width made, as an absolute address made now; at most MOST an item.
 */
final class SitemapImages
{
    /** At most this many images an address, Google's limit. */
    public const int MOST = 1000;

    /**
     * Each item's image addresses, by item ID, in the order of their uses'
     * references; an item with none is left out.
     *
     * @param  list<string>  $contentIds
     *
     * @return array<string, list<string>>
     */
    public function of(array $contentIds): array
    {
        if ($contentIds === []) {
            return [];
        }

        $images = [];
        $rows = DB::table('media_usages')
            ->join('media', 'media.id', '=', 'media_usages.media_id')
            ->whereIn('media_usages.content_id', $contentIds)
            ->where('media_usages.scope', 'published')
            // A file a link leads to isn't an image the page shows (I-1702).
            ->where('media_usages.ref', 'not like', 'link:%')
            ->where('media.kind', 'image')
            ->whereNull('media.deleted_at')
            ->orderBy('media_usages.content_id')
            ->orderBy('media_usages.ref')
            ->get(['media_usages.content_id', 'media.variants']);

        foreach ($rows as $row) {
            $id = (string) $row->content_id;
            $url = self::widest((array) json_decode((string) $row->variants, true));
            $held = $images[$id] ?? [];

            if ($url !== null && count($held) < self::MOST && ! in_array($url, $held, true)) {
                $images[$id][] = $url;
            }
        }

        return $images;
    }

    /**
     * The widest width made of a media item's variants, as an absolute
     * address, or null while none is made.
     *
     * @param  array<mixed>  $variants
     */
    private static function widest(array $variants): ?string
    {
        $widest = null;
        $width = 0;

        foreach ($variants as $key => $variant) {
            if (preg_match('/^w\d+$/', (string) $key) === 1 && is_array($variant) && is_string($variant['path'] ?? null) && (int) ($variant['width'] ?? 0) > $width) {
                $widest = $variant['path'];
                $width = (int) $variant['width'];
            }
        }

        return $widest === null ? null : url(MediaDisks::public()->url($widest));
    }
}
