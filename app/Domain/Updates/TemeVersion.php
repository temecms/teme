<?php

/**
 * Teme's own version (I-?? draft): the release the image was tagged with,
 * which the image workflow bakes in as TEME_VERSION, or UNTAGGED for a build
 * from a branch and for development. Also how two releases compare.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates;

final class TemeVersion
{
    /** What a build that isn't a release reports; every theme's ^0.1 accepts it. */
    public const string UNTAGGED = '0.1.0';

    /** A release: three numbers, then -alpha.N, -beta.N or -rc.N for a pre-release (docs/roadmap.md, Versions). */
    public const string PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(-(alpha|beta|rc)\.(0|[1-9]\d*))?$/';

    /** The version config/teme.php reads: the environment's when it is a release, else UNTAGGED. */
    public static function fromEnvironment(mixed $value): string
    {
        return is_string($value) && self::isRelease($value) ? $value : self::UNTAGGED;
    }

    public static function isRelease(string $version): bool
    {
        return preg_match(self::PATTERN, $version) === 1;
    }

    public static function isPrerelease(string $version): bool
    {
        return self::isRelease($version) && str_contains($version, '-');
    }

    /**
     * Negative, zero or positive as $one is older than, the same as or newer
     * than $other. PHP's own order puts alpha before beta before rc before
     * the release, and compares the numbers after them as numbers.
     */
    public static function compare(string $one, string $other): int
    {
        return version_compare($one, $other);
    }

    /** A whole commit in lower case, or null. */
    public static function revision(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{40}$/', $value) === 1 ? $value : null;
    }

    /** The version this site runs. */
    public static function current(): string
    {
        return (string) config('teme.version');
    }

    /** Whether this build is a tagged release. */
    public static function tagged(): bool
    {
        return (bool) config('teme.tagged');
    }
}
