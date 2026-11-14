<?php

declare(strict_types=1);

namespace App\Domain\Updates;

/**
 * Which releases this site is offered: stable, or pre-releases too, chosen by TEME_UPDATES_CHANNEL or by the version it runs.
 */
final class UpdateChannel
{
    public const string STABLE = 'stable';

    public const string PRERELEASE = 'prerelease';

    /**
     * Whether TEME_UPDATES_CHANNEL names one of the two channels.
     */
    public static function isSet(): bool
    {
        return in_array(config('teme.updates.channel'), [self::STABLE, self::PRERELEASE], true);
    }

    /**
     * The channel this site follows: the one set, or pre-release when the running version is one or the build is
     * untagged (a development build, newer than any release), otherwise stable.
     */
    public static function current(): string
    {
        if (self::isSet()) {
            return (string) config('teme.updates.channel');
        }

        if (! TemeVersion::tagged()) {
            return self::PRERELEASE;
        }

        return TemeVersion::isPrerelease(TemeVersion::current()) ? self::PRERELEASE : self::STABLE;
    }

    /**
     * Whether a release is offered on the channel this site follows.
     */
    public static function allows(string $version): bool
    {
        if (! TemeVersion::isRelease($version)) {
            return false;
        }

        return self::current() === self::PRERELEASE || ! TemeVersion::isPrerelease($version);
    }
}
