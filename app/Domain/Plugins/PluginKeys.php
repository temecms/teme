<?php

declare(strict_types=1);

namespace App\Domain\Plugins;

/**
 * Keys made from a plugin's code for places whose keys must start with a
 * letter and hold no hyphen, such as the `ai` settings group's keys, a
 * setting's or a notice kind's key: a code may start with a digit or hold
 * hyphens, so each begins with the same stem. Nothing core declares starts
 * with `plugin_`.
 */
final class PluginKeys
{
    /** A plugin code as a lower-case word that starts with a letter: "plugin_" and the code with hyphens as underscores. */
    public static function stem(string $code): string
    {
        return 'plugin_'.str_replace('-', '_', $code);       // "9-writer" → "plugin_9_writer"
    }
}
