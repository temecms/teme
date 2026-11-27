<?php

/**
 * Tells whether an image file is animated from its own structure, without
 * decoding a single frame: a GIF with more than one image, a WebP with an
 * animation chunk, a PNG with an animation control chunk. Decoding every frame
 * to find out runs out of memory on a long animation (issue 806).
 *
 * @package Teme
 * @since   1.7.1
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Images;

use Intervention\Gif\Decoder as GifDecoder;
use Throwable;

final class Animation
{
    /**
     * Returns whether the file at the path holds more than one frame; false
     * for a still image, another format, or a file that can't be read.
     */
    public static function in(string $path): bool
    {
        return self::gifFrames($path) > 1 || self::otherAnimated($path);
    }

    /**
     * Returns whether a GIF's blocks can all be read: a GIF cut off or
     * damaged part-way fails here though its first frame may still decode.
     * True for any file that isn't a GIF.
     */
    public static function intact(string $path): bool
    {
        return ! self::isGif($path) || self::gifFrames($path) !== null;
    }

    private static function isGif(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 4);
        fclose($handle);

        return $head === 'GIF8';
    }

    /**
     * A GIF's frames counted from its blocks: the decoder keeps each frame
     * compressed, as it is in the file, and draws none of them. 0 for a file
     * that isn't a GIF; null for a GIF that can't be read.
     */
    private static function gifFrames(string $path): ?int
    {
        if (! self::isGif($path)) {
            return 0;
        }

        try {
            return count(GifDecoder::decode($path)->frames());
        } catch (Throwable) {
            return null;
        }
    }

    private static function otherAnimated(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 64);
        fclose($handle);

        return match (true) {
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => self::chunk($path, 'ANIM'),
            str_starts_with($head, "\x89PNG") => self::chunk($path, 'acTL', 'IDAT'),
            default => false,
        };
    }

    /**
     * Whether the chunk's name appears before the stop name (or anywhere when
     * none is given), read a block at a time so a large file stays on disk.
     */
    private static function chunk(string $path, string $name, ?string $stop = null): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $carry = '';

        try {
            while (! feof($handle)) {
                $window = $carry.fread($handle, 65536);
                $found = strpos($window, $name);
                $stopped = $stop === null ? false : strpos($window, $stop);

                if ($found !== false && ($stopped === false || $found < $stopped)) {
                    return true;
                }

                if ($stopped !== false) {
                    return false;
                }

                $carry = substr($window, -3);
            }
        } finally {
            fclose($handle);
        }

        return false;
    }
}
