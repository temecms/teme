<?php

declare(strict_types=1);

namespace App\Domain\Plugins;

/**
 * The permission that opens Site › Plugins: finding, enabling and disabling
 * plugins, and agreeing to what they ask for. Administrators hold it.
 */
final class PluginAccess
{
    public const string MANAGE = 'plugins.manage';
}
