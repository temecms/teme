<?php

/**
 * What a plugin can ask an administrator to let it do in the first release.
 *
 * @package Teme
 * @since   0.5.0
 * @license MIT
 */

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
 * followers. FeedsLinks lets the plugin add the links its manifest
 * declares under `contributes.feedLinks`, such as a WebSub hub, to every
 * feed (`@since 1.0.0`). PagesInsert unlocks the places to add content on every public
 * page: the head, the top and bottom of the body, between the items of a
 * list, beside a theme's regions, and fixed to the screen's edge. Ai opens
 * SiteAi, the site's own AI connection, for the tasks the plugin declares
 * (1.0.0). PeopleRead opens the door that names the site's people: who
 * writes, who is invited, who joins; never an email or a role. NoticesSend
 * opens the Notices door, for the kinds of notice the
 * plugin declares, and ContentLinks the hook that sets attributes on the
 * links an article draws. PagesHead lets the plugin add `<meta>` tags and
 * structured data to public pages' heads, and Sitemaps its own sitemaps to
 * the site's sitemap index, those its manifest declares (1.1.0).
 * DirectoryRead opens VerifiedDirectory, Teme's plugin and theme directory as
 * Teme verified it, and ContentSubmissions the Submissions door, which keeps
 * what a visitor sends from a public form as an item waiting for review (1.3.0).
 * SecurityLog opens the SecurityLines door, which writes lines of the kinds the
 * plugin declares under `contributes.securityLog` into core's security log (next).
 * Redirects opens the RedirectWriter door, to add, list and remove the plugin's own redirects.
 * TermsWrite opens the TermWriter door, to create, update, list and remove terms of the taxonomies the plugin owns.
 *
 * @since 0.5.0
 */
enum Capability: string
{
    /** @since 0.5.0 */
    case ContentRead = 'content.read';

    /** @since 1.0.0 */
    case ContentWrite = 'content.write';

    /** @since 0.5.0 */
    case MediaRead = 'media.read';

    /** @since 1.0.0 */
    case MediaWrite = 'media.write';

    /** @since 1.0.0 */
    case ContentInsert = 'content.insert';

    /** @since 0.5.0 */
    case Http = 'http';

    /** @since 1.0.0 */
    case Jobs = 'jobs';

    /** @since 1.0.0 */
    case RoutesPublic = 'routes.public';

    /** @since 1.0.0 */
    case ImportWordPress = 'import.wordpress';

    /** @since 1.0.0 */
    case FediverseRead = 'fediverse.read';

    /** @since 1.0.0 */
    case FediverseSend = 'fediverse.send';

    /** @since 1.0.0 */
    case FeedsLinks = 'feeds.links';

    /** @since 1.0.0 */
    case PagesInsert = 'pages.insert';

    /** @since 1.0.0 */
    case Ai = 'ai';

    /** @since 1.0.0 */
    case PeopleRead = 'people.read';

    /** @since 1.0.0 */
    case NoticesSend = 'notices.send';

    /** @since 1.0.0 */
    case ContentLinks = 'content.links';

    /** @since 1.1.0 */
    case PagesHead = 'pages.head';

    /** @since 1.1.0 */
    case Sitemaps = 'sitemaps';

    /** @since 1.3.0 */
    case DirectoryRead = 'directory.read';

    /** @since 1.3.0 */
    case ContentSubmissions = 'content.submissions';

    /** @since 1.7.0 */
    case Redirects = 'redirects';

    /** @since 1.8.0 */
    case CommentsCheck = 'comments.check';

    /** @since 1.8.0 */
    case SearchRead = 'search.read';

    /** @since 1.8.0 */
    case ImportRead = 'import.read';

    /** @since next */
    case SecurityLog = 'security.log';

    /** @since next */
    case TermsWrite = 'terms.write';
}
