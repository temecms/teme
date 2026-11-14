<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Settings;

/**
 * The addresses of the sitemaps the old site served (I-1717): its SEO
 * plugin's, each type's and taxonomy's first page and every later one the
 * import's counts need, or WordPress's own. Pure.
 */
final class SitemapAddresses
{
    /** WordPress's name for authors in sitemaps' addresses. */
    public const string AUTHORS = 'author';

    /** How many entries a sitemap holds, by the SEO plugin the companion names. */
    private const array PER_SITEMAP = ['yoast' => 1000, 'rank_math' => 200];

    /** How many entries one of WordPress's own sitemaps holds. */
    private const int CORE = 2000;

    /**
     * Each sitemap's address, in the order of $counts.
     *
     * @param  array<string, int>  $counts  WordPress's name of a type or a taxonomy, or AUTHORS, => how many came
     * @param  list<string>  $taxonomies  which of those names are taxonomies
     *
     * @return list<string>
     */
    public static function for(?string $plugin, array $counts, array $taxonomies): array
    {
        $size = self::PER_SITEMAP[$plugin ?? ''] ?? null;
        $paths = [];

        foreach ($counts as $name => $count) {
            $name = (string) $name;
            $pages = max(1, (int) ceil($count / ($size ?? self::CORE)));

            for ($n = 1; $n <= $pages; $n++) {
                if ($size !== null) {
                    $paths[] = '/'.$name.'-sitemap'.($n === 1 ? '' : (string) $n).'.xml';
                } elseif ($name === self::AUTHORS) {
                    $paths[] = "/wp-sitemap-users-{$n}.xml";
                } else {
                    $paths[] = '/wp-sitemap-'.(in_array($name, $taxonomies, true) ? 'taxonomies' : 'posts')."-{$name}-{$n}.xml";
                }
            }
        }

        return $paths;
    }
}
