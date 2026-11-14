<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Html;

use App\Domain\Cards\DocumentSchema;
use App\Domain\Import\Convert\ConversionReport;
use App\Domain\Import\Convert\Links\SiteLinks;
use Dom\Element;
use LogicException;
use Tiptap\Editor;
use Tiptap\Marks\Bold;
use Tiptap\Marks\Code;
use Tiptap\Marks\Highlight;
use Tiptap\Marks\Italic;
use Tiptap\Marks\Link;
use Tiptap\Marks\Strike;
use Tiptap\Marks\Subscript;
use Tiptap\Marks\Superscript;
use Tiptap\Marks\Underline;
use Tiptap\Nodes\Document;
use Tiptap\Nodes\HardBreak;
use Tiptap\Nodes\Paragraph;
use Tiptap\Nodes\Text;

/**
 * A textblock's inline content as Tiptap nodes (I-1722). The element was
 * read by the native parser; its HTML, serialised again, goes to tiptap-php
 * only for its marks, and what comes back is kept to the text, hard breaks,
 * marks and link attributes DocumentSchema allows. Images are taken out
 * first, for the caller to place after the textblock.
 */
final readonly class InlineContent
{
    /** Images an emoji script draws: each becomes its alt, the character itself. */
    private const string EMOJI = 'img.wp-smiley, img.emoji';

    /** The longest address a link keeps (DocumentSchema::linkAttributes). */
    private const int LONGEST = 2048;

    /**
     * The element's inline nodes, the images taken out of it with the
     * address each was linked to, and the held elements taken out of it.
     *
     * @return array{nodes: list<array<string, mixed>>, images: list<array{element: Element, link: string|null}>, held: list<Element>}
     */
    public function read(Element $element, ConversionReport $report, ?SiteLinks $links = null): array
    {
        $copy = $element->cloneNode(true);

        if (! $copy instanceof Element) {
            throw new LogicException('A copy of an element is an element.');
        }

        $held = [];

        foreach (iterator_to_array($copy->querySelectorAll(Nodes::HELD)) as $holder) {
            $held[] = $holder;
            $holder->remove();
        }

        foreach (iterator_to_array($copy->querySelectorAll(self::EMOJI)) as $emoji) {
            $emoji->replaceWith((string) $emoji->getAttribute('alt'));
        }

        $images = [];

        foreach (iterator_to_array($copy->querySelectorAll('img')) as $image) {
            $images[] = ['element' => $image, 'link' => $image->closest('a')?->getAttribute('href')];
            $image->remove();
        }

        // A link left with no words once its images are out has nothing to say, and its address is never resolved or said.
        foreach (iterator_to_array($copy->querySelectorAll('a')) as $anchor) {
            if (HtmlFragment::text($anchor) === '') {
                $anchor->replaceWith(...iterator_to_array($anchor->childNodes));
            }
        }

        // tiptap-php's link mark refuses `teme:` addresses, so each link by ID waits behind a path and is put back after.
        $swapped = [];

        foreach (iterator_to_array($copy->querySelectorAll('a')) as $anchor) {
            $this->link($anchor, $report, $links, $swapped);
        }

        // Wrapped, so tiptap-php never reads the HTML as JSON (Editor::getContentType()).
        $document = (new Editor(['extensions' => [
            new Document, new Text, new Paragraph, new HardBreak,
            new Bold, new Italic, new Underline, new Strike, new Code, new Highlight, new Subscript, new Superscript, new Link,
        ]]))->setContent('<p>'.$copy->innerHTML.'</p>')->getDocument();

        $nodes = self::inline(is_array($document['content'] ?? null) ? $document['content'] : []);

        foreach ($nodes as $index => $node) {
            foreach ($node['marks'] ?? [] as $at => $mark) {
                if ($mark['type'] === 'link' && isset($swapped[$mark['attrs']['href'] ?? ''])) {
                    $nodes[$index]['marks'][$at]['attrs']['href'] = $swapped[$mark['attrs']['href']];
                }
            }
        }

        return ['nodes' => self::fit($nodes), 'images' => $images, 'held' => $held];
    }

    /**
     * The address a link keeps, or null when it has nowhere Teme can go: the
     * address trimmed, `//host/…` given https:, then kept when
     * DocumentSchema::allowsHref() accepts it.
     */
    public static function href(string $href): ?string
    {
        $href = trim($href);

        if (preg_match('#^//[^/\s]#', $href) === 1) {
            $href = 'https:'.$href;
        }

        return $href !== '' && strlen($href) <= self::LONGEST && DocumentSchema::allowsHref($href) ? $href : null;
    }

    /**
     * A link left with its address and a target of _blank, nothing else; one
     * with no address Teme can keep unwrapped, its words kept and counted.
     *
     * @param  array<string, string>  $swapped
     */
    private function link(Element $anchor, ConversionReport $report, ?SiteLinks $links, array &$swapped): void
    {
        $address = (string) $anchor->getAttribute('href');
        $href = match (true) {
            ! $anchor->hasAttribute('href') => null,
            $links !== null => $links->href($address),
            default => self::href($address),
        };

        if ($href === null) {
            if ($links === null && $anchor->hasAttribute('href')) {
                $report->count('link.removed');
            }

            $anchor->replaceWith(...iterator_to_array($anchor->childNodes));

            return;
        }

        $blank = strtolower(trim((string) $anchor->getAttribute('target'))) === '_blank';

        foreach ($anchor->getAttributeNames() as $name) {
            $anchor->removeAttribute($name);
        }

        if (DocumentSchema::linkTarget($href) !== null) {
            $placeholder = '/teme-link-'.hash('crc32b', $href).'-'.count($swapped);
            $swapped[$placeholder] = $href;
            $href = $placeholder;
        }

        $anchor->setAttribute('href', $href);

        if ($blank) {
            $anchor->setAttribute('target', '_blank');
        }
    }

    /**
     * Every text and hard break in tiptap-php's answer, in order; a second
     * paragraph reads as a line break before it.
     *
     * @param  array<mixed>  $nodes
     *
     * @return list<array<string, mixed>>
     */
    private static function inline(array $nodes): array
    {
        $inline = [];

        foreach ($nodes as $node) {
            $type = is_array($node) ? ($node['type'] ?? null) : null;

            if ($type === 'text' && is_string($node['text'] ?? null)) {
                $inline[] = ['type' => 'text', 'text' => $node['text'], 'marks' => self::marks($node['marks'] ?? [])];
            } elseif ($type === 'hardBreak') {
                $inline[] = ['type' => 'hardBreak'];
            } elseif (is_array($node) && is_array($node['content'] ?? null)) {
                if ($type === 'paragraph' && $inline !== []) {
                    $inline[] = ['type' => 'hardBreak'];
                }

                array_push($inline, ...self::inline($node['content']));
            }
        }

        return $inline;
    }

    /**
     * The marks DocumentSchema allows, once each: a link with its address
     * and a target of _blank only. The editor's code mark excludes every
     * other, so code stands alone, unless a link is there, which wins.
     *
     * @return list<array<string, mixed>>
     */
    private static function marks(mixed $marks): array
    {
        $kept = [];

        foreach (is_array($marks) ? $marks : [] as $mark) {
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;

            if (! is_string($type) || ! in_array($type, DocumentSchema::MARKS, true) || isset($kept[$type])) {
                continue;
            }

            if ($type !== 'link') {
                $kept[$type] = ['type' => $type];

                continue;
            }

            $href = $mark['attrs']['href'] ?? null;

            if (is_string($href)) {
                $kept['link'] = ['type' => 'link', 'attrs' => ($mark['attrs']['target'] ?? null) === '_blank' ? ['href' => $href, 'target' => '_blank'] : ['href' => $href]];
            }
        }

        if (isset($kept['code'])) {
            $kept = isset($kept['link']) ? array_diff_key($kept, ['code' => true]) : ['code' => $kept['code']];
        }

        return array_values($kept);
    }

    /**
     * The nodes as a browser shows them: neighbouring text with the same
     * marks joined, runs of spaces one space, spaces at the ends, beside a
     * hard break and after a space taken off, hard breaks at the ends left
     * out; nothing at all when no words are left.
     *
     * @param  list<array<string, mixed>>  $nodes
     *
     * @return list<array<string, mixed>>
     */
    private static function fit(array $nodes): array
    {
        $joined = [];

        foreach ($nodes as $node) {
            $last = array_key_last($joined);

            if ($node['type'] === 'text' && $last !== null && $joined[$last]['type'] === 'text' && $joined[$last]['marks'] === $node['marks']) {
                $joined[$last]['text'] .= $node['text'];

                continue;
            }

            $joined[] = $node;
        }

        foreach ($joined as $index => $node) {
            if ($node['type'] !== 'text') {
                continue;
            }

            $text = (string) preg_replace('/ {2,}/', ' ', $node['text']);
            $before = $joined[$index - 1] ?? null;
            $after = $joined[$index + 1] ?? null;

            if ($before === null || $before['type'] === 'hardBreak' || str_ends_with((string) ($before['text'] ?? ''), ' ')) {
                $text = ltrim($text, ' ');
            }

            if ($after === null || $after['type'] === 'hardBreak') {
                $text = rtrim($text, ' ');
            }

            $joined[$index]['text'] = $text;
        }

        $kept = array_values(array_filter($joined, fn (array $node): bool => $node['type'] !== 'text' || $node['text'] !== ''));

        while ($kept !== [] && $kept[0]['type'] === 'hardBreak') {
            array_shift($kept);
        }

        while ($kept !== [] && $kept[array_key_last($kept)]['type'] === 'hardBreak') {
            array_pop($kept);
        }

        $words = array_filter($kept, fn (array $node): bool => $node['type'] === 'text' && preg_match('/[^\s\x{00A0}]/u', $node['text']) === 1);

        if ($words === []) {
            return [];
        }

        return array_map(
            fn (array $node): array => $node['type'] === 'text' && $node['marks'] === [] ? ['type' => 'text', 'text' => $node['text']] : $node,
            $kept,
        );
    }
}
