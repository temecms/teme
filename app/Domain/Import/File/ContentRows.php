<?php

/**
 * An export file's posts, pages and other items in the companion's
 * `content` shape: their fields, terms, block tree, old slugs, and Yoast's or
 * Rank Math's fields from their meta. What a file has no field for stays
 * empty, and template variables are kept as written, since nothing renders them.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File;

use Carbon\CarbonImmutable;

final class ContentRows
{
    /** The taxonomies the companion leaves out of an item's terms. */
    /** The one type WordPress makes sticky. */
    private const string POSTS = 'post';

    private const array UNLISTED = ['post_format', 'language', 'post_translations'];

    /**
     * Returns the payload of one item.
     *
     * @param  array{fields: array<string, string>, taxonomies: list<array{domain: string, nicename: string, name: string}>, meta: list<array{key: string, value: string}>, comments: list<array{fields: array<string, string>, meta: list<array{key: string, value: string}>}>}  $item
     * @param  array<string, list<string>>  $meta  by key, every value
     * @param  array<string, array<mixed>|null>  $serialized  the read values, by key
     *
     * @return array<string, mixed>
     */
    public static function payload(array $item, array $meta, array $serialized, FileLookups $lookups, ContentBlocks $blocks): array
    {
        $f = $item['fields'];
        $type = trim($f['wp:post_type'] ?? 'post');
        $content = $f['content:encoded'] ?? '';
        $terms = [];
        $format = null;

        foreach ($item['taxonomies'] as $category) {
            if ($category['domain'] === 'post_format') {
                $format = str_starts_with($category['nicename'], 'post-format-') ? substr($category['nicename'], strlen('post-format-')) : null;
            }

            if ($category['domain'] === '' || $category['nicename'] === '' || in_array($category['domain'], self::UNLISTED, true)) {
                continue;
            }

            $id = $lookups->term($category['domain'], $category['nicename'], $category['name']);

            if (! in_array($id, $terms[$category['domain']] ?? [], true)) {
                $terms[$category['domain']][] = $id;
            }
        }

        $template = self::first($meta, '_wp_page_template');
        $thumbnail = self::first($meta, '_thumbnail_id');
        $trashedAt = self::first($meta, '_wp_trash_meta_time');

        return [
            'id' => (int) ($f['wp:post_id'] ?? 0),
            'type' => $type,
            'status' => trim($f['wp:status'] ?? ''),
            'slug' => trim($f['wp:post_name'] ?? ''),
            'title' => $f['title'] ?? '',
            'date' => self::date($f['wp:post_date'] ?? null),
            'date_gmt' => self::date($f['wp:post_date_gmt'] ?? null),
            'modified_gmt' => self::date($f['wp:post_modified_gmt'] ?? null),
            'author' => $lookups->author(trim($f['dc:creator'] ?? '')),
            'parent' => (int) ($f['wp:post_parent'] ?? 0),
            'menu_order' => (int) ($f['wp:menu_order'] ?? 0),
            'comment_status' => trim($f['wp:comment_status'] ?? ''),
            'ping_status' => trim($f['wp:ping_status'] ?? ''),
            'password' => $f['wp:post_password'] ?? '',
            'sticky' => $type === self::POSTS && trim($f['wp:is_sticky'] ?? '') === '1',
            'format' => $format,
            'template' => $template === null || $template === '' || $template === 'default' ? null : $template,
            'featured_media' => $thumbnail !== null && ctype_digit(trim($thumbnail)) && (int) $thumbnail > 0 ? (int) $thumbnail : null,
            'terms' => $terms,
            'excerpt' => $f['excerpt:encoded'] ?? '',
            'content' => $content,
            'has_blocks' => str_contains($content, '<!-- wp:'),
            'blocks' => $blocks->tree($content),
            'permalink' => trim($f['link'] ?? ''),
            'old_slugs' => array_values(array_filter($meta['_wp_old_slug'] ?? [], fn (string $slug): bool => $slug !== '')),
            'old_dates' => array_values(array_filter($meta['_wp_old_date'] ?? [], fn (string $date): bool => $date !== '')),
            'seo' => self::seo($meta, $serialized),
            'comment_count' => count(array_filter(CommentRows::rows(0, $item['comments']), fn (array $row): bool => $row['payload']['status'] === '1')),
            'status_before_trash' => self::first($meta, '_wp_trash_meta_status'),
            'trashed_at' => $trashedAt !== null && ctype_digit(trim($trashedAt)) ? CarbonImmutable::createFromTimestampUTC((int) $trashedAt)->format('Y-m-d H:i:s') : null,
        ];
    }

    /**
     * Returns which SEO plugin's fields the meta holds: `yoast`, `rank_math`, or null.
     *
     * @param  array<string, list<string>>  $meta
     * @param  array<string, array<mixed>|null>  $serialized
     */
    public static function seoPlugin(array $meta, array $serialized = []): ?string
    {
        foreach (array_keys($meta) as $key) {
            if (str_starts_with((string) $key, '_yoast_wpseo_')) {
                return 'yoast';
            }
        }

        foreach ([...array_keys($meta), ...array_keys($serialized)] as $key) {
            if (str_starts_with((string) $key, 'rank_math_')) {
                return 'rank_math';
            }
        }

        return null;
    }

    /**
     * WordPress's date, or null for its empty date.
     */
    public static function date(?string $date): ?string
    {
        $date = trim((string) $date);

        return $date === '' || str_starts_with($date, '0000-00-00') ? null : $date;
    }

    /**
     * @param  array<string, list<string>>  $meta
     */
    public static function first(array $meta, string $key): ?string
    {
        return $meta[$key][0] ?? null;
    }

    /**
     * The neutral SEO fields, as the companion gives them, or null when the item has neither plugin's.
     *
     * @param  array<string, list<string>>  $meta
     * @param  array<string, array<mixed>|null>  $serialized
     *
     * @return array<string, mixed>|null
     */
    private static function seo(array $meta, array $serialized): ?array
    {
        $plugin = self::seoPlugin($meta, $serialized);

        if ($plugin === null) {
            return null;
        }

        $value = fn (string $key): string => trim($meta[$key][0] ?? '');
        $either = fn (string $first, string $second): string => $value($first) !== '' ? $value($first) : $value($second);
        $fields = [
            'title' => null, 'description' => null, 'canonical' => null, 'noindex' => null, 'nofollow' => false,
            'social_title' => null, 'social_description' => null, 'social_image_id' => null, 'social_image_url' => null,
            'primary_terms' => [], 'had_variables' => [],
        ];

        if ($plugin === 'yoast') {
            $variable = '/%%[a-z_]+%%/i';
            $primary = '_yoast_wpseo_primary_';
            $fields['noindex'] = ['1' => true, '2' => false][$value('_yoast_wpseo_meta-robots-noindex')] ?? null;
            $fields['nofollow'] = $value('_yoast_wpseo_meta-robots-nofollow') === '1';
            $fields['canonical'] = $value('_yoast_wpseo_canonical') !== '' ? $value('_yoast_wpseo_canonical') : null;
            $texts = [
                'title' => $value('_yoast_wpseo_title'),
                'description' => $value('_yoast_wpseo_metadesc'),
                'social_title' => $either('_yoast_wpseo_opengraph-title', '_yoast_wpseo_twitter-title'),
                'social_description' => $either('_yoast_wpseo_opengraph-description', '_yoast_wpseo_twitter-description'),
            ];
            $imageId = $either('_yoast_wpseo_opengraph-image-id', '_yoast_wpseo_twitter-image-id');
            $imageUrl = $either('_yoast_wpseo_opengraph-image', '_yoast_wpseo_twitter-image');
        } else {
            $variable = '/%[a-z_]+(\([^)]*\))?%/i';
            $primary = 'rank_math_primary_';
            $robots = $serialized['rank_math_robots'] ?? null;

            if (is_array($robots)) {
                $fields['noindex'] = in_array('noindex', $robots, true) ? true : (in_array('index', $robots, true) ? false : null);
                $fields['nofollow'] = in_array('nofollow', $robots, true);
            }

            $fields['canonical'] = $value('rank_math_canonical_url') !== '' ? $value('rank_math_canonical_url') : null;
            $texts = [
                'title' => $value('rank_math_title'),
                'description' => $value('rank_math_description'),
                'social_title' => $either('rank_math_facebook_title', 'rank_math_twitter_title'),
                'social_description' => $either('rank_math_facebook_description', 'rank_math_twitter_description'),
            ];
            $imageId = $either('rank_math_facebook_image_id', 'rank_math_twitter_image_id');
            $imageUrl = $either('rank_math_facebook_image', 'rank_math_twitter_image');
        }

        foreach ($texts as $field => $text) {
            if ($text === '') {
                continue;
            }

            if (preg_match($variable, $text) === 1) {
                $fields['had_variables'][] = $field;
            }

            $fields[$field] = $text;
        }

        $fields['social_image_id'] = ctype_digit($imageId) ? (int) $imageId : null;
        $fields['social_image_url'] = $imageUrl !== '' ? $imageUrl : null;

        foreach ($meta as $key => $values) {
            if (str_starts_with((string) $key, $primary) && ctype_digit(trim($values[0] ?? ''))) {
                $fields['primary_terms'][substr((string) $key, strlen($primary))] = (int) $values[0];
            }
        }

        return $fields;
    }
}
