<?php

declare(strict_types=1);

namespace Teme\Contracts\Cache;

/**
 * A change to something the public site shows, as the page cache is told of
 * it: what changed, and the page-cache tags core purges for it, from before
 * the change and after. It is study C's collect hook of this name: core
 * owns the graph (spec §5.6), and from phase 5 a listener may return the
 * tags of pages it adds that show the same thing. The tags are the page
 * cache's vocabulary, and a CDN's surrogate keys.
 *
 * @since 0.1.0
 */
final readonly class CacheKeysFor
{
    /** A content item, by its ID. @since 0.1.0 */
    public const string CONTENT = 'content';

    /** A taxonomy term, by its ID. @since 0.1.0 */
    public const string TERM = 'term';

    /**
     * A person the site shows, such as one an item credits, by the ID core
     * gives them: an account's ID for someone with an account, another ID
     * for someone without. Opaque: passed back as given, never read as a
     * number.
     *
     * @since 0.1.0
     */
    public const string PERSON = 'person';

    /** A media item, by its ID. @since 0.1.0 */
    public const string MEDIA = 'media';

    /** A menu, by its handle. @since 0.1.0 */
    public const string MENU = 'menu';

    /** A settings group, by its key. @since 0.1.0 */
    public const string SETTINGS = 'settings';

    /** The whole site, as a repair of what every list counts on changes it; its ID is `*`. @since 0.1.0 */
    public const string SITE = 'site';

    /**
     * @param  string  $subject  what changed: one of the constants above
     * @param  string  $id  the subject's ID, handle or key
     * @param  list<string>  $keys  the tags core purges for the change
     *
     * @since 0.1.0
     */
    public function __construct(
        public string $subject,
        public string $id,
        public array $keys,
    ) {}
}
