<?php

declare(strict_types=1);

namespace App\Domain\Fediverse;

/**
 * The fediverse's fixed names: the media types an ActivityPub request asks
 * for and an answer carries, the JSON-LD contexts, and the prefix of the
 * site's own new addresses (proposal, Settled 3).
 */
final class ActivityPub
{
    public const string CONTENT_TYPE = 'application/activity+json';

    /** The Accept types that ask for a document rather than a page. */
    public const array ACCEPT = ['application/activity+json', 'application/ld+json'];

    public const string CONTEXT = 'https://www.w3.org/ns/activitystreams';

    public const string SECURITY_CONTEXT = 'https://w3id.org/security/v1';

    public const string PUBLIC = 'https://www.w3.org/ns/activitystreams#Public';

    /** The first segment of every new fediverse address. */
    public const string PREFIX = 'ap';

    /** The first segment of the WordPress plugin's addresses, kept for imported actors. */
    public const string WORDPRESS_PREFIX = 'wp-json';
}
