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
 * followers.
 *
 * @since 0.5.0
 */
enum Capability: string
{
    /** @since 0.5.0 */
    case ContentRead = 'content.read';

    /** @since 0.9.0 */
    case ContentWrite = 'content.write';

    /** @since 0.5.0 */
    case MediaRead = 'media.read';

    /** @since 0.9.0 */
    case MediaWrite = 'media.write';

    /** @since 0.9.0 */
    case ContentInsert = 'content.insert';

    /** @since 0.5.0 */
    case Http = 'http';

    /** @since 0.9.0 */
    case Jobs = 'jobs';

    /** @since 0.9.0 */
    case RoutesPublic = 'routes.public';

    /** @since 0.9.0 */
    case ImportWordPress = 'import.wordpress';

    /** @since 0.9.0 */
    case FediverseRead = 'fediverse.read';

    /** @since 0.9.0 */
    case FediverseSend = 'fediverse.send';
}
