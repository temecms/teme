<?php

declare(strict_types=1);

namespace App\Domain\Themes\Exceptions;

use RuntimeException;

/**
 * A composer returned a key its view already has, from the page or from an
 * earlier composer (I-251), or a theme's composer returned a name core
 * keeps for itself. Thrown outside production; in production it is logged
 * and the value already there, if any, is kept.
 */
final class ComposerKeyCollision extends RuntimeException
{
    public static function for(string $composer, string $key, string $view): self
    {
        return new self("The composer {$composer} adds \"{$key}\" to {$view}, which already has it.");
    }

    public static function reserved(string $composer, string $key, string $view, string $theme): self
    {
        return new self("The composer {$composer} of the theme {$theme} adds \"{$key}\" to {$view}, a name kept for Teme.");
    }
}
