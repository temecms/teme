<?php

declare(strict_types=1);

namespace App\Domain\Media;

/**
 * The registered set of image variants every image gets (study K, §7). A
 * change to the widths, the thumbnail or the quality raises SET, and
 * teme:media:regenerate remakes every older item. Images used as a featured
 * or social image also get the share images (I-855), which change no SET.
 */
final class MediaVariants
{
    public const int SET = 1;

    /** @var list<int> */
    public const array WIDTHS = [320, 640, 960, 1280, 1920, 2560];

    public const int THUMBNAIL = 300;

    public const int QUALITY = 80;

    /**
     * The share images an image gets once it is used as a featured or social
     * image (I-700, I-855): the social card, then three crops for search
     * engines, each width × height. No key starts with "w", which
     * MediaUrls::largest() would read as a width.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    public const array SHARE = ['social' => [1200, 630], 'crop-16x9' => [1200, 675], 'crop-4x3' => [1200, 900], 'crop-1x1' => [1200, 1200]];

    /**
     * A share image's size for an original this big: its ratio at the
     * largest the original allows, never enlarged.
     *
     * @return array{0: int, 1: int}
     */
    public static function shareSize(int $width, int $height, int $shareWidth, int $shareHeight): array
    {
        $scale = min(1, $width / $shareWidth, $height / $shareHeight);

        return [(int) round($shareWidth * $scale), (int) round($shareHeight * $scale)];
    }

    /**
     * The widths to make for an original this wide: every registered width
     * narrower than it, plus its own width when that is under the largest.
     * Never enlarged.
     *
     * @return list<int>
     */
    public static function widthsFor(int $originalWidth): array
    {
        $largest = self::WIDTHS[array_key_last(self::WIDTHS)];
        $widths = array_values(array_filter(self::WIDTHS, fn (int $width): bool => $width < $originalWidth));
        $top = min($originalWidth, $largest);

        if (! in_array($top, $widths, true)) {
            $widths[] = $top;
        }

        return $widths;
    }
}
