<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

/**
 * The words a person reads in a plugin's area, from its manifest
 * (docs/plans/plugin-sockets-2.md, A2): every one passes through here, so
 * they can be translated from the plugin's own language files in one place.
 */
final class AreaWords
{
    /**
     * The word as the person reads it: the manifest's English for now.
     */
    public static function text(string $code, string $english): string
    {
        return $english;
    }
}
