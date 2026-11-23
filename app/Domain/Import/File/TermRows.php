<?php

/**
 * An export file's terms in the companion's `terms` shape, from the
 * channel's categories, tags and terms. A file has no term taxonomy ID, so a
 * term's ID is its term ID; its public address and count come later.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File;

final class TermRows
{
    /** Taxonomies the companion leaves out of `terms`: menus, themes, patterns, links and Polylang's own. */
    /** WordPress's two built-in taxonomies, which an export lists in elements of their own. */
    private const string CATEGORIES = 'category';

    private const string TAGS = 'post_tag';

    /** The channel's element for a tag. */
    private const string TAG_ELEMENT = 'tag';

    public const array INTERNAL = ['nav_menu', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category', 'link_category', 'language', 'term_language', 'post_translations', 'term_translations'];

    /**
     * Returns the rows of the channel's terms, with parents found by slug,
     * and fills the lookups with them. A term with no term ID (WXR 1.0) gets
     * one below zero, in the channel's order.
     *
     * @param  list<array{element: string, fields: array<string, string>, meta: list<array{key: string, value: string}>}>  $terms
     * @param  array<string, int>  $known  by key(), filled with each term
     *
     * @return list<array{list: string, id: int, payload: array<string, mixed>}>
     */
    public static function fromChannel(array $terms, array &$known, int &$lowest): array
    {
        $read = [];

        foreach ($terms as $term) {
            $fields = $term['fields'];
            [$taxonomy, $slug, $name, $parent, $description] = match ($term['element']) {
                self::CATEGORIES => [self::CATEGORIES, $fields['category_nicename'] ?? '', $fields['cat_name'] ?? '', $fields['category_parent'] ?? '', $fields['category_description'] ?? ''],
                self::TAG_ELEMENT => [self::TAGS, $fields['tag_slug'] ?? '', $fields['tag_name'] ?? '', '', $fields['tag_description'] ?? ''],
                default => [$fields['term_taxonomy'] ?? '', $fields['term_slug'] ?? '', $fields['term_name'] ?? '', $fields['term_parent'] ?? '', $fields['term_description'] ?? ''],
            };
            $slug = trim($slug);

            if ($taxonomy === '' || $slug === '' || array_key_exists(self::key($taxonomy, $slug), $known)) {
                continue;
            }

            $termId = (int) trim($fields['term_id'] ?? '');
            $id = $termId > 0 ? $termId : --$lowest;
            $known[self::key($taxonomy, $slug)] = $id;
            $read[] = [$id, $taxonomy, $name, $slug, $description, trim($parent), self::seo($term['meta'])];
        }

        $rows = [];

        foreach ($read as [$id, $taxonomy, $name, $slug, $description, $parent, $seo]) {
            $parentId = $parent === '' ? 0 : ($known[self::key($taxonomy, $parent)] ?? 0);
            $rows[] = ['list' => self::listOf($taxonomy), 'id' => $id, 'payload' => self::payload($id, $taxonomy, $name, $slug, $description, $parentId, $seo)];
        }

        return $rows;
    }

    /**
     * Returns a term's payload.
     *
     * @param  array<string, mixed>|null  $seo
     *
     * @return array<string, mixed>
     */
    public static function payload(int $id, string $taxonomy, string $name, string $slug, string $description, int $parent, ?array $seo): array
    {
        return [
            'id' => $id,
            'term_id' => $id,
            'taxonomy' => $taxonomy,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'parent' => $parent,
            'count' => 0,
            'link' => null,
            'seo' => $seo,
        ];
    }

    /**
     * Returns the list a term of this taxonomy is kept in: `terms`, or the file's own list for the taxonomies the companion leaves out.
     */
    public static function listOf(string $taxonomy): string
    {
        return in_array($taxonomy, self::INTERNAL, true) ? ItemRows::OTHER_TERMS : ItemRows::TERMS;
    }

    /**
     * Returns the lookup key of a term.
     */
    public static function key(string $taxonomy, string $slug): string
    {
        return $taxonomy."\0".$slug;
    }

    /**
     * Rank Math's fields from a term's meta, as the companion reads them, or
     * null when it has none. Yoast keeps a term's fields in an option, which
     * an export doesn't hold.
     *
     * @param  list<array{key: string, value: string}>  $meta
     *
     * @return array<string, mixed>|null
     */
    private static function seo(array $meta): ?array
    {
        $values = [];

        foreach ($meta as $pair) {
            $values[$pair['key']] = $pair['value'];
        }

        if (array_filter(array_keys($values), fn (string $key): bool => str_starts_with($key, 'rank_math_')) === []) {
            return null;
        }

        $text = fn (string $key): ?string => trim($values[$key] ?? '') !== '' ? trim($values[$key]) : null;
        $robots = SerializedMeta::read('rank_math_robots', $values['rank_math_robots'] ?? '')['value'];

        return [
            'title' => $text('rank_math_title'),
            'description' => $text('rank_math_description'),
            'canonical' => $text('rank_math_canonical_url'),
            'noindex' => is_array($robots) ? in_array('noindex', $robots, true) : null,
        ];
    }
}
