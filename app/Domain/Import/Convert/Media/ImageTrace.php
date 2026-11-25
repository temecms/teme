<?php

/**
 * Finds the media Teme imported for an image in WordPress's HTML (I-338),
 * four ways, the first match winning: an attachment ID (the block's, the
 * `wp-image-N` class, a legacy gallery's `data-id`, a caption shortcode's),
 * then the file's path below the uploads folder, across its sizes and
 * edited copies. Only the site's own addresses are traced by file.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert\Media;

use App\Domain\Import\Convert\ConversionSite;
use Dom\Element;

final class ImageTrace
{
    /** Where an image's address may be: lazy-loading plugins move it out of src. */
    private const array SOURCES = ['src', 'data-lazy-src', 'data-src'];

    /**
     * What the trace found: the media ID or null, whether the image is the
     * site's own, its file name, and for another site's, its host.
     *
     * @return array{mediaId: string|null, own: bool, file: string, host: string|null}
     */
    public static function find(Element $img, ConversionSite $site, MediaTargets $media, ?int $attachmentId = null, ?int $captionId = null): array
    {
        $dataId = filter_var($img->getAttribute('data-id'), FILTER_VALIDATE_INT);
        $ids = array_values(array_filter(
            [$attachmentId, self::classId($img), is_int($dataId) ? $dataId : null, $captionId],
            fn (?int $id): bool => $id !== null && $id > 0,
        ));

        return self::trace(self::source($img), $ids, $site, $media);
    }

    /**
     * What the trace finds for an address and attachment IDs, as find()
     * answers; for any file, not only an image.
     *
     * @param  list<int>  $ids
     *
     * @return array{mediaId: string|null, own: bool, file: string, host: string|null}
     */
    public static function trace(string $src, array $ids, ConversionSite $site, MediaTargets $media): array
    {
        $file = basename(rawurldecode((string) parse_url($src, PHP_URL_PATH)));

        foreach ($ids as $id) {
            if ($id < 1) {
                continue;
            }

            $found = $media->attachment($id);

            if ($found !== null) {
                return ['mediaId' => $found, 'own' => true, 'file' => $file, 'host' => null];
            }
        }

        $key = $site->uploadsKey($src);
        $found = $key === null ? null : self::lookup($key, $media);

        if ($found !== null) {
            return ['mediaId' => $found, 'own' => true, 'file' => $file, 'host' => null];
        }

        // A file with no address is counted as the site's own: nothing says it's another's.
        $own = $ids !== [] || $src === '' || $site->isOwn($src);

        return ['mediaId' => null, 'own' => $own, 'file' => $file, 'host' => $own ? null : $site->hostOf($src)];
    }

    /**
     * The media of a file below the uploads folder: its own key first, then
     * without a size, then the original's; null when none is known.
     */
    public static function lookup(string $key, MediaTargets $media): ?string
    {
        foreach (self::keys($key) as $candidate) {
            $found = $media->file($candidate);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The image's real address: `src`, unless it's a lazy-loading plugin's
     * `data:` placeholder, then `data-lazy-src`, then `data-src`.
     */
    public static function source(Element $img): string
    {
        foreach (self::SOURCES as $attribute) {
            $value = trim((string) $img->getAttribute($attribute));

            if ($value !== '' && ! str_starts_with(strtolower($value), 'data:')) {
                return $value;
            }
        }

        return '';
    }

    /**
     * What an outside picture waits for: each attachment ID the image names
     * (`a:{id}`), and the original file below the uploads folder
     * (`f:{key}`), so the picture pass finds the posts a file it brings
     * belongs in (#716).
     *
     * @return list<string>
     *
     * @since 1.5.0
     */
    public static function waitingKeys(Element $img, ConversionSite $site, ?int $attachmentId = null, ?int $captionId = null): array
    {
        $dataId = filter_var($img->getAttribute('data-id'), FILTER_VALIDATE_INT);
        $keys = [];

        foreach ([$attachmentId, self::classId($img), is_int($dataId) ? $dataId : null, $captionId] as $id) {
            if ($id !== null && $id > 0) {
                $keys[] = "a:{$id}";
            }
        }

        $key = $site->uploadsKey(self::source($img));

        if ($key !== null) {
            $keys[] = 'f:'.self::original($key);
        }

        return array_values(array_unique($keys));
    }

    /**
     * The original file's key for a size or an edited copy: without a
     * `-{w}x{h}` size, then without `-scaled` or `-rotated`.
     */
    public static function original(string $key): string
    {
        $unsized = (string) preg_replace('/-\d+x\d+(?=\.[a-z0-9]+$)/', '', $key);

        return (string) preg_replace('/-(?:scaled|rotated)(?=\.[a-z0-9]+$)/', '', $unsized);
    }

    /**
     * The keys to try for a file, the address's own first.
     *
     * @return list<string>
     */
    private static function keys(string $key): array
    {
        $unsized = (string) preg_replace('/-\d+x\d+(?=\.[a-z0-9]+$)/', '', $key);

        return array_values(array_unique([$key, $unsized, self::original($key)]));
    }

    /**
     * The attachment ID WordPress wrote into the image's class (media.php's
     * get_image_tag()), or null.
     */
    private static function classId(Element $img): ?int
    {
        return preg_match('/(?:^|\s)wp-image-(\d+)(?:\s|$)/', (string) $img->getAttribute('class'), $found) === 1 ? (int) $found[1] : null;
    }
}
