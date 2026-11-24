<?php

/**
 * The site report of an export file, counted from its rows once they are all
 * read, in the keys of the companion's `analyze` report that
 * SiteAnalysis::fromReport() reads. What a file can't say, such as roles,
 * widgets or where menus sit, is left out rather than guessed.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File;

use App\Domain\Import\WordPressRecords;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FileReport
{
    /** The types and taxonomies WordPress itself defines, as the companion marks them built in. */
    private const array BUILTIN_TYPES = ['post', 'page', 'attachment', 'wp_block', 'wp_navigation', 'nav_menu_item', ...WordPressRecords::TYPES];

    /** The types WordPress shows the public by its own rules; a file says nothing of any other's. */
    private const array PUBLIC_TYPES = ['post', 'page', 'attachment'];

    private const array BUILTIN_TAXONOMIES = ['category', 'post_tag', 'post_format', 'nav_menu', 'link_category', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category'];

    /**
     * Returns the report of the file's rows.
     *
     * @return array<string, mixed>
     */
    public static function build(string $fileId, ?string $siteAddress, ?CarbonImmutable $madeAt): array
    {
        $rows = fn (string $list) => DB::table('import_file_rows')->where('file_id', $fileId)->where('list', $list);
        $facts = json_decode((string) ($rows(ItemRows::FACTS)->where('object_id', 0)->value('payload') ?? '{}'), true);
        $facts = is_array($facts) ? $facts : [];

        $types = [];

        foreach ($rows(ItemRows::CONTENT)->selectRaw("payload->>'type' as type, payload->>'status' as status, count(*) as total")->groupByRaw('1, 2')->get() as $row) {
            $type = (string) $row->type;
            $types[$type] ??= ['builtin' => in_array($type, self::BUILTIN_TYPES, true), 'public' => in_array($type, self::PUBLIC_TYPES, true) ? true : null, 'counts' => []];
            $types[$type]['counts'][(string) $row->status] = (int) $row->total;
        }

        $media = $rows(ItemRows::MEDIA)->count();
        $types['attachment'] = ['builtin' => true, 'public' => true, 'counts' => ['inherit' => $media]];

        $taxonomies = [];

        foreach ([ItemRows::TERMS, ItemRows::OTHER_TERMS] as $list) {
            foreach ($rows($list)->selectRaw("payload->>'taxonomy' as taxonomy, count(*) as total")->groupByRaw('1')->get() as $row) {
                $taxonomy = (string) $row->taxonomy;
                $taxonomies[$taxonomy] = [
                    'builtin' => in_array($taxonomy, self::BUILTIN_TAXONOMIES, true),
                    // A file doesn't say whether a taxonomy is public; any but WordPress's own hidden ones is taken to be.
                    'public' => ! in_array($taxonomy, TermRows::INTERNAL, true),
                    'terms' => (int) $row->total,
                ];
            }
        }

        $comments = [];

        foreach ($rows(ItemRows::COMMENTS)->selectRaw("payload->>'status' || '|' || (payload->>'type') as kind, count(*) as total")->groupByRaw('1')->get() as $row) {
            $comments[(string) $row->kind] = (int) $row->total;
        }

        $menus = $rows(ItemRows::MENUS)->orderBy('object_id')->selectRaw("object_id, jsonb_array_length(payload->'items') as items")->get()
            ->map(fn (object $row): array => ['term_id' => (int) $row->object_id, 'items' => (int) $row->items])->all();

        $templates = [];

        foreach ($rows(ItemRows::CONTENT)->whereRaw("payload->>'type' = 'page'")->selectRaw("coalesce(payload->>'template', 'default') as template, count(*) as total")->groupByRaw('1')->get() as $row) {
            $templates[(string) $row->template] = (int) $row->total;
        }

        $seo = is_array($facts['seo'] ?? null) ? $facts['seo'] : [];
        $images = is_array($facts['images'] ?? null) ? $facts['images'] : [];
        $sections = [
            'environment' => ['home' => $siteAddress, 'siteurl' => $siteAddress],
            'content_types' => ['post_types' => $types, 'taxonomies' => $taxonomies],
            'users' => ['total' => $rows(ItemRows::USERS)->count(), 'by_role' => [], 'custom_roles' => []],
            'comments' => ['total' => array_sum($comments), 'by_status_and_type' => $comments],
            'media' => ['total' => $media],
            'menus' => [
                'menus' => $menus,
                'registered_locations' => [],
                'assigned_locations' => [],
                'navigation_posts' => $rows(ItemRows::NAVIGATION)->whereRaw("payload->>'status' = 'publish'")->count(),
            ],
            'post_states' => ['page_templates' => $templates, 'revisions' => 0],
            // A file can't tell which plugin wrote its SEO meta's version, only that the meta is there.
            'seo' => ['plugins' => ['yoast' => isset($seo['yoast']) ? true : null, 'rank_math' => isset($seo['rank_math']) ? true : null]],
            'content' => ['html' => ['images' => [
                'total' => (int) ($images['total'] ?? 0),
                'own_uploads' => (int) ($images['own'] ?? 0),
                'external_hosts' => is_array($images['hosts'] ?? null) ? $images['hosts'] : [],
            ]]],
        ];

        $languages = $rows(ItemRows::OTHER_TERMS)->whereRaw("payload->>'taxonomy' = 'language'")->count();

        if ($languages > 0) {
            $sections['multilingual'] = ['polylang' => ['languages' => $languages]];
        }

        return [
            'report_version' => 1,
            'plugin_version' => null,
            'include_examples' => false,
            'started_at' => $madeAt?->toIso8601String(),
            'generated_at' => $madeAt?->toIso8601String(),
            'file_plan' => self::plan($fileId),
            'sections' => $sections,
            'errors' => (int) ($facts['dropped_count'] ?? 0) > 0
                ? ['serialized' => ['dropped' => (int) $facts['dropped_count'], 'items' => is_array($facts['dropped'] ?? null) ? $facts['dropped'] : []]]
                : [],
        ];
    }

    /**
     * Returns the address guesses the plan page needs, worked out once while the file is read.
     *
     * @since 1.4.1
     *
     * @return array{category_base: string, tag_base: string, category_example: string, tag_example: string}
     */
    public static function plan(string $fileId): array
    {
        $bases = FileAddresses::bases($fileId);
        $examples = FileAddresses::examples($fileId);

        return [
            'category_base' => $bases['category_base'],
            'tag_base' => $bases['tag_base'],
            'category_example' => $examples['categoryExample'],
            'tag_example' => $examples['tagExample'],
        ];
    }
}
