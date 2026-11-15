<?php

declare(strict_types=1);

namespace Teme\Contracts\Plugins;

/**
 * What a plugin can ask an administrator to let it do in the first release.
 * ContentRead unlocks the content-reading hooks, ContentWrite the content
 * door, MediaRead the media-reading hooks, MediaWrite the media door, Http
 * the outbound door to the hosts the plugin names, and Jobs the door that
 * queues the plugin's declared background work.
 *
 * @since 0.5.0
 */
enum Capability: string
{
    case ContentRead = 'content.read';
    case ContentWrite = 'content.write';
    case MediaRead = 'media.read';
    case MediaWrite = 'media.write';
    case Http = 'http';
    case Jobs = 'jobs';
}
