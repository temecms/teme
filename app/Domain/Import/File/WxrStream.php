<?php

/**
 * Reads a WordPress export file (WXR 1.0 to 1.2) one item at a time, with the
 * channel's facts first, so memory stays flat whatever the file's size. It
 * never reads a document type, an entity or anything from the network: a
 * file with a document type declaration is refused whole.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File;

use DOMDocument;
use DOMElement;
use DOMNode;
use Generator;
use XMLReader;

/**
 * @phpstan-type WxrMeta list<array{key: string, value: string}>
 * @phpstan-type WxrComment array{fields: array<string, string>, meta: WxrMeta}
 * @phpstan-type WxrItem array{fields: array<string, string>, taxonomies: list<array{domain: string, nicename: string, name: string}>, meta: WxrMeta, comments: list<WxrComment>}
 * @phpstan-type WxrTerm array{element: string, fields: array<string, string>, meta: WxrMeta}
 * @phpstan-type WxrChannel array{version: string, link: string|null, baseBlogUrl: string|null, pubDate: string|null, authors: list<array<string, string>>, terms: list<WxrTerm>}
 */
final class WxrStream
{
    private const string CONTENT = 'http://purl.org/rss/1.0/modules/content/';

    private const string DUBLIN_CORE = 'http://purl.org/dc/elements/1.1/';

    /** The element an item names a term with, whatever its taxonomy. */
    private const string TERM_ON_ITEM = 'category';

    /** @var WxrChannel */
    private array $channel;

    /** Whether the reader stands on the first item, or the channel had none. */
    private bool $atItem = false;

    private function __construct(
        private readonly XMLReader $reader,
        private readonly string $wordpress,
        string $version,
    ) {
        $this->channel = ['version' => $version, 'link' => null, 'baseBlogUrl' => null, 'pubDate' => null, 'authors' => [], 'terms' => []];
    }

    /**
     * Opens the file and reads its channel up to the first item.
     *
     * @throws WxrRefused when it has a document type, isn't an export, or breaks off before its first item
     */
    public static function open(string $path): self
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $reader = new XMLReader;

            if (! @$reader->open($path, null, LIBXML_NONET)) {
                throw WxrRefused::notAnExport();
            }

