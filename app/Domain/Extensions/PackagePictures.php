<?php

/**
 * A plugin's or a theme's own pictures, as its manifest declares them: its
 * icon and its screenshots, each a path inside the package. Checks them for
 * both manifests' validators, and finds a declared one on disk.
 *
 * @package Teme
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions;

use finfo;

/**
 * A plugin's or a theme's own pictures, as its manifest declares them: an
 * `icon` and up to eight `screenshots`, each a path inside the package with
 * no `..` segment, backslash, colon or white space. Teme's directory shows
 * them; the admin draws a plugin's icon.
 */
final class PackagePictures
{
    /** The manifest's `icon`: a .png, .jpg, .jpeg, .webp or .svg picture inside the package, in any case. */
    public const string ICON = '~^(?!/)(?!(?:.*/)?\.\.(?:/|$))[^\\\\\s:]+\.(?:png|jpe?g|webp|svg)$~i';

    /** A screenshot's `path`: a .png, .jpg, .jpeg or .webp picture inside the package, in any case. */
    public const string SCREENSHOT = '~^(?!/)(?!(?:.*/)?\.\.(?:/|$))[^\\\\\s:]+\.(?:png|jpe?g|webp)$~i';

    /** The most screenshots a manifest may list. */
    public const int MOST_SCREENSHOTS = 8;

    /** The longest a screenshot's caption may be, in characters. */
    public const int LONGEST_CAPTION = 140;

    /** The media type each picture's name promises, by its extension. */
    private const array TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];

    /**
     * What is wrong with the manifest's `icon` and `screenshots`, each in a
     * sentence; empty when both are absent or valid. A missing file is no
     * error: the admin draws initials in its place.
     *
     * @param  array<string, mixed>  $manifest
     * @param  string  $kind  plugin or theme, as the sentences name the package
     *
     * @return list<string>
     */
    public static function errors(array $manifest, string $kind): array
    {
        $errors = [];

        if (array_key_exists('icon', $manifest) && (! is_string($manifest['icon']) || preg_match(self::ICON, $manifest['icon']) !== 1)) {
            $errors[] = "icon must be a path inside the {$kind}, with no .., to a .png, .jpg, .jpeg, .webp or .svg picture.";
        }

        if (! array_key_exists('screenshots', $manifest)) {
            return $errors;
        }

        $shots = $manifest['screenshots'];

        if (! is_array($shots) || ! array_is_list($shots) || count($shots) > self::MOST_SCREENSHOTS) {
            $errors[] = 'screenshots must be a list of at most '.self::MOST_SCREENSHOTS.' pictures, each with a path and, if it has one, a caption.';

            return $errors;
        }

        foreach ($shots as $i => $shot) {
            if (is_array($shot) && array_diff(array_keys($shot), ['path', 'caption']) !== []) {
                $errors[] = "screenshots[{$i}] may hold only path and caption.";
            }

            if (! is_array($shot) || ! is_string($shot['path'] ?? null) || preg_match(self::SCREENSHOT, $shot['path']) !== 1) {
                $errors[] = "screenshots[{$i}].path must be a path inside the {$kind}, with no .., to a .png, .jpg, .jpeg or .webp picture.";
            }

            if (is_array($shot) && array_key_exists('caption', $shot) && (! is_string($shot['caption']) || mb_strlen($shot['caption']) > self::LONGEST_CAPTION)) {
                $errors[] = "screenshots[{$i}].caption must be text of at most ".self::LONGEST_CAPTION.' characters.';
            }
        }

        return $errors;
    }

    /**
     * The file a declared picture names and its media type, or null unless
     * the path passes $pattern, resolves inside the package's own folder,
     * and holds the picture its name says. No path ever comes from a request.
     *
     * @return array{path: string, type: string}|null
     */
    public static function find(string $folder, string $declared, string $pattern = self::ICON): ?array
    {
        if (preg_match($pattern, $declared) !== 1) {
            return null;
        }

        $root = realpath($folder);
        $file = realpath($folder.'/'.$declared);

        if ($root === false || $file === false || ! is_file($file) || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }

        $named = self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
        $type = (new finfo(FILEINFO_MIME_TYPE))->file($file);

        return $named !== null && $type === $named ? ['path' => $file, 'type' => $named] : null;
    }

    /**
     * The response headers a served icon carries: its type, no sniffing, a
     * tight CSP so an SVG opened on its own can run and fetch nothing, and
     * a day of private cache.
     *
     * @return array<string, string>
     */
    public static function headers(string $type): array
    {
        return [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=86400',
        ];
    }
}
