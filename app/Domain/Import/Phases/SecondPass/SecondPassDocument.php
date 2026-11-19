<?php

/**
 * The second pass's work on one document (I-333), pure: each reusable
 * block put in at the place the converter noted, the last first, blocks
 * inside blocks too, a block met inside itself once, never past the nodes
 * a document may hold; and links left as paths finished by ID wherever the
 * document holds them.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\SecondPass;

use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\Exceptions\InvalidDocumentException;
use Closure;

final class SecondPassDocument
{
    /** How deep blocks inside blocks are put in. */
    public const int DEEPEST = 10;

    /** The Imported content card's name (I-329), whose markup holds links of its own. */
    private const string IMPORTED = 'imported';

    /** Crockford's base 32, as a ULID writes it. */
    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * The content with every reference's block put in; the blocks put in,
     * those not found, those left out as loops or too deep, and the links
     * the blocks left for the second pass. $find gives a block's content,
     * its own references and its pending links, or null.
     *
     * @param  list<array<string, mixed>>  $content
     * @param  list<array<string, mixed>>  $references
     * @param  Closure(string): (array{content: list<array<string, mixed>>, references: list<array<string, mixed>>, pendingLinks: list<string>}|null)  $find
     *
     * @return array{content: list<array<string, mixed>>, inlined: list<string>, missing: list<string>, loops: list<string>, pendingLinks: list<string>}
     *
     * @throws InvalidDocumentException when the blocks would take it past DocumentSchema::MAX_NODES
     */
    public static function inline(array $content, array $references, Closure $find, string $seed): array
    {
        $state = ['inlined' => [], 'missing' => [], 'loops' => [], 'pendingLinks' => [], 'ids' => [], 'copies' => [], 'nodes' => self::count($content)];
        self::ids($content, $state['ids']);
        $content = self::into($content, $references, $find, $seed, [], 1, $state);

        return [
            'content' => $content,
            'inlined' => array_values(array_unique($state['inlined'])),
            'missing' => array_values(array_unique($state['missing'])),
            'loops' => array_values(array_unique($state['loops'])),
            'pendingLinks' => array_values(array_unique($state['pendingLinks'])),
        ];
    }

    /**
     * The content with each link mark, each link in an Imported content
     * card, and each link in a card's rich text, whose address is a key of $hrefs led where it says.
     *
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, string>  $hrefs
     *
     * @return list<array<string, mixed>>
     */
    public static function relink(array $content, array $hrefs): array
    {
        if ($hrefs === []) {
            return $content;
        }

        return array_map(fn (array $node): array => self::relinkNode($node, $hrefs), $content);
    }

    /**
     * A node ID made from $seed: '0' and 25 characters of base 32 from its
     * SHA-1, which DocumentSchema::NODE_ID accepts; the same every time.
     */
    public static function nodeId(string $seed): string
    {
        $bytes = sha1($seed, true);
        $id = '0';

        for ($i = 0; $i < 25; $i++) {
            $id .= self::ALPHABET[ord($bytes[$i % 20]) % 32];
        }

        return $id;
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @param  list<array<string, mixed>>  $references
     * @param  list<string>  $within  the blocks being put in around this one
     * @param  array<string, mixed>  $state
     *
     * @return list<array<string, mixed>>
     */
    private static function into(array $content, array $references, Closure $find, string $seed, array $within, int $depth, array &$state): array
    {
        $ordered = [];

        foreach ($references as $index => $reference) {
            if (is_array($reference['at'] ?? null) && $reference['at'] !== [] && isset($reference['id'])) {
                $ordered[] = ['id' => (string) $reference['id'], 'at' => array_map(intval(...), array_values($reference['at'])), 'index' => $index];
            }
        }

        // The last place first; at one place, the later reference first, as PendingReference counts places (I-333).
        usort($ordered, fn (array $a, array $b): int => self::compare($b['at'], $a['at']) ?: $b['index'] <=> $a['index']);

        foreach ($ordered as $reference) {
            if (in_array($reference['id'], $within, true) || $depth > self::DEEPEST) {
                $state['loops'][] = $reference['id'];

                continue;
            }

            $block = $find($reference['id']);

            if ($block === null) {
                $state['missing'][] = $reference['id'];

                continue;
            }

            // Each copy adds its own nodes once; counted before its blocks are built, so a block used ten times in a block used ten times stops here.
            $state['nodes'] += self::count($block['content']);

            if ($state['nodes'] > DocumentSchema::MAX_NODES) {
                throw InvalidDocumentException::at('', 'The document has more than '.number_format(DocumentSchema::MAX_NODES).' nodes.');
            }

            $nodes = self::into($block['content'], $block['references'], $find, $seed, [...$within, $reference['id']], $depth + 1, $state);
            array_push($state['pendingLinks'], ...$block['pendingLinks']);
            $state['inlined'][] = $reference['id'];

            // IDs are made unique once, where the nodes join the item's document.
            if ($within === []) {
                $nodes = self::fresh($nodes, $seed, $state);
            }

            $content = self::insert($content, $reference['at'], $nodes);
        }

        return $content;
    }

    /**
     * $nodes put into $content at the path $at; a place that no longer
     * exists takes nothing.
     *
     * @param  list<array<string, mixed>>  $content
     * @param  list<int>  $at
     * @param  list<array<string, mixed>>  $nodes
     *
     * @return list<array<string, mixed>>
     */
    private static function insert(array $content, array $at, array $nodes): array
    {
        $index = (int) array_shift($at);

        if ($at === []) {
            // The empty paragraph DocumentFit gave an empty container gives way.
            if ($index === 0 && $nodes !== [] && count($content) === 1 && self::emptyParagraph($content[0])) {
                return $nodes;
            }

            array_splice($content, max(0, min($index, count($content))), 0, $nodes);

            return $content;
        }

        if (! isset($content[$index]) || ! is_array($content[$index]['content'] ?? null)) {
            return $content;
        }

        $content[$index]['content'] = self::insert(array_values($content[$index]['content']), $at, $nodes);

        return $content;
    }

    /**
     * -1, 0 or 1 as place $a comes before, at or after place $b in the
     * document's order of parents, a place inside another after it.
     *
     * @param  list<int>  $a
     * @param  list<int>  $b
     */
    private static function compare(array $a, array $b): int
    {
        foreach ($a as $level => $index) {
            if (! isset($b[$level])) {
                return 1;
            }

            if ($index !== $b[$level]) {
                return $index <=> $b[$level];
            }
        }

        return count($a) <=> count($b);
    }

    private static function emptyParagraph(mixed $node): bool
    {
        return is_array($node) && ($node['type'] ?? null) === 'paragraph' && ($node['content'] ?? []) === [];
    }

    /**
     * How many nodes $nodes holds, text nodes and nested ones included, as
     * DocumentValidator counts them.
     *
     * @param  list<mixed>  $nodes
     */
    private static function count(array $nodes): int
    {
        $count = 0;

        foreach ($nodes as $node) {
            if (is_array($node)) {
                $count += 1 + self::count((array) ($node['content'] ?? []));
            }
        }

        return $count;
    }

    /**
     * Every node ID in $nodes, as keys of $ids.
     *
     * @param  list<mixed>  $nodes
     * @param  array<string, true>  $ids
     */
    private static function ids(array $nodes, array &$ids): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (is_string($node['attrs']['id'] ?? null) && $node['attrs']['id'] !== '') {
                $ids[$node['attrs']['id']] = true;
            }

            self::ids((array) ($node['content'] ?? []), $ids);
        }
    }

    /**
     * $nodes with every node ID the document already holds made new, from
     * the seed, the old ID and how many copies of it came before.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $state
     *
     * @return list<array<string, mixed>>
     */
    private static function fresh(array $nodes, string $seed, array &$state): array
    {
        foreach ($nodes as $i => $node) {
            $id = $node['attrs']['id'] ?? null;

            if (is_string($id) && $id !== '') {
                if (isset($state['ids'][$id])) {
                    $state['copies'][$id] = ($state['copies'][$id] ?? 1) + 1;
                    $node['attrs']['id'] = self::nodeId("{$seed}:{$id}:{$state['copies'][$id]}");
                }

                $state['ids'][$node['attrs']['id']] = true;
            }

            if (is_array($node['content'] ?? null)) {
                $node['content'] = self::fresh(array_values($node['content']), $seed, $state);
            }

            $nodes[$i] = $node;
        }

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, string>  $hrefs
     *
     * @return array<string, mixed>
     */
    private static function relinkNode(array $node, array $hrefs): array
    {
        foreach ((array) ($node['marks'] ?? []) as $i => $mark) {
            $href = is_array($mark) && ($mark['type'] ?? null) === 'link' ? ($mark['attrs']['href'] ?? null) : null;

            if (is_string($href) && isset($hrefs[$href])) {
                $node['marks'][$i]['attrs']['href'] = $hrefs[$href];
            }
        }

        if (($node['type'] ?? null) === self::IMPORTED && is_array($node['attrs']['markup'] ?? null)) {
            $node['attrs']['markup'] = self::relinkParts($node['attrs']['markup'], $hrefs);
        } elseif (is_array($node['attrs'] ?? null)) {
            $node['attrs'] = self::relinkProse($node['attrs'], $hrefs);
        }

        if (is_array($node['content'] ?? null)) {
            $node['content'] = self::relink($node['content'], $hrefs);
        }

        return $node;
    }

    /**
     * A card's attributes with the links in each piece of rich text they
     * hold, such as an FAQ answer or a step, led where $hrefs says, found
     * as DocumentNormaliser finds them: any value that is a document.
     *
     * @param  array<mixed>  $value
     * @param  array<string, string>  $hrefs
     *
     * @return array<mixed>
     */
    private static function relinkProse(array $value, array $hrefs): array
    {
        if (($value['type'] ?? null) === 'doc' && is_array($value['content'] ?? null)) {
            return self::relinkNode($value, $hrefs);
        }

        foreach ($value as $key => $inner) {
            if (is_array($inner)) {
                $value[$key] = self::relinkProse($inner, $hrefs);
            }
        }

        return $value;
    }

    /**
     * An Imported content card's parts with each `a` whose address is a key
     * of $hrefs led where it says (MarkupTree's parts).
     *
     * @param  array<mixed>  $parts
     * @param  array<string, string>  $hrefs
     *
     * @return array<mixed>
     */
    private static function relinkParts(array $parts, array $hrefs): array
    {
        foreach ($parts as $i => $part) {
            if (! is_array($part)) {
                continue;
            }

            $href = ($part['element'] ?? null) === 'a' ? ($part['attrs']['href'] ?? null) : null;

            if (is_string($href) && isset($hrefs[$href])) {
                $parts[$i]['attrs']['href'] = $hrefs[$href];
            }

            if (is_array($part['children'] ?? null)) {
                $parts[$i]['children'] = self::relinkParts($part['children'], $hrefs);
            }
        }

        return $parts;
    }
}
