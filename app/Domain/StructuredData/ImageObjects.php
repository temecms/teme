<?php

/**
 * An image as structured data describes it (I-700): an ImageObject at its
 * widest public size, since originals are private, with its caption and
 * its credit, and the three 1,200-pixel crops once they have been made.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\StructuredData;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Plugins\Images\ImageServices;
use Illuminate\Support\Facades\DB;

final readonly class ImageObjects
{
    /** The crops an article's image lists after the image itself (I-700), by their variants' keys. */
    private const array CROPS = ['crop-16x9', 'crop-4x3', 'crop-1x1'];

    /**
     * The image's ImageObject, with no @id, and its crops' addresses; null
     * for anything that isn't a live image with a public size. One
     * statement, and the page records the image's tag, so it goes when the
     * caption or the credit changes.
     *
     * @return array{object: array<string, mixed>, crops: list<string>}|null
     */
    public function for(string $mediaId): ?array
    {
        app(RenderRecord::class)->tag(PageTags::media($mediaId));
        $media = DB::table('media')->where('id', $mediaId)->where('kind', 'image')->whereNull('deleted_at')->first(['variants', 'caption', 'credit']);

        if ($media === null) {
            return null;
        }

        $variants = (array) json_decode((string) $media->variants, true);
        $widest = null;
        $widestKey = '';

        foreach ($variants as $key => $variant) {
            if (preg_match('/^w\d+$/', (string) $key) === 1 && is_array($variant) && is_string($variant['path'] ?? null) && (int) ($variant['width'] ?? 0) > (int) ($widest['width'] ?? 0)) {
                $widest = $variant;
                $widestKey = (string) $key;
            }
        }

        if ($widest === null) {
            return null;
        }

        $images = app(ImageServices::class);
        $url = url($images->address($mediaId, $widestKey, (string) $widest['path']));
        $crops = [];

        foreach (self::CROPS as $key) {
            if (is_array($variants[$key] ?? null) && is_string($variants[$key]['path'] ?? null)) {
                $crops[] = url($images->address($mediaId, $key, $variants[$key]['path']));
            }
        }

        return [
            'object' => [
                '@type' => 'ImageObject',
                'url' => $url,
                'contentUrl' => $url,
                'width' => (int) $widest['width'],
                'height' => (int) ($widest['height'] ?? 0),
                'caption' => $media->caption,
                'creditText' => $media->credit,
            ],
            'crops' => $crops,
        ];
    }
}
