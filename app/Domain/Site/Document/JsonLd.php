<?php

declare(strict_types=1);

namespace App\Domain\Site\Document;

use Closure;

/**
 * The page's JSON-LD: one graph, its nodes merged by @id, passed through the
 * graph's filters and tidied, and the one encoder that writes it (I-698).
 * Plain arrays in, one script's text out; no value is ever read for
 * template tokens.
 */
final class JsonLd
{
    /**
     * `<` and `>` become \u escapes, so `</script` and `<!--` can never form
     * inside the script, and U+2028 and U+2029 stay escaped, since
     * JSON_UNESCAPED_LINE_TERMINATORS is never passed. Slashes and every
     * script's letters stay readable. No HTML escaping: a script is raw
     * text, so `&` stays `&`.
     */
    private const int FLAGS = JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * The page's graph, merged and tidied with no filter run: the nodes as
     * they would be written.
     *
     * @param  list<JsonLdNode>  $nodes
     *
     * @return list<array<string, mixed>>
     */
    public static function graph(array $nodes): array
    {
        return self::build($nodes)->nodes;
    }

    /**
     * The page's graph (I-697): each node where its @id first came, a later
     * node with the same @id merged into it; then the graph's one filter,
     * the only way to remove or rewrite a node; then core's tidying, which
     * withholds a node lacking what Google requires. The nodes come in
     * precedence order, a plugin's first, so its values stand, and it adds
     * to core's Article without rebuilding it.
     *
     * @param  list<JsonLdNode>  $nodes
     * @param  (Closure(list<array<string, mixed>>): iterable<mixed>)|null  $filter
     */
    public static function build(array $nodes, ?Closure $filter = null): JsonLdGraph
    {
        $merged = self::merge($nodes);

        if ($filter === null) {
            return self::tidy($merged, []);
        }

        // What a filter returns is held to a node's rules, so the encoder can never fail on it.
        $returned = [];

        foreach ($filter($merged) as $node) {
            /** @var array<string, mixed> $properties */
            $properties = is_array($node) ? $node : [];
            $returned[] = new JsonLdNode($properties);
        }

        $filtered = self::merge($returned);

        return self::tidy($filtered, array_values(array_diff(array_column($merged, '@id'), array_column($filtered, '@id'))));
    }

    /**
     * The text of the page's one `<script type="application/ld+json">`.
     *
     * @param  list<array<string, mixed>>  $graph
     */
    public static function encode(array $graph): string
    {
        return json_encode(['@context' => 'https://schema.org', '@graph' => $graph], self::FLAGS);
    }

    /**
     * Each node where its @id first came, a later one merged into it.
     *
     * @param  list<JsonLdNode>  $nodes
     *
     * @return list<array<string, mixed>>
     */
    private static function merge(array $nodes): array
    {
        $graph = [];

        foreach ($nodes as $node) {
            $id = $node->id();
            $graph[$id] = isset($graph[$id]) ? self::merged($graph[$id], $node->properties) : $node->properties;
        }

        return array_values($graph);
    }

    /**
     * Core's tidying (I-697): every empty value goes, at any depth (null, ''
     * and [], never false or 0); each @type is its names once, one name as a
     * string; references to the nodes the filter removed go; the gate
     * withholds a node lacking what Google requires; references to the
     * withheld go too. A reference to a node never in the graph, such as
     * another site's organization, stays.
     *
     * @param  list<array<string, mixed>>  $graph
     * @param  list<string>  $removed  the @ids the filter took out
     */
    private static function tidy(array $graph, array $removed): JsonLdGraph
    {
        $nodes = [];

        foreach ($graph as $node) {
            $node = self::pruned($node);
            $node['@type'] = self::types($node['@type'] ?? null, null);
            $nodes[] = $node;
        }

        $nodes = self::unlinked($nodes, $removed);
        $byId = array_column($nodes, null, '@id');
        $required = app(RequiredProperties::class);
        $kept = [];
        $withheld = [];

        foreach ($nodes as $node) {
            $missing = $required->missing($node, $byId);

            if ($missing === []) {
                $kept[] = $node;
            } else {
                $withheld[] = new Withheld((string) $node['@id'], self::names($node['@type']), $missing);
            }
        }

        return new JsonLdGraph(self::unlinked($kept, array_map(fn (Withheld $node): string => $node->id, $withheld)), $withheld);
    }

    /**
     * The value with every empty one inside it gone, depth first, so an
     * object left empty goes too; a list stays a list.
     *
     * @param  array<array-key, mixed>  $value
     *
     * @return array<array-key, mixed>
     */
    private static function pruned(array $value): array
    {
        $list = array_is_list($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $item = self::pruned($item);
                $value[$key] = $item;
            }

            if ($item === null || $item === '' || $item === []) {
                unset($value[$key]);
            }
        }

        return $list ? array_values($value) : $value;
    }

    /**
     * The nodes with every reference to these @ids taken out: a property
     * holding one goes, a list loses it, and an object left empty goes.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<string>  $ids
     *
     * @return list<array<string, mixed>>
     */
    private static function unlinked(array $nodes, array $ids): array
    {
        if ($ids === []) {
            return $nodes;
        }

        return array_map(fn (array $node): array => self::pruned(self::without($node, $ids)), $nodes);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $ids
     *
     * @return array<array-key, mixed>
     */
    private static function without(array $value, array $ids): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = array_keys($item) === ['@id'] && in_array($item['@id'], $ids, true) ? null : self::without($item, $ids);
            }
        }

        return $value;
    }

    /**
     * A node, or an object inside one, with a later contribution merged in
     * (I-697): an empty value (missing, null, '' or []) is filled; @types
     * are joined; two lists are joined without repeats; two objects merge
     * the same way; any other value stays as it came first.
     *
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $then
     *
     * @return array<string, mixed>
     */
    private static function merged(array $first, array $then): array
    {
        foreach ($then as $key => $value) {
            if ($key === '@id') {
                continue;
            }

            if ($key === '@type') {
                $first['@type'] = self::types($first['@type'] ?? null, $value);

                continue;
            }

            $held = $first[$key] ?? null;

            if ($held === null || $held === '' || $held === []) {
                $first[$key] = $value;
            } elseif (is_array($held) && is_array($value) && array_is_list($held) === array_is_list($value)) {
                $first[$key] = array_is_list($held) ? self::joined($held, $value) : self::merged($held, $value);
            }
        }

        return $first;
    }

    /**
     * Two @type values as one: a name when there is one, else the names in
     * the order they came, each once.
     *
     * @return string|list<string>
     */
    private static function types(mixed $first, mixed $then): string|array
    {
        $names = array_values(array_unique([...self::names($first), ...self::names($then)]));

        return count($names) === 1 ? $names[0] : $names;
    }

    /**
     * @return list<string>
     */
    private static function names(mixed $type): array
    {
        return is_string($type) ? [$type] : (is_array($type) ? array_values(array_filter($type, 'is_string')) : []);
    }

    /**
     * @param  list<mixed>  $first
     * @param  list<mixed>  $then
     *
     * @return list<mixed>
     */
    private static function joined(array $first, array $then): array
    {
        foreach ($then as $item) {
            if (! in_array($item, $first, true)) {
                $first[] = $item;
            }
        }

        return $first;
    }
}
