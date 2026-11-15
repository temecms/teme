<?php

declare(strict_types=1);

namespace Teme\Contracts\Plugins;

/**
 * What a plugin can ask an administrator to let it do in the first release.
 * ContentRead unlocks the content-reading hooks, MediaRead the media-reading
 * hooks, and Http the outbound door, to the hosts the plugin names.
 * ImportWordPress hands an import the data the plugin declares under its
 * manifest's `imports`, read from the WordPress site Teme imports.
 *
 * @since 0.5.0
 */
enum Capability: string
{
    case ContentRead = 'content.read';
    case MediaRead = 'media.read';
    case Http = 'http';
    case ImportWordPress = 'import.wordpress';
}
