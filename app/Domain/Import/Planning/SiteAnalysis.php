<?php

/**
 * What a WordPress site's report says an import would bring across and
 * leave behind (docs/studies/import.md, §4.0 and §5), and what the choices
 * step needs from it. Read from the Teme Companion's site report, whose
 * counts may arrive as text; it never fails on a report it can't read whole.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Planning;

use Carbon\CarbonImmutable;
use Throwable;

final readonly class SiteAnalysis
{
    /** The statuses an item comes across in: published, scheduled, draft, pending, private and trashed. */
    private const array STATUSES = ['publish', 'future', 'draft', 'pending', 'private', 'trash'];

    /** The comment types that come across; WordPress before 5.5 wrote an empty type. */
    private const array COMMENT_TYPES = ['comment', 'pingback', 'trackback', ''];

    /** The comment statuses that come across (docs/studies/import.md, §4.8). */
    private const array COMMENT_STATUSES = ['0', '1', 'spam', 'trash', 'post-trashed'];

    /** The SEO plugins a report names, by the names people know them by. */
    private const array SEO_PLUGINS = [
        'yoast' => 'Yoast SEO',
        'rank_math' => 'Rank Math',
        'aioseo' => 'All in One SEO',
        'seopress' => 'SEOPress',
        'the_seo_framework' => 'The SEO Framework',
    ];

    /**
     * @param  list<array{key: string, label: string, count: int|null}>  $comes
     * @param  list<array{key: string, wordpressKey: string|null, label: string, count: int, why: string}>  $stays
     * @param  array{custom: int, total: int}|null  $warning
     * @param  list<string>  $menuLocations
     * @param  array<string, int>  $templates
     * @param  array<string, list<string>>  $customRoles
     * @param  array<string, int>  $outsideHosts
     */
    private function __construct(
        public bool $reported,
        private ?string $reportedAt,
        private array $comes,
        private array $stays,
        private ?array $warning,
        private ?string $seo,
        private array $menuLocations,
        private array $templates,
        private array $customRoles,
        private array $outsideHosts,
    ) {}

    /**
     * The analysis of a site whose WordPress hasn't made a report yet.
     */
    public static function noReport(): self
    {
        return new self(false, null, [], [], null, null, [], [], [], []);
    }

    /**
     * The analysis of a site report, as the companion's analyze answers it.
     *
     * @param  array<mixed>  $report
     */
    public static function fromReport(array $report): self
    {
        $sections = self::map($report['sections'] ?? null);
        $contentTypes = self::map($sections['content_types'] ?? null);
        $types = self::map($contentTypes['post_types'] ?? null);
        $taxonomies = self::map($contentTypes['taxonomies'] ?? null);
        $users = self::map($sections['users'] ?? null);
        $menus = self::map($sections['menus'] ?? null);
        $states = self::map($sections['post_states'] ?? null);
        $images = self::map(self::map(self::map($sections['content'] ?? null)['html'] ?? null)['images'] ?? null);

        $post = self::map($types['post'] ?? null);
        $page = self::map($types['page'] ?? null);
        $subscribers = self::count(self::map($users['by_role'] ?? null)['subscriber'] ?? null);
        // Accounts with no role at all, whom the users phase leaves behind too.
        $roleless = self::count(self::map($users['by_role'] ?? null)['none'] ?? null);
        [$comments, $otherComments] = self::comments(self::map(self::map($sections['comments'] ?? null)['by_status_and_type'] ?? null));

        // Every kind the import brings has its row; one the report doesn't count says so with a null count.
        $media = self::map($sections['media'] ?? null);
        $category = self::map($taxonomies['category'] ?? null);
        $tag = self::map($taxonomies['post_tag'] ?? null);
        $comes = [
            ['key' => 'posts', 'label' => 'Posts', 'count' => $post === [] ? null : self::items($post)],
            ['key' => 'pages', 'label' => 'Pages', 'count' => $page === [] ? null : self::items($page)],
            ['key' => 'media', 'label' => 'Media files', 'count' => array_key_exists('total', $media) ? self::count($media['total']) : null],
            ['key' => 'categories', 'label' => 'Categories', 'count' => array_key_exists('terms', $category) ? self::count($category['terms']) : null],
            ['key' => 'tags', 'label' => 'Tags', 'count' => array_key_exists('terms', $tag) ? self::count($tag['terms']) : null],
            ['key' => 'people', 'label' => 'People', 'count' => array_key_exists('total', $users) ? max(0, self::count($users['total']) - $subscribers - $roleless) : null],
            ['key' => 'comments', 'label' => 'Comments', 'count' => is_array(self::map($sections['comments'] ?? null)['by_status_and_type'] ?? null) ? $comments : null],
            ['key' => 'menus', 'label' => 'Menus', 'count' => is_array($menus['menus'] ?? null) ? count($menus['menus']) : null],
        ];

        $languages = self::polylangLanguages($report);

        if ($languages !== null) {
            $comes[] = ['key' => 'languages', 'label' => 'Languages', 'count' => $languages];
        }

        $stays = [];
        $customPublished = 0;

        foreach ($types as $key => $type) {
            $type = self::map($type);

            if (($type['builtin'] ?? true) !== false) {
                continue;
            }

            if (($type['public'] ?? false) === true) {
                $customPublished += self::count(self::map($type['counts'] ?? null)['publish'] ?? null);
            }

            $count = self::items($type);

            if ($count > 0) {
                $stays[] = ['key' => 'type.'.$key, 'wordpressKey' => (string) $key, 'label' => self::label($type, (string) $key), 'count' => $count, 'why' => 'A custom type. Teme can’t import these yet.'];
            }
        }

        foreach ($taxonomies as $key => $taxonomy) {
            $taxonomy = self::map($taxonomy);
            $count = self::count($taxonomy['terms'] ?? null);

            if (($taxonomy['builtin'] ?? true) === false && ($taxonomy['public'] ?? false) === true && $count > 0) {
                $stays[] = ['key' => 'taxonomy.'.$key, 'wordpressKey' => (string) $key, 'label' => self::label($taxonomy, (string) $key), 'count' => $count, 'why' => 'A custom taxonomy. Teme can’t import these yet.'];
            }
        }

        $widgets = 0;

        foreach (self::map(self::map($sections['widgets'] ?? null)['sidebars'] ?? null) as $sidebar => $count) {
            $widgets += $sidebar === 'wp_inactive_widgets' ? 0 : self::count($count);
        }

        foreach ([
            ['subscribers', 'Subscribers', $subscribers, 'Readers can’t have accounts on Teme yet. Their comments come across, and those who have published posts come as contributors.'],
            ['revisions', 'Revisions', self::count($states['revisions'] ?? null), 'Earlier versions stay in WordPress.'],
            ['widgets', 'Widgets', $widgets, 'Set up your theme’s regions again in Content › Regions.'],
            ['other-comments', 'Other comments', $otherComments, 'Likes, mentions and notes plugins kept as comments.'],
        ] as [$key, $label, $count, $why]) {
            if ($count > 0) {
                $stays[] = ['key' => $key, 'wordpressKey' => null, 'label' => $label, 'count' => $count, 'why' => $why];
            }
        }

        $builtinPublished = self::count(self::map($post['counts'] ?? null)['publish'] ?? null) + self::count(self::map($page['counts'] ?? null)['publish'] ?? null);

        return new self(
            true,
            self::moment($report['generated_at'] ?? null),
            $comes,
            $stays,
            $customPublished > $builtinPublished ? ['custom' => $customPublished, 'total' => $customPublished + $builtinPublished] : null,
            self::seo(self::map(self::map($sections['seo'] ?? null)['plugins'] ?? null)),
            self::locations(self::map($menus['assigned_locations'] ?? null)),
            self::counted(self::map($states['page_templates'] ?? null), ['default']),
            self::roles(self::map($users['custom_roles'] ?? null)),
            self::hosts(self::map($images['external_hosts'] ?? null)),
        );
    }

    /**
     * Returns how many languages the report says Polylang has, or null when
     * it says Polylang isn't on, or says nothing of it.
     *
     * @param  array<mixed>  $report
     *
     * @since next
     */
    public static function polylangLanguages(array $report): ?int
    {
        $polylang = self::map(self::map(self::map($report['sections'] ?? null)['multilingual'] ?? null)['polylang'] ?? null);

        return $polylang === [] ? null : self::count($polylang['languages'] ?? null);
    }

    /**
     * The analysis as toArray() stored it.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        // Only toArray() writes what is kept, so its shape holds.
        /** @var array{reported: bool, reportedAt: string|null, comes: list<array{key: string, label: string, count: int|null}>, stays: list<array{key: string, wordpressKey: string|null, label: string, count: int, why: string}>, warning: array{custom: int, total: int}|null, seo: string|null, menuLocations: list<string>, templates: array<string, int>, customRoles: array<string, list<string>>, outsideHosts: array<string, int>} $kept */
        $kept = $stored;

        return new self(
            $kept['reported'], $kept['reportedAt'], $kept['comes'], $kept['stays'], $kept['warning'], $kept['seo'],
            $kept['menuLocations'], $kept['templates'], $kept['customRoles'], $kept['outsideHosts'],
        );
    }

    /**
     * The analysis with more of what doesn't come across, after its own.
     *
     * @param  list<array{key: string, wordpressKey: string|null, label: string, count: int, why: string}>  $stays
     *
     * @since 1.0.0
     */
    public function withStays(array $stays): self
    {
        return new self(
            $this->reported, $this->reportedAt, $this->comes, [...$this->stays, ...$stays], $this->warning, $this->seo,
            $this->menuLocations, $this->templates, $this->customRoles, $this->outsideHosts,
        );
    }

    /**
     * Returns the analysis as the page draws it, with what the choices need.
     *
     * @return array{reported: bool, reportedAt: string|null, comes: list<array{key: string, label: string, count: int|null}>, stays: list<array{key: string, wordpressKey: string|null, label: string, count: int, why: string}>, warning: array{custom: int, total: int}|null, seo: string|null, menuLocations: list<string>, templates: array<string, int>, customRoles: array<string, list<string>>, outsideHosts: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'reported' => $this->reported,
            'reportedAt' => $this->reportedAt,
            'comes' => $this->comes,
            'stays' => $this->stays,
            'warning' => $this->warning,
            'seo' => $this->seo,
            'menuLocations' => $this->menuLocations,
            'templates' => $this->templates,
            'customRoles' => $this->customRoles,
            'outsideHosts' => $this->outsideHosts,
        ];
    }

    /**
     * Returns how many languages the site report says Polylang has, or null
     * when it doesn't say Polylang is on.
     *
     * @since next
     */
    public function polylangCount(): ?int
    {
        foreach ($this->comes as $row) {
            if ($row['key'] === 'languages') {
                return $row['count'];
            }
        }

        return null;
    }

    /**
     * Returns the WordPress menu locations that hold a menu.
     *
     * @return list<string>
     */
    public function menuLocations(): array
    {
        return $this->menuLocations;
    }

    /**
     * Returns the page templates WordPress's pages use, other than its default, with how many pages use each.
     *
     * @return array<string, int>
     */
    public function templates(): array
    {
        return $this->templates;
    }

    /**
     * Returns the custom roles, each with what it may do.
     *
     * @return array<string, list<string>>
     */
    public function customRoles(): array
    {
        return $this->customRoles;
    }

    /**
     * Returns the other sites the posts' images live on, with how many images each, most first.
     *
     * @return array<string, int>
     */
    public function outsideHosts(): array
    {
        return $this->outsideHosts;
    }

    /**
     * An item type's count in the statuses that come across.
     *
     * @param  array<mixed>  $type
     */
    private static function items(array $type): int
    {
        $counts = self::map($type['counts'] ?? null);

        return array_sum(array_map(fn (string $status): int => self::count($counts[$status] ?? null), self::STATUSES));
    }

    /**
     * The comments that come across, and the rest.
     *
     * @param  array<mixed>  $rows
     *
     * @return array{0: int, 1: int}
     */
    private static function comments(array $rows): array
    {
        $kept = 0;
        $other = 0;

        foreach ($rows as $key => $count) {
            [$status, $type] = array_pad(explode('|', (string) $key, 2), 2, '');

            if (in_array($status, self::COMMENT_STATUSES, true) && in_array($type, self::COMMENT_TYPES, true)) {
                $kept += self::count($count);
            } else {
                $other += self::count($count);
            }
        }

        return [$kept, $other];
    }

    /**
     * The active SEO plugin's name, or null.
     *
     * @param  array<mixed>  $plugins
     */
    private static function seo(array $plugins): ?string
    {
        foreach (self::SEO_PLUGINS as $key => $name) {
            if (($plugins[$key] ?? null) !== null && $plugins[$key] !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $assigned
     *
     * @return list<string>
     */
    private static function locations(array $assigned): array
    {
        return array_map(strval(...), array_keys(array_filter($assigned, fn (mixed $menu): bool => self::count($menu) > 0)));
    }

    /**
     * @param  array<mixed>  $rows
     *
     * @return array<string, list<string>>
     */
    private static function roles(array $rows): array
    {
        $roles = [];

        foreach ($rows as $role => $capabilities) {
            $roles[(string) $role] = array_values(array_map(strval(...), array_filter(self::map($capabilities), is_string(...))));
        }

        return $roles;
    }

    /**
     * Hosts, lower case, with their counts, most first, then by name.
     *
     * @param  array<mixed>  $rows
     *
     * @return array<string, int>
     */
    private static function hosts(array $rows): array
    {
        $hosts = [];

        foreach ($rows as $host => $count) {
            if (self::count($count) > 0) {
                $hosts[strtolower((string) $host)] = self::count($count);
            }
        }

        uksort($hosts, fn (string $a, string $b): int => [$hosts[$b], $a] <=> [$hosts[$a], $b]);

        return $hosts;
    }

    /**
     * Counts above 0 by key, less the keys left out.
     *
     * @param  array<mixed>  $rows
     * @param  list<string>  $without
     *
     * @return array<string, int>
     */
    private static function counted(array $rows, array $without): array
    {
        $counted = [];

        foreach ($rows as $key => $count) {
            if (! in_array((string) $key, $without, true) && self::count($count) > 0) {
                $counted[(string) $key] = self::count($count);
            }
        }

        return $counted;
    }

    /**
     * @param  array<mixed>  $row
     */
    private static function label(array $row, string $key): string
    {
        return is_string($row['label'] ?? null) && $row['label'] !== '' ? $row['label'] : $key;
    }

    private static function moment(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private static function count(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * @return array<mixed>
     */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
