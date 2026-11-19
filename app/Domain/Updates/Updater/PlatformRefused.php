<?php

/**
 * The platform, or Teme's own guard in front of it, refused a step of an update; the message is a sentence people read, never the platform's own words.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

use RuntimeException;

final class PlatformRefused extends RuntimeException
{
    /**
     * The sentence when Teme holds no Dokploy API key: Settings › Updates says it in its own words (issue 534).
     *
     * @since 1.1.1
     */
    public const string NO_KEY = 'Teme has no Dokploy API key. Paste one in Settings › Updates.';

    /**
     * The refusal when Teme holds no Dokploy API key.
     *
     * @since 1.1.1
     */
    public static function noKey(): self
    {
        return new self(self::NO_KEY);
    }

    /** The sentence when no platform is set up. */
    public static function notSetUp(): self
    {
        return new self('No platform is set up for the updater. Update by hand: see Tools › Updates, How to update.');
    }
}
