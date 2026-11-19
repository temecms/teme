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
