<?php

/**
 * Whether a request is for a plugin or a theme, and the Composer package
 * type each must have.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions;

enum ExtensionKind: string
{
    case Plugin = 'plugin';
    case Theme = 'theme';

    /** The Composer type a package of this kind declares. */
    public function packageType(): string
    {
        return match ($this) {
            self::Plugin => 'teme-plugin',
            self::Theme => 'teme-theme',
        };
    }
}
