<?php

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

use RuntimeException;

/**
 * The platform, or Teme's own guard in front of it, refused a step of an update; the message is a sentence people read, never the platform's own words.
 */
final class PlatformRefused extends RuntimeException
{
    /** The sentence when no platform is set up. */
    public static function notSetUp(): self
    {
        return new self('No platform is set up for the updater. Update by hand: see Tools › Updates, How to update.');
    }
}
