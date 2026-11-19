<?php

/**
 * The permission that opens Site › Plugins: finding, enabling and disabling
 * plugins, and agreeing to what they ask for. Administrators hold it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins;

final class PluginAccess
{
    public const string MANAGE = 'plugins.manage';
}
