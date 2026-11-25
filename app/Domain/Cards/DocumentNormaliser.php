<?php

/**
 * Repairs the values a paste or another editor can bring that would
 * otherwise refuse a whole save (I-452): odd attribute values, link
 * addresses the site can make sense of, a block's `id` that is not a ULID
 * (set to null) and an `id` already used earlier in the document, cards' and
 * headings' included (the later one is set to null, I-3362). Runs on the server before
 * DocumentValidator, so every client gets it; everything else is left for
 * the validator to refuse.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards;

final class DocumentNormaliser
{
    /** @var array<array-key, string|null> each link address in this document, and what it keeps */
    private array $hrefs = [];

    /** @var list<string>|null the prose blocks whose schema rules give them an `id` */
    private ?array $identified = null;

    /** @var array<string, true> each node id met so far in this document, in document order */
    private array $ids = [];

    /**
     * The document with those values repaired. It reads only its input, and
     * the path and media tables for a link to a page or a file on this site. $links receives each
     * link address it changed and what that became: another address, or
     * null where the link went and its words stayed.
     *
     * @param  array<mixed>  $document
     * @param  list<array{from: string, to: string|null}>|null  $links
     *
     * @param-out  list<array{from: string, to: string|null}>  $links
     *
     * @return array<mixed>
     */
    public static function normalise(array $document, ?array &$links = null): array
    {
        $walk = new self;
        $document = $walk->node($document);
        $links = [];

        foreach ($walk->hrefs as $from => $to) {
            if ($to !== (string) $from) {
                $links[] = ['from' => (string) $from, 'to' => $to];
            }
        }

        return $document;
    }

    /**
     * @param  array<mixed>  $node
     *
     * @return array<mixed>
     */
    private function node(array $node): array
    {
        $type = $node['type'] ?? null;

        if (isset($node['attrs']) && is_array($node['attrs'])) {
            $node['attrs'] = $this->unrepeated($this->attributes((string) $type, $node['attrs']), (string) $type);

            // A card's rich text, such as an FAQ's answers, keeps to the body's rules (CardProse).
            if ($type !== 'doc' && ! in_array($type, DocumentSchema::NODES, true)) {
                $node['attrs'] = $this->prose($node['attrs']);
            }
        }

        if (isset($node['marks']) && is_array($node['marks'])) {
            $node = $this->links($node);
        }

        if (isset($node['content']) && is_array($node['content'])) {
            foreach ($node['content'] as $index => $child) {
                if (is_array($child)) {
                    $node['content'][$index] = $this->node($child);
                }
            }
        }

        return $node;
    }

    /**
     * A card's attributes with each piece of rich text inside them
     * repaired as the body is, at any depth.
     *
     * @param  array<mixed>  $value
     *
     * @return array<mixed>
     */
    private function prose(array $value): array
    {
        if (($value['type'] ?? null) === 'doc' && is_array($value['content'] ?? null)) {
            return $this->node($value);
        }

        foreach ($value as $key => $inner) {
            if (is_array($inner)) {
                $value[$key] = $this->prose($inner);
            }
        }

        return $value;
    }

    /**
     * The attributes with a prose block's `id` that an earlier node already
     * carries set to null: the first keeps it (I-3362). A card's id is never
     * changed, since every card needs one and the editor keeps it when a
     * card is copied; but it counts as seen, so a later prose block that
     * repeats it is the one that loses it.
     *
     * @param  array<mixed>  $attrs
     *
     * @return array<mixed>
     */
    private function unrepeated(array $attrs, string $type): array
    {
        if (! is_string($attrs['id'] ?? null) || $attrs['id'] === '') {
            return $attrs;
        }

        $id = strtoupper($attrs['id']);

        if (isset($this->ids[$id]) && in_array($type, DocumentSchema::NODES, true)) {
            $attrs['id'] = null;
        } else {
            $this->ids[$id] = true;
        }

        return $attrs;
    }

    /**
     * The prose blocks that may carry an `id`, read from the schema's rules.
     *
     * @return list<string>
     */
    private function identified(): array
    {
        return $this->identified ??= array_keys(array_filter(
            DocumentSchema::nodeAttributes(),
            fn (array $rules): bool => array_key_exists('id', $rules),
        ));
    }

    /**
     * @param  array<mixed>  $attrs
     *
     * @return array<mixed>
     */
    private function attributes(string $type, array $attrs): array
    {
        // A Custom HTML card has no on or off any more; an older document's is dropped.
        if ($type === 'site-code') {
            unset($attrs['on']);
        }

        if ($type === 'codeBlock' && is_string($attrs['language'] ?? null)) {
            $language = strtolower($attrs['language']);
            $attrs['language'] = preg_match(DocumentSchema::LANGUAGE, $language) === 1 ? $language : null;
        }

        if ($type === 'orderedList') {
            if (array_key_exists('type', $attrs) && $attrs['type'] !== null && ! in_array($attrs['type'], DocumentSchema::LIST_TYPES, true)) {
                $attrs['type'] = null;
            }

            // parseInt() makes NaN of a start that isn't a number, and JSON
            // sends NaN as null (I-452).
            if (array_key_exists('start', $attrs)) {
                $start = filter_var($attrs['start'], FILTER_VALIDATE_INT);
                $attrs['start'] = is_int($start) && $start >= 1 ? $start : 1;
            }
        }

        if (in_array($type, ['paragraph', 'heading'], true) && array_key_exists('textAlign', $attrs)
            && $attrs['textAlign'] !== null && ! in_array($attrs['textAlign'], DocumentSchema::ALIGNMENTS, true)) {
            $attrs['textAlign'] = null;
        }

        // Another editor's ID, such as a UUID, goes. Today the editor gives a
        // new ULID to headings only, when it next opens the article (I-202);
        // it will give one to every block that may carry an id (I-3362).
        if (in_array($type, $this->identified(), true) && is_string($attrs['id'] ?? null)) {
            $id = strtoupper($attrs['id']);
            $attrs['id'] = preg_match(DocumentSchema::NODE_ID, $id) === 1 ? $id : null;
        }

        if ($type === 'heading') {
            // Tiptap's own default level is 1, which the editor draws as an
            // h2; a level past 4 is an h4 (I-452).
            if (is_int($attrs['level'] ?? null)) {
                $attrs['level'] = max(2, min(4, $attrs['level']));
            }
        }

        // A pasted cell's span, as browsers read it: 1 when it isn't a
        // positive integer (colspan="0", colspan=""), and no more than their
        // limit, so a colspan of millions can't swamp the editor's table map.
        // And an alignment the cell can't take (I-452).
        if (in_array($type, ['tableCell', 'tableHeader'], true)) {
            foreach (DocumentSchema::CELL_SPANS as $span => $most) {
                if (array_key_exists($span, $attrs)) {
                    $attrs[$span] = is_int($attrs[$span]) && $attrs[$span] >= 1 ? min($attrs[$span], $most) : 1;
                }
            }

            if (array_key_exists('align', $attrs) && ! in_array($attrs['align'], DocumentSchema::CELL_ALIGNMENTS, true)) {
                $attrs['align'] = null;
            }
        }

        if ($type === 'button' && is_string($attrs['contentId'] ?? null)
            && preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $attrs['contentId']) === 1) {
            $attrs['contentId'] = strtolower($attrs['contentId']);
        }

        return $attrs;
    }

    /**
     * A text node with each link's address as it is stored; a link with
     * nowhere to go is removed and its words kept.
     *
     * @param  array<mixed>  $node
     *
     * @return array<mixed>
     */
    private function links(array $node): array
    {
        $marks = (array) $node['marks'];
        $removed = false;

        foreach ($marks as $index => $mark) {
            if (! is_array($mark) || ($mark['type'] ?? null) !== 'link' || ! is_array($mark['attrs'] ?? null) || ! is_string($mark['attrs']['href'] ?? null)) {
                continue;
            }

            $href = $this->href($mark['attrs']['href']);

            if ($href === null) {
                unset($marks[$index]);
                $removed = true;
            } else {
                $marks[$index]['attrs']['href'] = $href;
            }
        }

        if (! $removed) {
            $node['marks'] = $marks;
        } elseif ($marks === []) {
            unset($node['marks']);
        } else {
            $node['marks'] = array_values($marks);
        }

        return $node;
    }

    /**
     * What a link address keeps, worked out once per document.
     */
    private function href(string $href): ?string
    {
        if (! array_key_exists($href, $this->hrefs)) {
            $this->hrefs[$href] = self::address($href);
        }

        return $this->hrefs[$href];
    }

    /**
     * What a link's address becomes, or null when the link goes and its
     * words stay (I-452): a link by ID in its table's case, its place
     * encoded; a place on the page encoded; `//host/…` given https:; a path
     * on this site, or this site's own address written in full, as
     * sitePath() keeps it; a library file's address on a media disk served
     * from another host as a link to the file by ID; any other http, https,
     * mailto or tel address as it is. Anything else, such as `javascript:`,
     * `ftp:` or a path relative to another page, has nowhere to go. What it
     * keeps always passes DocumentSchema::allowsHref().
     */
    private static function address(string $href): ?string
    {
        $href = trim($href);

        // A browser reads `//host/…` as an address on another site.
        if (preg_match('#^//[^/\s]#', $href) === 1) {
            $href = 'https:'.$href;
        }

        // This site's own address, written in full, is stored as the path it
        // names, and the path as sitePath() keeps it (I-1669).
        $href = self::pathOnThisSite($href) ?? $href;

        $kept = match (true) {
            stripos($href, 'teme:') === 0 => self::byId($href),
            str_starts_with($href, '#') => self::fragment($href),
            str_starts_with($href, '/') => self::sitePath($href),
            preg_match('~^https?://~i', $href) === 1 => self::elsewhere($href),
            default => $href,
        };

        return $kept !== null && strlen($kept) <= 2048 && DocumentSchema::allowsHref($kept) ? $kept : null;
    }

    /**
     * The path, query and place of an address on this site written in full,
     * or null for any other address. This site is config('app.url')'s host,
     * without case or a leading `www.`, over http or https, on its port: a
     * scheme's own port counts as none. An address with a user name is
     * another site's.
     */
    private static function pathOnThisSite(string $href): ?string
    {
        if (preg_match('~^https?://~i', $href) !== 1) {
            return null;
        }

        $address = parse_url($href);
        $site = parse_url((string) config('app.url'));

        if (! is_array($address) || ! is_array($site) || ! isset($address['host'], $site['host'])
            || isset($address['user']) || self::host($address['host']) !== self::host($site['host'])
            || self::port($address['scheme'] ?? '', $address['port'] ?? null) !== self::port($site['scheme'] ?? '', $site['port'] ?? null)) {
            return null;
        }

        return ($address['path'] ?? '/')
            .(isset($address['query']) ? '?'.$address['query'] : '')
            .(isset($address['fragment']) ? '#'.$address['fragment'] : '');
    }

    /**
     * A host as this site compares it: lower-cased, without a leading `www.`.
     */
    private static function host(string $host): string
    {
        return (string) preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * An address's port, or null for none or its scheme's own (80, 443).
     */
    private static function port(string $scheme, ?int $port): ?int
    {
        return $port === (strtolower($scheme) === 'http' ? 80 : 443) ? null : $port;
    }

    /**
     * A link by ID as it is stored: its kind lower-cased, its ID in the case
     * its table keeps (I-418), its place encoded; null when it names no kind
     * and ID a link may have, or a query follows.
     */
    private static function byId(string $href): ?string
    {
        if (preg_match('~^teme:([a-z]+)/([0-9a-z]+)(#.*)?$~is', $href, $link) !== 1) {
            return null;
        }

        $kind = strtolower($link[1]);
        $id = $kind === 'media' ? strtoupper($link[2]) : strtolower($link[2]);
        $place = isset($link[3]) ? self::fragment($link[3]) : null;
        $kept = DocumentSchema::linkTo($kind, $id, $place ?? '');

        return DocumentSchema::linkTarget($kept) === null ? null : $kept;
    }

    /**
     * A path on this site as it is stored: a link by ID to the article, the
     * term or the author whose address, current or old, it is, or to the
     * library file it names, its place kept and its query dropped (I-1702);
     * otherwise the path itself, its characters encoded (I-1669). The home
     * page's path, a feed's, a later page of a list's and a path nothing has
     * stay paths.
     */
    private static function sitePath(string $path): ?string
    {
        // A browser reads `/\host` and `///host` as another site.
        if (preg_match('#^/[/\\\\]#', $path) === 1) {
            return null;
        }

        $target = app(InternalLinks::class)->targetAt(substr($path, 0, strcspn($path, '?#')));

        return $target === null ? self::encoded($path, DocumentSchema::NOT_IN_SITE_PATH) : self::linkFor($target, $path);
    }

    /**
     * An address on another host as it is stored: a link by ID when it is a
     * library file's on a media disk served from there (I-1702), otherwise
     * as it is.
     */
    private static function elsewhere(string $href): string
    {
        $target = app(InternalLinks::class)->targetAt(substr($href, 0, strcspn($href, '?#')));

        return $target === null ? $href : self::linkFor($target, $href);
    }

    /**
     * A link by ID to what an address names, the address's place kept and
     * its query dropped (I-1670).
     *
     * @param  array{kind: string, id: string}  $target
     */
    private static function linkFor(array $target, string $address): string
    {
        $hash = strpos($address, '#');
        $place = $hash === false ? null : self::fragment(substr($address, $hash));

        return DocumentSchema::linkTo($target['kind'], $target['id'], $place ?? '');
    }

    /**
     * A place with the characters a fragment can't hold encoded, or null
     * for `#` alone, which names no place.
     */
    private static function fragment(string $href): ?string
    {
        $encoded = self::encoded(substr($href, 1), DocumentSchema::NOT_IN_FRAGMENT);

        return $encoded === null || $encoded === '' ? null : '#'.$encoded;
    }

    /**
     * The text with each character the pattern finds percent-encoded, or
     * null when the text isn't UTF-8.
     */
    private static function encoded(string $text, string $pattern): ?string
    {
        return preg_replace_callback($pattern, fn (array $character): string => rawurlencode($character[0]), $text);
    }
}
