<?php

declare(strict_types=1);

namespace Teme\Contracts\StructuredData;

/**
 * The fragments core's structured-data nodes end their @id with, so
 * an extension adds to core's node by naming it, rather than describing the
 * same thing twice. A node's @id is an address and a fragment: the site's
 * home address, a page's own, or an author's; a page's own node is its
 * address alone.
 *
 * @since 0.1.0
 */
final class NodeIds
{
    /** The site, after its home address. @since 0.1.0 */
    public const string WEBSITE = '#website';

    /** Who publishes the site, after its home address. @since 0.1.0 */
    public const string ORGANIZATION = '#organization';

    /** The publisher's logo, after the site's home address. @since 0.1.0 */
    public const string LOGO = '#logo';

    /** What a page is about, such as its article, after the page's address. @since 0.1.0 */
    public const string ARTICLE = '#article';

    /** The page's primary image, after the page's address. @since 0.1.0 */
    public const string PRIMARY_IMAGE = '#primaryimage';

    /** The page's breadcrumb trail, after the page's address. @since 0.1.0 */
    public const string BREADCRUMB = '#breadcrumb';

    /** A person, after their author address. @since 0.1.0 */
    public const string PERSON = '#person';

    /**
     * A card's node, after the page's address: its card type and its own
     * ID, which a document's card keeps through every export.
     *
     * @since 0.1.0
     */
    public static function card(string $type, string $id): string
    {
        return '#'.$type.'-'.$id;
    }
}
