<?php

/**
 * The only image names the updater sends a platform (I-3326; GHSA-jxxj-gmpx-h5rj): Teme's own repository
 * on ghcr.io, from TEME_IMAGE_REPOSITORY, and a release's version. Anything else is refused before any request.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Updates\Updater\PlatformRefused;

final class ImageName
{
    /** D: $ never matches before a final newline. */
    public const string REPOSITORY = '/^ghcr\.io\/[a-z0-9]+(-[a-z0-9]+)*\/teme$/D';

    /** TemeVersion::PATTERN's text, with D. */
    public const string VERSION = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(-(alpha|beta|rc)\.(0|[1-9]\d*))?$/D';

    /** The study's pattern for a whole image name (docs/studies/2026-11-07-install-and-update.md), with D. */
    public const string IMAGE = '/^[a-z0-9.\/-]+:[0-9A-Za-z.+-]+$/D';

    /**
     * Returns the image name for a release's version, built from the repository setting and the version alone.
     *
     * @throws PlatformRefused
     */
    public static function for(string $version): string
    {
        $repository = config('teme.updater.image_repository');

        if (! is_string($repository) || preg_match(self::REPOSITORY, $repository) !== 1) {
            throw new PlatformRefused('TEME_IMAGE_REPOSITORY must be Teme’s image on ghcr.io, such as ghcr.io/example/teme.');
        }

        if (! TemeVersion::isRelease($version) || preg_match(self::VERSION, $version) !== 1) {
            throw new PlatformRefused("{$version} isn’t a release’s version.");
        }

        $image = "{$repository}:{$version}";

        if (preg_match(self::IMAGE, $image) !== 1) {
            throw new PlatformRefused("{$version} isn’t a release’s version.");
        }

        return $image;
    }
}
