<?php

/**
 * What a stored document may contain beyond its cards: the prose nodes and
 * marks, the attributes each may carry and the rule each value must pass
 * (a heading, paragraph, quote, code block, list item, task item or table
 * may carry an optional `id`), the link rules, and the size limits. DocumentValidator refuses anything
 * outside it; the cards declare their own attributes.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards;

use Illuminate\Validation\Rule;

final class DocumentSchema
{
    /** @var list<string> */
    public const array NODES = [
        'doc', 'paragraph', 'heading', 'bulletList', 'orderedList', 'listItem', 'taskList', 'taskItem',
        'blockquote', 'codeBlock', 'horizontalRule', 'hardBreak', 'text',
        'table', 'tableRow', 'tableHeader', 'tableCell',
    ];

    /** @var list<string> */
    public const array MARKS = ['bold', 'italic', 'underline', 'strike', 'code', 'highlight', 'subscript', 'superscript', 'link'];

    /** The most nodes a document holds, its own doc node left out (I-2997). */
    public const int MAX_NODES = 20_000;

    /** How deeply nodes may nest, counting the document's own children as depth 1. */
    public const int MAX_DEPTH = 8;

    /** The most bytes a document's JSON holds: 1 MB (I-2997). */
    public const int MAX_BYTES = 1_048_576;

    /** TextAlign's default alignments (Tiptap 3). */
    public const array ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    /** The alignments a table cell may have (Tiptap 3.31's table cells). */
    public const array CELL_ALIGNMENTS = ['left', 'center', 'right'];

    /**
     * The most a table cell may span, as browsers clamp it: 1,000 columns and
     * 65,534 rows. The normaliser brings a larger span down to it (I-452).
     */
    public const array CELL_SPANS = ['colspan' => 1000, 'rowspan' => 65534];

    /** The list styles an ordered list may name (Tiptap 3's `type`). */
    public const array LIST_TYPES = ['1', 'a', 'A', 'i', 'I'];

    /** A code block's language, as the editor's input rule or a paste sets it. */
    public const string LANGUAGE = '/^[a-z0-9+#-]{1,30}$/';

    /**
     * An internal link: exactly `teme:content/` and a content ID, which is
     * lowercase (I-418). A prefix check would pass `teme:content/../../x`.
     */
    public const string CONTENT_LINK = '/^teme:content\/[0-9a-hjkmnp-tv-z]{26}$/';

    /** The same, in any case: what the normaliser repairs. */
    public const string CONTENT_LINK_ANY_CASE = '/^teme:content\/[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/';

    /**
     * A node's stable ID, as the editor makes it: a ULID in uppercase
     * Crockford base32 (resources/js/lib/ulid.ts). Content IDs are lowercase
     * (I-418); this rule is for the IDs of headings and cards, not for them.
     */
    public const string NODE_ID = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    /** The protocols an external link may use. */
    public const array PROTOCOLS = ['http', 'https', 'mailto', 'tel'];

    /**
     * A link to a place on the same page: `#` and one or more of the
     * characters RFC 3986 allows in a fragment. The normaliser
     * percent-encodes any other character first (I-452).
     */
    public const string FRAGMENT = '/^#[A-Za-z0-9_.~!$&\'()*+,;=:@\/?%-]+$/';

    /** A character a fragment may not hold as it is. */
    public const string NOT_IN_FRAGMENT = '/[^A-Za-z0-9_.~!$&\'()*+,;=:@\/?%-]/u';

    /**
     * A link to an article, or to a place in it: `teme:content/`, the
     * article's ID, and optionally a fragment (I-1670).
     */
    public const string INTERNAL_LINK = '/^teme:content\/([0-9a-hjkmnp-tv-z]{26})(#[A-Za-z0-9_.~!$&\'()*+,;=:@\/?%-]+)?$/';

    /**
     * What a link by ID may lead to, and the ID each keeps as its table
     * stores it (I-418): an article's and a term's lowercase, a library
     * file's uppercase, a person's a whole number short of `bigint`'s limit.
     *
     * @var array<string, string>
     */
    public const array LINK_TARGETS = [
        'content' => '[0-9a-hjkmnp-tv-z]{26}',
        'term' => '[0-9a-hjkmnp-tv-z]{26}',
        'user' => '[1-9][0-9]{0,17}',
        'media' => '[0-9A-HJKMNP-TV-Z]{26}',
    ];

    /** A place after a link by ID: `#` and the characters a fragment holds. */
    public const string PLACE = '#[A-Za-z0-9_.~!$&\'()*+,;=:@\/?%-]+';

    /**
     * A path on this site with its query and place, such as a listing's or
     * the home page's: `/`, never `//`, then the characters RFC 3986 allows
     * in a path, a query and a fragment (I-1669).
     */
    public const string SITE_PATH = '/^\/(?!\/)[A-Za-z0-9_.~!$&\'()*+,;=:@\/?#%-]*$/';

    /** A character a site path may not hold as it is. */
    public const string NOT_IN_SITE_PATH = '/[^A-Za-z0-9_.~!$&\'()*+,;=:@\/?#%-]/u';

    /**
     * The attributes each prose node may carry, with their rules. A node not
     * listed may carry none.
     *
     * @return array<string, array<string, list<mixed>>>
     */
    public static function nodeAttributes(): array
    {
        $id = ['nullable', 'string', 'regex:'.self::NODE_ID];
        $align = ['nullable', Rule::in(self::ALIGNMENTS)];
        $cell = [
            'colspan' => ['integer', 'min:1', 'max:'.self::CELL_SPANS['colspan']],
            'rowspan' => ['integer', 'min:1', 'max:'.self::CELL_SPANS['rowspan']],
            'colwidth' => ['nullable', 'array'],
            // Tiptap 3.31 gives every cell an alignment, null by default (I-452).
            'align' => ['nullable', Rule::in(self::CELL_ALIGNMENTS)],
        ];

        return [
            // The six blocks below the heading carry an optional permanent id,
            // validated as a heading's is (I-3362).
            'paragraph' => ['id' => $id, 'textAlign' => $align],
            'blockquote' => ['id' => $id],
            'listItem' => ['id' => $id],
            'table' => ['id' => $id],
            // The id is the stable ULID the editor assigns to a heading (I-202).
            'heading' => ['level' => ['required', 'integer', 'between:2,4'], 'id' => $id, 'textAlign' => $align],
            'codeBlock' => ['id' => $id, 'language' => ['nullable', 'string', 'regex:'.self::LANGUAGE]],
            'orderedList' => ['start' => ['integer', 'min:1'], 'type' => ['nullable', Rule::in(self::LIST_TYPES)]],
            'taskItem' => ['id' => $id, 'checked' => ['boolean']],
            'tableHeader' => $cell,
            'tableCell' => $cell,
        ];
    }

    /**
     * The attributes a link mark carries. Tiptap writes all five on every
     * link (checked 2026-09-21), so all five are allowed; the renderer, not
     * the document, decides `rel`.
     *
     * @return array<string, list<mixed>>
     */
    public static function linkAttributes(): array
    {
        return [
            'href' => ['required', 'string', 'max:2048'],
            'target' => ['nullable', Rule::in(['_blank'])],
            'rel' => ['prohibited'],
            'class' => ['prohibited'],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Whether a link may point here: an article, a term, a person or a
     * library file by its ID, with a place at most (I-1702); a path on this
     * site; a place on the same page; or an address with an allowed protocol.
     */
    public static function allowsHref(string $href): bool
    {
        if (self::linkTarget($href) !== null || preg_match(self::SITE_PATH, $href) === 1 || preg_match(self::FRAGMENT, $href) === 1) {
            return true;
        }

        $scheme = parse_url($href, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), self::PROTOCOLS, true);
    }

    /**
     * The article an internal link leads to and the place in it, `#…` or
     * '' for none; null for any other address (I-1670).
     *
     * @return array{id: string, place: string}|null
     */
    public static function internalLink(string $href): ?array
    {
        return preg_match(self::INTERNAL_LINK, $href, $link) === 1 ? ['id' => $link[1], 'place' => $link[2] ?? ''] : null;
    }

    /**
     * What a link by ID leads to, its ID, and the place in it, `#…` or ''
     * for none; null for any other address (I-1702).
     *
     * @return array{kind: string, id: string, place: string}|null
     */
    public static function linkTarget(string $href): ?array
    {
        foreach (self::LINK_TARGETS as $kind => $id) {
            if (preg_match('/^teme:'.$kind.'\/('.$id.')('.self::PLACE.')?$/', $href, $link) === 1) {
                return ['kind' => $kind, 'id' => $link[1], 'place' => $link[2] ?? ''];
            }
        }

        return null;
    }

    /**
     * A link by ID's address, as the link box, the normaliser and the
     * importer write it (I-1702). It checks nothing; linkTarget() reads it.
     */
    public static function linkTo(string $kind, string $id, string $place = ''): string
    {
        return "teme:{$kind}/{$id}{$place}";
    }
}
