<?php

/**
 * The prose blocks of a stored document that carry a permanent ID beside
 * cards (I-3362), and the step that gives each one lacking an ID a new one.
 * DocumentSchema says which blocks may carry one; the editor gives the same
 * blocks theirs (resources/js/components/editor/block-ids.ts).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards;

use Closure;

final class BlockIds
{
    /**
     * The node types whose attributes, as DocumentSchema allows them, hold
     * an `id`, in that schema's order.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(array_filter(DocumentSchema::nodeAttributes(), fn (array $rules): bool => array_key_exists('id', $rules)));
    }

    /**
     * The nodes with every node of those types, at any depth, whose `id` is
     * not of a node ID's shape given a new one, its other attributes kept;
     * every other node, cards included, as it was. It does not dedupe: the
     * normaliser does.
     *
     * @param  list<array<mixed>>  $nodes
     * @param  Closure(): string  $newId
     *
     * @return list<array<mixed>>
     */
    public static function fill(array $nodes, Closure $newId): array
    {
        $types = self::types();

        return array_map(fn (array $node): array => self::filled($node, $types, $newId), $nodes);
    }

    /**
     * The node with every `id` attribute taken out, at any depth, cards'
     * included, and a node left with no attributes having none: what a
     * document holds, whatever IDs it carries.
     *
     * @param  array<mixed>  $node
     *
     * @return array<mixed>
     */
    public static function without(array $node): array
    {
        if (isset($node['attrs']) && is_array($node['attrs'])) {
            unset($node['attrs']['id']);

            if ($node['attrs'] === []) {
                unset($node['attrs']);
            }
        }

        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = array_map(fn (mixed $child): mixed => is_array($child) ? self::without($child) : $child, $node['content']);
        }

        return $node;
    }

    /**
     * Returns the new nodes, each one that holds what a stored node holds,
     * IDs aside, replaced by that stored node, so its IDs, cards' included,
     * stay; matched in order, each stored node once. Every other node is as
     * it came.
     *
     * @param  list<mixed>  $nodes
     * @param  list<mixed>  $stored
     *
     * @return list<mixed>
     *
     * @since next
     */
    public static function kept(array $nodes, array $stored): array
    {
        $bare = array_map(fn (mixed $node): mixed => is_array($node) ? self::canonical(self::without($node)) : null, $stored);
        $next = 0;

        foreach ($nodes as $i => $node) {
            if (! is_array($node)) {
                continue;
            }

            $wanted = self::canonical(self::without($node));

            for ($j = $next; $j < count($stored); $j++) {
                if ($bare[$j] === $wanted) {
                    $nodes[$i] = $stored[$j];
                    $next = $j + 1;

                    break;
                }
            }
        }

        return $nodes;
    }

    /**
     * Whether two documents, or two nodes, hold exactly the same, whatever
     * order their keys came back in: "10" and "10.0" differ, as they would
     * not under PHP's loose comparison.
     *
     * @param  array<mixed>  $one
     * @param  array<mixed>  $other
     *
     * @since next
     */
    public static function same(array $one, array $other): bool
    {
        return self::canonical($one) === self::canonical($other);
    }

    /**
     * The value as JSON with every object's keys in order, so equal content
     * gives equal text.
     */
    private static function canonical(mixed $value): string
    {
        return (string) json_encode(self::sorted($value), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sorted(...), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $types
     * @param  Closure(): string  $newId
     *
     * @return array<mixed>
     */
    private static function filled(array $node, array $types, Closure $newId): array
    {
        if (in_array($node['type'] ?? null, $types, true)) {
            $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
            $id = $attrs['id'] ?? null;

            if (! is_string($id) || preg_match(DocumentSchema::NODE_ID, $id) !== 1) {
                $attrs['id'] = $newId();
                $node['attrs'] = $attrs;
            }
        }

        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = array_map(fn (mixed $child): mixed => is_array($child) ? self::filled($child, $types, $newId) : $child, $node['content']);
        }

        return $node;
    }
}
