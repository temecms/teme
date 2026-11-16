<?php

declare(strict_types=1);

namespace Teme\Contracts\Plugins;

/**
 * What a plugin can ask an administrator to let it do in the first release.
 * ContentRead unlocks the content-reading hooks, ContentWrite the content
 * door, MediaRead the media-reading hooks, MediaWrite the media door,
 * ContentInsert the article insertion points, Http the outbound door to the
 * hosts the plugin names, Jobs the door that queues the plugin's declared
 * background work, RoutesPublic the door that answers the plugin's declared
 * public addresses, and ImportWordPress hands an import the data the plugin
 * declares under its manifest's `imports`, read from the WordPress site Teme
 * imports. FediverseRead unlocks the fediverse hooks and who follows the
 * site's accounts, and FediverseSend the door that sends activities to those
 * followers. NoticesSend opens the Notices door, for the kinds of notice
 * the plugin declares, and ContentLinks the hook that sets attributes on the
 * links an article draws.
 *
 * @since 0.5.0
 */
enum Capability: string
{
    case ContentRead = 'content.read';
    case ContentWrite = 'content.write';
    case MediaRead = 'media.read';
    case MediaWrite = 'media.write';
    case ContentInsert = 'content.insert';
    case Http = 'http';
    case Jobs = 'jobs';
    case RoutesPublic = 'routes.public';
    case ImportWordPress = 'import.wordpress';
    case FediverseRead = 'fediverse.read';
    case FediverseSend = 'fediverse.send';

    /** @since 0.9.0 */
    case NoticesSend = 'notices.send';

    /** @since 0.9.0 */
    case ContentLinks = 'content.links';
}