            $reader->setParserProperty(XMLReader::LOADDTD, false);
            $reader->setParserProperty(XMLReader::DEFAULTATTRS, false);
            $reader->setParserProperty(XMLReader::VALIDATE, false);
            $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);

            $stream = self::fromRoot($reader);
            $stream->readChannel();

            return $stream;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Returns the channel's facts: the WXR version, the site's address, when the file was made, its authors and its terms.
     *
     * @return WxrChannel
     */
    public function channel(): array
    {
        return $this->channel;
    }

    /**
     * Yields each item after the first $skip, by its place in the file from
     * zero. Skipped items are passed over without being expanded.
     *
     * @return Generator<int, WxrItem>
     *
     * @throws WxrRefused when the file breaks off partway
     */
    public function items(int $skip = 0): Generator
    {
        $previous = libxml_use_internal_errors(true);
        $index = 0;

        try {
            while ($this->atItem) {
                if ($index >= $skip) {
                    $item = $this->expanded();
                    yield $index => $this->item($item);
                }

                $index++;
                $this->atItem = $this->nextItem();
            }
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * The reader on the root, checked: an `rss` element that declares one of
     * WordPress's export namespaces, with no document type before it.
     */
    private static function fromRoot(XMLReader $reader): self
    {
        while (self::advance($reader, fn (): bool => $reader->read())) {
            if ($reader->nodeType === XMLReader::DOC_TYPE) {
                throw WxrRefused::documentType();
            }

            if ($reader->nodeType === XMLReader::ELEMENT) {
                break;
            }
        }

        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'rss' || $reader->namespaceURI !== '') {
            throw WxrRefused::notAnExport();
        }

        while ($reader->moveToNextAttribute()) {
            if ($reader->prefix === 'xmlns' && preg_match('#^http://wordpress\.org/export/(1\.[0-2])/$#', $reader->value, $match) === 1) {
                $reader->moveToElement();

                return new self($reader, $match[0], $match[1]);
            }
        }

        throw WxrRefused::notAnExport();
    }

    /**
     * Reads the channel's own elements up to its first item.
     */
    private function readChannel(): void
    {
        $reader = $this->reader;

        while (self::advance($reader, fn (): bool => $reader->read())) {
            if (self::on($reader, XMLReader::ELEMENT, 1, 'channel')) {
                break;
            }
        }

        if (! self::on($reader, XMLReader::ELEMENT, 1, 'channel')) {
            throw WxrRefused::notAnExport();
        }

        $moved = self::advance($reader, fn (): bool => $reader->read());

        while ($moved) {
            if (self::on($reader, XMLReader::END_ELEMENT, 1)) {
                return;
            }

            if (self::on($reader, XMLReader::ELEMENT, 2)) {
                if (self::on($reader, XMLReader::ELEMENT, 2, 'item')) {
                    $this->atItem = true;

                    return;
                }

                $this->channelElement($this->expanded());
            }

            $moved = self::advance($reader, fn (): bool => $reader->next());
        }
    }

    /**
     * Moves to the next item of the channel; false at the channel's end.
     */
    private function nextItem(): bool
    {
        $reader = $this->reader;
        $moved = self::advance($reader, fn (): bool => $reader->next());

        while ($moved) {
            if (self::on($reader, XMLReader::END_ELEMENT, 1) || self::on($reader, XMLReader::END_ELEMENT, 0)) {
                return false;
            }

            if (self::on($reader, XMLReader::ELEMENT, 2, 'item')) {
                return true;
            }

            $moved = self::advance($reader, fn (): bool => $reader->next());
        }

        return false;
    }

    /**
     * Whether the reader stands on a node of this type and depth, and, for a
     * name, on an element of that name outside any namespace.
     */
    private static function on(XMLReader $reader, int $type, int $depth, ?string $local = null): bool
    {
        return $reader->nodeType === $type && $reader->depth === $depth && ($local === null || ($reader->localName === $local && $reader->namespaceURI === ''));
    }

    /**
     * Moves the reader; false at the file's end, and a refusal when the XML breaks off instead.
     *
     * @param  callable(): bool  $move
     */
    private static function advance(XMLReader $reader, callable $move): bool
    {
        $moved = @$move();
        $error = libxml_get_last_error();

        if ($error !== false && $error->level >= LIBXML_ERR_ERROR) {
            throw WxrRefused::notAnExport();
        }

        return $moved;
    }

    /**
     * The element the reader stands on, in a document of its own, so nothing
     * of the items before it is kept.
     */
    private function expanded(): DOMElement
    {
        $document = new DOMDocument;
        $node = @$this->reader->expand($document);
        $error = libxml_get_last_error();

        if (! $node instanceof DOMElement || ($error !== false && $error->level >= LIBXML_ERR_ERROR)) {
            throw WxrRefused::notAnExport();
        }

        return $node;
    }

    /**
     * Keeps one of the channel's own elements.
     */
    private function channelElement(DOMElement $element): void
    {
        $name = $this->name($element);

        match ($name) {
            'link' => $this->channel['link'] = trim($element->textContent),
            'pubDate' => $this->channel['pubDate'] = trim($element->textContent),
            'wp:base_blog_url' => $this->channel['baseBlogUrl'] = trim($element->textContent),
            'wp:wxr_version' => $this->channel['version'] = trim($element->textContent) !== '' ? trim($element->textContent) : $this->channel['version'],
            'wp:author' => $this->channel['authors'][] = $this->fields($element),
            'wp:category', 'wp:tag', 'wp:term' => $this->channel['terms'][] = [
                'element' => substr($name, 3),
                'fields' => $this->fields($element, ['termmeta']),
                'meta' => $this->meta($element, 'termmeta'),
            ],
            default => null,
        };
    }

    /**
     * @return WxrItem
     */
    private function item(DOMElement $element): array
    {
        $item = ['fields' => [], 'taxonomies' => [], 'meta' => [], 'comments' => []];

        foreach (self::children($element) as $child) {
            $name = $this->name($child);

            if ($name === self::TERM_ON_ITEM) {
                $item['taxonomies'][] = ['domain' => $child->getAttribute('domain'), 'nicename' => $child->getAttribute('nicename'), 'name' => $child->textContent];
            } elseif ($name === 'wp:postmeta') {
                $item['meta'][] = $this->pair($child);
            } elseif ($name === 'wp:comment') {
                $item['comments'][] = ['fields' => $this->fields($child, ['commentmeta']), 'meta' => $this->meta($child, 'commentmeta')];
            } elseif ($name !== null) {
                $item['fields'][$name] = $child->textContent;
            }
        }

        return $item;
    }

    /**
     * An element's simple children by WordPress's own names, without the `wp:` prefix.
     *
     * @param  list<string>  $except
     *
     * @return array<string, string>
     */
    private function fields(DOMElement $element, array $except = []): array
    {
        $fields = [];

        foreach (self::children($element) as $child) {
            $name = $this->name($child);

            if ($name !== null && str_starts_with($name, 'wp:') && ! in_array(substr($name, 3), $except, true)) {
                $fields[substr($name, 3)] = $child->textContent;
            }
        }

        return $fields;
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    private function meta(DOMElement $element, string $local): array
    {
        $meta = [];

        foreach (self::children($element) as $child) {
            if ($this->name($child) === 'wp:'.$local) {
                $meta[] = $this->pair($child);
            }
        }

        return $meta;
    }

    /**
     * @return array{key: string, value: string}
     */
    private function pair(DOMElement $element): array
    {
        $fields = $this->fields($element);

        return ['key' => $fields['meta_key'] ?? '', 'value' => $fields['meta_value'] ?? ''];
    }

    /**
     * An element's name with the prefix WordPress gives its namespace, or null for a namespace it doesn't write.
     */
    private function name(DOMElement $element): ?string
    {
        $namespace = (string) $element->namespaceURI;
        $local = (string) $element->localName;

        return match (true) {
            $namespace === '' => $local,
            $namespace === $this->wordpress => 'wp:'.$local,
            $namespace === self::CONTENT => 'content:'.$local,
            $namespace === self::DUBLIN_CORE => 'dc:'.$local,
            preg_match('#^http://wordpress\.org/export/1\.[0-2]/excerpt/$#', $namespace) === 1 => 'excerpt:'.$local,
            default => null,
        };
    }

    /**
     * @return list<DOMElement>
     */
    private static function children(DOMNode $node): array
    {
        $children = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        return $children;
    }
}
