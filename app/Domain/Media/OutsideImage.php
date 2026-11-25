<?php

/**
 * An outside picture: an image an import left at the old site's address
 * while it copies the file into the library. A featured image holds one as
 * its value, and travels to a theme as an image ID of its own, so a theme
 * draws it as it draws any image.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media;

final class OutsideImage
{
    /** What begins an outside picture's image ID, never a library item's. */
    public const string PREFIX = 'outside:';

    /**
     * The image ID a theme passes back to draw the picture: its address and size, encoded.
     */
    public static function id(string $source, ?int $width, ?int $height): string
    {
        $json = (string) json_encode(['s' => $source, 'w' => $width, 'h' => $height], JSON_UNESCAPED_SLASHES);

        return self::PREFIX.rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * The address and size an image ID holds, or null for one that isn't an outside picture's.
     *
     * @return array{source: string, width: int|null, height: int|null}|null
     */
    public static function decode(string $id): ?array
    {
        if (! str_starts_with($id, self::PREFIX)) {
            return null;
        }

        $json = base64_decode(strtr(substr($id, strlen(self::PREFIX)), '-_', '+/'), true);
        $value = $json === false ? null : json_decode($json, true);
        $source = is_array($value) ? ($value['s'] ?? null) : null;

        if (! is_string($source) || ! self::isWebAddress($source)) {
            return null;
        }

        return [
            'source' => $source,
            'width' => is_int($value['w'] ?? null) ? $value['w'] : null,
            'height' => is_int($value['h'] ?? null) ? $value['h'] : null,
        ];
    }

    /**
     * The `<img>` for an outside picture, sending no referrer, which spares a
     * site that refuses pictures shown elsewhere.
     */
    public static function markup(string $source, string $alt, ?int $width, ?int $height, bool $priority = false, string $class = ''): string
    {
        $attributes = ['src="'.e($source).'"', 'alt="'.e($alt).'"'];

        if ($width !== null && $height !== null) {
            $attributes[] = 'width="'.$width.'" height="'.$height.'"';
        }

        if ($class !== '') {
            $attributes[] = 'class="'.e($class).'"';
        }

        $attributes[] = $priority ? 'fetchpriority="high" decoding="async"' : 'loading="lazy" decoding="async"';
        $attributes[] = 'referrerpolicy="no-referrer"';

        return '<img '.implode(' ', $attributes).'>';
    }

    /**
     * Whether an address is one the site may draw a picture from: http or https.
     */
    public static function isWebAddress(string $address): bool
    {
        return strlen($address) <= 2048
            && filter_var($address, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($address, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
