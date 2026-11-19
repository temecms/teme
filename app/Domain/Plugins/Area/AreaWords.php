<?php

/**
 * The words a person reads in a plugin's area, from its manifest
 * (docs/plans/plugin-sockets-2.md, A2): every one passes through here, in
 * the person's language from the plugin's own language files.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

use App\Domain\Plugins\ManifestText;

final class AreaWords
{
    /**
     * The word as the person reads it: the plugin's line for it in the
     * current language, or the manifest's English.
     */
    public static function text(string $code, string $english): string
    {
        return ManifestText::for($code)->text($english, app()->getLocale());
    }
}
