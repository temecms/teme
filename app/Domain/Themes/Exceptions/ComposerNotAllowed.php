<?php

/**
 * A theme's composer that may not run: it doesn't exist, isn't a
 * ViewComposer, or asks in its constructor for something other than a
 * Teme\Contracts interface (I-251). Discovery records the message as the
 * theme's error, so such a theme is broken and never used.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Themes\Exceptions;

use RuntimeException;

final class ComposerNotAllowed extends RuntimeException
{
    public static function asksFor(string $composer, string $asked): self
    {
        return new self("The composer {$composer} may only depend on Teme\\Contracts interfaces; it asks for {$asked}.");
    }

    public static function missing(string $composer): self
    {
        return new self("The composer {$composer} does not exist.");
    }

    public static function notAComposer(string $composer): self
    {
        return new self("The composer {$composer} does not implement Teme\\Contracts\\Themes\\ViewComposer.");
    }
}
