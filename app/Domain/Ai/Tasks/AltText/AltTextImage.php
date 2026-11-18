<?php

/**
 * The copy of a library image that alt text is asked about (I-319): its
 * 960-pixel variant, never the private original and never a link, decoded
 * and written again as a WebP still, its longer side cut to what a model
 * reads and its bytes under the cap.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Tasks\AltText;

use App\Domain\Ai\AiImage;
use App\Domain\Media\Images\ImageEngine;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\Models\Media;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use League\Flysystem\FilesystemException;

final readonly class AltTextImage
{
    /** The variant I-319 names; a narrower original's own-width copy stands in for it. */
    public const int WIDTH = 960;

    /** Beyond this, Anthropic scales an image down itself, so nothing larger is worth sending. */
    public const int LONGEST_SIDE = 1568;

    /** Under the 4 MB of I-319, and its base64 text is exactly 5 MiB, so it fits a provider that counts that. */
    public const int MAX_BYTES = 3_932_160;

    /** Tried in turn at each size; the first is the variants' own. */
    public const array QUALITIES = [80, 60, 40];

    /** The side is halved while it stays at least this. */
    public const int SMALLEST_SIDE = 320;

    public function __construct(private ImageEngine $engine) {}

    /**
     * Returns the variant a copy is made from: the widest recorded width
     * variant no wider than 960 pixels, or null when the item isn't an image
     * or has none, because its processing failed or hasn't finished.
     *
     * @return array{path: string, width: int, height: int}|null
     */
    public static function variantOf(Media $media): ?array
    {
        if (! $media->isImage()) {
            return null;
        }

        $chosen = null;

        foreach ($media->variants as $key => $variant) {
            $wide = $variant['width'];

            if (preg_match('/^w\d+$/', $key) === 1 && $wide <= self::WIDTH && ($chosen === null || $wide > $chosen['width'])) {
                $chosen = ['path' => $variant['path'], 'width' => $wide, 'height' => $variant['height']];
            }
        }

        return $chosen;
    }

    /**
     * The copy as a provider is sent it: a WebP still, its longer side at
     * most 1,568 pixels, its bytes at most MAX_BYTES.
     *
     * @throws ModelNotFoundException when there is no copy to send, or it can't be read or made small enough
     */
    public function copyOf(Media $media): AiImage
    {
        $variant = self::variantOf($media);
        try {
            $bytes = $variant === null ? null : MediaDisks::public()->get($variant['path']);
        } catch (FilesystemException) {
            // The media disk throws on a missing file rather than answering null.
            $bytes = null;
        }

        // None made, or gone since: nothing is sent, and nothing recorded.
        if (! is_string($bytes) || $bytes === '') {
            throw (new ModelNotFoundException)->setModel(Media::class, [$media->id]);
        }

        // Halving the side quarters the pixels: three sizes at three qualities reach any copy.
        for ($side = self::LONGEST_SIDE; $side >= self::SMALLEST_SIDE; $side = intdiv($side, 2)) {
            foreach (self::QUALITIES as $quality) {
                $made = $this->engine->reencode($bytes, $side, $quality);

                if ($made === null) {
                    throw (new ModelNotFoundException)->setModel(Media::class, [$media->id]);
                }

                if (strlen($made['bytes']) <= self::MAX_BYTES) {
                    return new AiImage('image/webp', $made['bytes']);
                }
            }
        }

        throw (new ModelNotFoundException)->setModel(Media::class, [$media->id]);
    }
}
